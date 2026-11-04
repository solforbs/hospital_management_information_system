<?php

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\StaffAssignment;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use App\Support\FacilityLevel;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /** Billing category → dashboard service group (Primary 1 / 2 / 3). */
    public const GROUPS = [
        'services' => ['label' => 'Clinical services', 'categories' => ['consultation', 'registration', 'procedure', 'emergency', 'nursing', 'theatre', 'other']],
        'diagnostics' => ['label' => 'Diagnostics', 'categories' => ['laboratory', 'radiology']],
        'pharmacy' => ['label' => 'Pharmacy & beds', 'categories' => ['pharmacy', 'bed']],
    ];

    #[Url]
    public int $year = 0;

    #[Url]
    public string $metric = 'visits';

    #[Url]
    public string $breakdown = 'services';

    public function mount(): void
    {
        $this->year = $this->year ?: (int) now()->year;

        if (! $this->canFinance()) {
            $this->metric = 'visits';
        }
    }

    public function canFinance(): bool
    {
        return Auth::user()->canAny(['reports.view', 'billing.payments.receive', 'billing.invoices.view']);
    }

    public function canClinicalStats(): bool
    {
        return Auth::user()->canAny(['reception.checkin', 'encounters.view', 'reports.view']);
    }

    #[Computed]
    public function tenant()
    {
        return app(TenantContext::class)->tenant();
    }

    #[Computed]
    public function facility()
    {
        return app(TenantContext::class)->facility();
    }

    #[Computed]
    public function memberships()
    {
        return Auth::user()->tenants()->wherePivot('status', 'active')->get();
    }

    #[Computed]
    public function facilities()
    {
        if (! $this->tenant) {
            return collect();
        }

        $all = Facility::where('active', true)->orderBy('name')->get();
        $assigned = StaffAssignment::where('user_id', Auth::id())->pluck('facility_id');

        return $assigned->isEmpty() ? $all : $all->whereIn('id', $assigned)->values();
    }

    #[Computed]
    public function roles()
    {
        return Auth::user()->getRoleNames();
    }


    #[Computed]
    public function modules()
    {
        return ModuleRegistry::forUser(Auth::user());
    }

    /**
     * Top KPI cards: [label, value, icon, tint, route]. Tints follow the
     * reference palette (blue, purple, red, cyan, orange).
     */
    #[Computed]
    public function kpis(): array
    {
        if (! $this->tenant) {
            return [];
        }

        $user = Auth::user();
        $money = fn ($v) => config('hms.currency').' '.number_format((float) $v, 0);
        $cards = [];

        if ($this->canFinance()) {
            $cards[] = [__('Collected (month)'), $money(Payment::where('received_at', '>=', now()->startOfMonth())->sum('amount')), 'banknotes', 'blue', $user->canAny(['billing.invoices.view', 'billing.payments.receive']) ? 'billing.index' : null];
            $cards[] = [__('Billed (month)'), $money(Invoice::where('status', '!=', 'void')->where('created_at', '>=', now()->startOfMonth())->sum('total')), 'document-text', 'purple', $user->can('reports.view') ? 'reports.index' : null];
        }

        if ($this->canClinicalStats()) {
            $cards[] = [__('Visits today'), number_format(Encounter::whereDate('started_at', today())->count()), 'clipboard-document-list', 'red', $user->can('reception.checkin') ? 'reception.index' : null];
            $cards[] = [__('Patients'), number_format(Patient::count()), 'users', 'cyan', $user->can('patients.view') ? 'patients.index' : null];
        }

        if ($user->canAny(['wards.view', 'nursing.tasks.manage', 'reports.view'])) {
            $beds = Bed::count();
            $occupied = Bed::where('status', 'occupied')->count();
            $cards[] = [__('Bed occupancy'), ($beds ? round($occupied / $beds * 100) : 0).'%', 'home-modern', 'orange', $user->canAny(['wards.view', 'nursing.tasks.manage']) ? 'wards.board' : null];
        }

        if ($user->can('lab.orders.view') && count($cards) < 5) {
            $cards[] = [__('Lab tests pending'), LabOrderItem::whereIn('status', ['ordered', 'collected', 'resulted', 'verified'])->count(), 'beaker', 'purple', $user->can('lab.orders.process') ? 'lab.worklist' : null];
        }

        if ($user->can('pharmacy.prescriptions.view') && count($cards) < 5) {
            $cards[] = [__('Prescriptions to dispense'), Prescription::whereIn('status', ['pending', 'partially_dispensed'])->count(), 'shopping-bag', 'blue', $user->can('pharmacy.dispense') ? 'pharmacy.queue' : null];
        }

        return array_slice($cards, 0, 5);
    }

    /** Live patient-flow counters (queues), shown as small chips. */
    #[Computed]
    public function flow(): array
    {
        if (! $this->tenant || ! Auth::user()->canAny(['reception.checkin', 'encounters.view', 'reports.view', 'triage.record'])) {
            return [];
        }

        $open = Encounter::where('status', 'open')->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');

        return collect([EncounterStage::WAITING_TRIAGE, EncounterStage::WAITING_DOCTOR, EncounterStage::IN_CONSULTATION, EncounterStage::AWAITING_RESULTS, EncounterStage::WAITING_PHARMACY, EncounterStage::WAITING_BILLING, EncounterStage::ADMITTED])
            ->map(fn ($s) => ['label' => EncounterStage::label($s), 'count' => (int) ($open[$s] ?? 0), 'color' => EncounterStage::color($s)])
            ->all();
    }

    public function years(): array
    {
        return range((int) now()->year - 3, (int) now()->year);
    }

    protected function monthExpr(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "cast(strftime('%m', {$column}) as integer)" : "month({$column})";
    }

    /** Monthly series for the line chart (visits, or revenue collected). */
    #[Computed]
    public function series(): array
    {
        if (! $this->tenant) {
            return [];
        }

        $start = now()->setDate($this->year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();

        $rows = $this->metric === 'revenue' && $this->canFinance()
            ? Payment::whereBetween('received_at', [$start, $end])->selectRaw($this->monthExpr('received_at').' as m, sum(amount) as v')->groupBy('m')->pluck('v', 'm')
            : Encounter::whereBetween('started_at', [$start, $end])->selectRaw($this->monthExpr('started_at').' as m, count(*) as v')->groupBy('m')->pluck('v', 'm');

        $lastMonth = $this->year === (int) now()->year ? (int) now()->month : 12;

        return collect(range(1, 12))->map(fn ($m) => ['m' => $m, 'label' => now()->setDate($this->year, $m, 1)->format('M'), 'v' => $m <= $lastMonth ? (float) ($rows[$m] ?? 0) : null])->all();
    }

    /** Round an axis maximum up to a "nice" value. */
    public static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 4;
        }

        $mag = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($max <= $step * $mag) {
                return $step * $mag;
            }
        }

        return 10 * $mag;
    }

    public static function short(float $v): string
    {
        return match (true) {
            $v >= 1_000_000 => round($v / 1_000_000, 1).'M',
            $v >= 1_000 => round($v / 1_000, 1).'K',
            default => (string) round($v),
        };
    }

    /** Billed amount this year by payer (rows) × service group (stacked segments). */
    #[Computed]
    public function payerSummary(): array
    {
        if (! $this->tenant || ! $this->canFinance()) {
            return [];
        }

        $rows = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', 'void')
            ->where('invoice_items.created_at', '>=', now()->setDate($this->year, 1, 1)->startOfDay())
            ->where('invoice_items.created_at', '<=', now()->setDate($this->year, 12, 31)->endOfDay())
            ->selectRaw('invoices.payer_type as payer, invoice_items.category as category, sum(invoice_items.total) as v')
            ->groupBy('invoices.payer_type', 'invoice_items.category')
            ->get();

        $groupOf = fn ($cat) => collect(self::GROUPS)->search(fn ($g) => in_array($cat, $g['categories'], true)) ?: 'services';

        return collect(['cash' => __('Cash'), 'insurance' => __('Insurance / SHA'), 'corporate' => __('Corporate'), 'waiver' => __('Waiver')])
            ->map(function ($label, $payer) use ($rows, $groupOf) {
                $mine = $rows->where('payer', $payer);
                $parts = collect(array_keys(self::GROUPS))->mapWithKeys(fn ($g) => [$g => (float) $mine->filter(fn ($r) => $groupOf($r->category) === $g)->sum('v')]);

                return ['label' => $label, 'parts' => $parts->all(), 'total' => $parts->sum()];
            })->values()->all();
    }

    /** Top billed items this year within the selected service group. */
    #[Computed]
    public function topItems()
    {
        if (! $this->tenant || ! $this->canFinance()) {
            return collect();
        }

        $categories = self::GROUPS[$this->breakdown]['categories'] ?? self::GROUPS['services']['categories'];

        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', 'void')
            ->whereIn('invoice_items.category', $categories)
            ->where('invoice_items.created_at', '>=', now()->setDate($this->year, 1, 1)->startOfDay())
            ->where('invoice_items.created_at', '<=', now()->setDate($this->year, 12, 31)->endOfDay())
            ->selectRaw('invoice_items.description as description, invoice_items.category as category, sum(invoice_items.quantity) as qty, sum(invoice_items.total) as total')
            ->groupBy('invoice_items.description', 'invoice_items.category')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    /** Progress bars: [label, value text, percent, color]. */
    #[Computed]
    public function targets(): array
    {
        if (! $this->tenant) {
            return [];
        }

        $user = Auth::user();
        $bars = [];

        if ($this->canFinance()) {
            $from = now()->startOfMonth();
            $billed = (float) Invoice::where('status', '!=', 'void')->where('created_at', '>=', $from)->sum('total');
            $collected = (float) Invoice::where('status', '!=', 'void')->where('created_at', '>=', $from)->sum('amount_paid');
            $bars[] = [__('Collection rate (month)'), config('hms.currency').' '.number_format($collected, 0), $billed > 0 ? round($collected / $billed * 100, 1) : 0, 'brand-1'];
        }

        if ($user->canAny(['wards.view', 'nursing.tasks.manage', 'reports.view'])) {
            $beds = Bed::count();
            $occ = Bed::where('status', 'occupied')->count();
            $bars[] = [__('Bed occupancy'), $occ.' / '.$beds.' '.__('beds'), $beds ? round($occ / $beds * 100, 1) : 0, 'brand-2'];
        }

        if ($this->canClinicalStats()) {
            $today = Encounter::whereDate('started_at', today())->count();
            $done = Encounter::whereDate('started_at', today())->where('status', 'completed')->count();
            $bars[] = [__('Visits completed today'), $done.' / '.$today, $today ? round($done / $today * 100, 1) : 0, 'brand-3'];
        }

        return $bars;
    }

    public function switchTenant(int $tenantId): void
    {
        $allowed = Auth::user()->tenants()->wherePivot('status', 'active')->pluck('tenants.id');

        abort_unless($allowed->contains($tenantId), 403);

        session(['current_tenant_id' => $tenantId]);
        session()->forget('current_facility_id');

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function switchFacility(int $facilityId): void
    {
        // Re-validated server-side; IdentifyTenant re-checks on every request too.
        abort_unless($this->facilities->contains('id', $facilityId), 403);

        session(['current_facility_id' => $facilityId]);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function setYear(int $year): void
    {
        $this->year = in_array($year, $this->years(), true) ? $year : (int) now()->year;
    }

    public function setMetric(string $metric): void
    {
        $this->metric = $metric === 'revenue' && $this->canFinance() ? 'revenue' : 'visits';
    }

    public function setBreakdown(string $group): void
    {
        $this->breakdown = array_key_exists($group, self::GROUPS) ? $group : 'services';
    }
};
?>

@php
    $tints = [
        'blue' => 'bg-[#EEF3FF] text-[#4E7CFF]',
        'purple' => 'bg-[#F3EDFF] text-[#7033FF]',
        'red' => 'bg-[#FFF0F2] text-[#F65164]',
        'cyan' => 'bg-[#E9F8FD] text-[#3BB4D9]',
        'orange' => 'bg-[#FFF3EC] text-[#F08A4B]',
    ];
    $segColors = ['services' => '#4E7CFF', 'diagnostics' => '#7033FF', 'pharmacy' => '#F65164'];
    $brand = ['brand-1' => '#4E7CFF', 'brand-2' => '#7033FF', 'brand-3' => '#F65164'];
    $stageHex = ['amber' => '#F5A524', 'sky' => '#3BB4D9', 'indigo' => '#4E7CFF', 'purple' => '#7033FF', 'teal' => '#14B8A6', 'orange' => '#F08A4B', 'rose' => '#F65164', 'lime' => '#65C466', 'zinc' => '#9CA4B8'];
    // Vivid gradients per KPI tint: [from, to, soft wash]
    $grad = [
        'blue' => ['#4E7CFF', '#6FA0FF', '#EEF3FF'],
        'purple' => ['#7033FF', '#A06BFF', '#F3EDFF'],
        'red' => ['#F65164', '#FF8A7A', '#FFF0F2'],
        'cyan' => ['#1FB6D9', '#5AD8E6', '#E6F9FC'],
        'orange' => ['#F5872F', '#FFB25B', '#FFF4EA'],
    ];
    $palette = ['#4E7CFF', '#7033FF', '#F65164', '#1FB6D9', '#F5872F', '#14B8A6'];
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-5">
    @if ($this->tenant)
        {{-- Hero banner --}}
        @php
            // The banner follows the institution's brand colours (Institution profile → Logo & colours).
            $bp = $this->tenant->brandColor('primary');
            $bs = $this->tenant->brandColor('secondary');
        @endphp
        <div class="relative overflow-hidden rounded-2xl p-6 text-white shadow-lg sm:p-7" style="background: linear-gradient(120deg, {{ $bp }} 0%, {{ $bs }} 70%, #F65164 130%); box-shadow: 0 12px 30px -14px {{ $bp }}">
            @if ($this->tenant->logoUrl())
                <img src="{{ $this->tenant->logoUrl() }}" alt="" class="pointer-events-none absolute end-6 top-1/2 hidden size-24 -translate-y-1/2 rounded-2xl bg-white/90 object-contain p-2 shadow-lg lg:block" />
            @endif
            <div class="pointer-events-none absolute -end-10 -top-16 size-56 rounded-full bg-white/10"></div>
            <div class="pointer-events-none absolute -bottom-20 end-40 size-44 rounded-full bg-white/10"></div>
            <div class="pointer-events-none absolute end-6 top-6 size-16 rounded-full bg-[#F65164]/40 blur-xl"></div>

            <div class="relative flex flex-wrap items-end justify-between gap-5">
                <div>
                    <div class="text-[0.65rem] font-medium uppercase tracking-[0.2em] text-white/70">{{ now()->format('l, j F Y') }}</div>
                    <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">{{ __('Welcome back') }}, {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first() }} 👋</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full bg-white/15 px-3 py-1 backdrop-blur">{{ $this->tenant->name }}</span>
                        @if ($this->facility)
                            <span class="rounded-full bg-white/15 px-3 py-1 backdrop-blur">{{ $this->facility->name }}</span>
                            @if ($this->facility->level)<span class="rounded-full bg-white px-3 py-1 font-semibold text-[#4E7CFF]">{{ FacilityLevel::short($this->facility->level) }}</span>@endif
                        @endif
                        @if ($this->roles->isNotEmpty())<span class="rounded-full bg-[#F65164]/80 px-3 py-1">{{ $this->roles->join(', ') }}</span>@endif
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    @if ($this->memberships->count() > 1)
                        <div class="inline-flex overflow-hidden rounded-lg bg-white/15 text-[0.7rem] backdrop-blur">
                            @foreach ($this->memberships as $membership)
                                <button type="button" wire:click="switchTenant({{ $membership->id }})" class="px-3 py-1.5 {{ $this->tenant->id === $membership->id ? 'bg-white font-semibold text-[#4E7CFF]' : 'text-white/90 hover:bg-white/10' }}">{{ $membership->name }}</button>
                            @endforeach
                        </div>
                    @endif
                    @if ($this->facilities->count() > 1)
                        <div class="inline-flex overflow-hidden rounded-lg bg-white/15 text-[0.7rem] backdrop-blur">
                            @foreach ($this->facilities as $f)
                                <button type="button" wire:click="switchFacility({{ $f->id }})" class="px-3 py-1.5 {{ $this->facility?->id === $f->id ? 'bg-white font-semibold text-[#7033FF]' : 'text-white/90 hover:bg-white/10' }}">{{ $f->name }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- KPI cards --}}
        @if ($this->kpis)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                @foreach ($this->kpis as [$label, $value, $icon, $tint, $route])
                    @php
                        [$from, $to, $wash] = $grad[$tint];
                    @endphp
                    <a @if ($route) href="{{ route($route) }}" wire:navigate @endif
                       class="group relative overflow-hidden rounded-xl border bg-white p-5 transition dark:bg-zinc-900 {{ $route ? 'hover:-translate-y-0.5 hover:shadow-lg' : 'pointer-events-none' }}"
                       style="border-color: {{ $from }}33; box-shadow: 0 6px 20px -10px {{ $from }}66; background-image: linear-gradient(135deg, {{ $wash }} 0%, #ffffff 60%)">
                        <div class="absolute inset-x-0 top-0 h-1" style="background: linear-gradient(90deg, {{ $from }}, {{ $to }})"></div>
                        <div class="pointer-events-none absolute -end-6 -bottom-6 size-20 rounded-full opacity-10" style="background: {{ $from }}"></div>
                        <div class="relative flex items-center gap-4">
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl text-white shadow-md" style="background: linear-gradient(135deg, {{ $from }}, {{ $to }}); box-shadow: 0 6px 14px -6px {{ $from }}">
                                <flux:icon :name="$icon" class="size-5" />
                            </div>
                            <div class="min-w-0">
                                <div class="kpi-label truncate" style="color: {{ $from }}">{{ $label }}</div>
                                <div class="mt-1 truncate text-xl font-semibold text-hms-text dark:text-white">{{ $value }}</div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Patient flow chips --}}
        @if ($this->flow)
            <div class="flex flex-wrap gap-2">
                @foreach ($this->flow as $f)
                    @php
                        $c = $stageHex[$f['color']] ?? '#9CA4B8';
                    @endphp
                    <div class="flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium" style="background: {{ $c }}1A; color: {{ $c }}">
                        <span class="size-2 rounded-full" style="background: {{ $c }}"></span>
                        <span>{{ __($f['label']) }}</span>
                        <span class="rounded-full px-1.5 text-[0.7rem] font-semibold text-white" style="background: {{ $c }}">{{ $f['count'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Charts row --}}
        <div class="grid gap-5 xl:grid-cols-5">
            @if ($this->canClinicalStats() || $this->canFinance())
                @php
                    $series = $this->series;
                    $values = collect($series)->pluck('v')->filter(fn ($v) => $v !== null);
                    $max = $this::niceMax((float) ($values->max() ?? 0));
                    $W = 620; $H = 220; $L = 44; $T = 12; $B = 28; $R = 12;
                    $pw = $W - $L - $R; $ph = $H - $T - $B;
                    $pts = collect($series)->filter(fn ($p) => $p['v'] !== null)->map(fn ($p) => [($L + ($p['m'] - 1) * $pw / 11), ($T + $ph - ($p['v'] / $max) * $ph), $p]);
                    $line = $pts->map(fn ($p) => round($p[0], 1).','.round($p[1], 1))->implode(' ');
                    $area = $pts->isNotEmpty() ? 'M'.$pts->first()[0].','.($T + $ph).' L'.$pts->map(fn ($p) => round($p[0], 1).','.round($p[1], 1))->implode(' L').' L'.$pts->last()[0].','.($T + $ph).' Z' : '';
                @endphp
                <div class="card p-5 xl:col-span-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg text-white" style="background: linear-gradient(135deg, #4E7CFF, #7033FF)"><flux:icon :name="$metric === 'revenue' ? 'banknotes' : 'chart-bar'" class="size-4" /></div>
                            <div>
                            <div class="card-title">{{ $metric === 'revenue' ? __('Revenue performance') : __('Patient visits') }}</div>
                            <div class="card-subtitle mt-1">{{ $metric === 'revenue' ? __('Collected by month') : __('Visits by month') }} · {{ $year }}</div>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($this->canFinance())
                                <div class="seg">
                                    <button type="button" aria-pressed="{{ $metric === 'visits' ? 'true' : 'false' }}" wire:click="setMetric('visits')">{{ __('Visits') }}</button>
                                    <button type="button" aria-pressed="{{ $metric === 'revenue' ? 'true' : 'false' }}" wire:click="setMetric('revenue')">{{ __('Revenue') }}</button>
                                </div>
                            @endif
                            <div class="seg">
                                @foreach ($this->years() as $y)
                                    <button type="button" aria-pressed="{{ $year === $y ? 'true' : 'false' }}" wire:click="setYear({{ $y }})">{{ $y }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <svg viewBox="0 0 {{ $W }} {{ $H }}" class="mt-4 w-full" role="img" aria-label="{{ __('Monthly trend chart') }}">
                        <defs>
                            <linearGradient id="trendFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#7033FF" stop-opacity="0.22" />
                                <stop offset="60%" stop-color="#4E7CFF" stop-opacity="0.08" />
                                <stop offset="100%" stop-color="#4E7CFF" stop-opacity="0" />
                            </linearGradient>
                            <linearGradient id="trendStroke" x1="0" x2="1" y1="0" y2="0">
                                <stop offset="0%" stop-color="#4E7CFF" />
                                <stop offset="60%" stop-color="#7033FF" />
                                <stop offset="100%" stop-color="#F65164" />
                            </linearGradient>
                        </defs>
                        @foreach (range(0, 4) as $i)
                            @php
                                $gy = $T + $ph - $i * $ph / 4;
                            @endphp
                            <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="#E8ECF4" />
                            <text x="{{ $L - 8 }}" y="{{ $gy + 3 }}" font-size="9" text-anchor="end" fill="#9CA4B8">{{ ($metric === 'revenue' ? '' : '').$this::short($max * $i / 4) }}</text>
                        @endforeach
                        @foreach ($series as $p)
                            <text x="{{ $L + ($p['m'] - 1) * $pw / 11 }}" y="{{ $H - 8 }}" font-size="9" text-anchor="middle" fill="#9CA4B8">{{ $p['label'] }}</text>
                        @endforeach
                        @if ($pts->count() > 1)
                            <path d="{{ $area }}" fill="url(#trendFill)" />
                            <polyline points="{{ $line }}" fill="none" stroke="url(#trendStroke)" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" />
                        @endif
                        @foreach ($pts as [$x, $y, $p])
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="#fff" stroke="{{ $palette[($p['m'] - 1) % 3] }}" stroke-width="2.5"><title>{{ $p['label'] }}: {{ $metric === 'revenue' ? config('hms.currency').' '.number_format($p['v'], 0) : number_format($p['v']) }}</title></circle>
                        @endforeach
                    </svg>
                </div>
            @endif

            @if ($this->payerSummary)
                @php
                    $pmax = $this::niceMax((float) collect($this->payerSummary)->max('total'));
                @endphp
                <div class="card p-5 xl:col-span-2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg text-white" style="background: linear-gradient(135deg, #7033FF, #F65164)"><flux:icon name="shield-check" class="size-4" /></div>
                            <div class="card-title">{{ __('Payer summary') }}</div>
                        </div>
                        <div class="card-subtitle">{{ __('Billed by service') }} · {{ $year }}</div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3 text-[0.7rem] text-zinc-500">
                        @foreach ($this::GROUPS as $k => $g)
                            <span class="flex items-center gap-1.5"><span class="size-2 rounded-full" style="background: {{ $segColors[$k] }}"></span>{{ __($g['label']) }}</span>
                        @endforeach
                    </div>
                    <div class="mt-5 space-y-4">
                        @foreach ($this->payerSummary as $row)
                            <div class="flex items-center gap-3">
                                <div class="w-24 shrink-0 text-right text-[0.7rem] text-zinc-500">{{ $row['label'] }}</div>
                                <div class="flex h-5 flex-1 gap-0.5 overflow-hidden rounded-full bg-zinc-50 dark:bg-zinc-800" title="{{ config('hms.currency') }} {{ number_format($row['total'], 0) }}">
                                    @foreach ($row['parts'] as $k => $v)
                                        @if ($v > 0)
                                            <div class="first:rounded-s-full last:rounded-e-full" style="width: {{ $v / $pmax * 100 }}%; background: linear-gradient(90deg, {{ $segColors[$k] }}, {{ $segColors[$k] }}CC)" title="{{ __($this::GROUPS[$k]['label']) }}: {{ config('hms.currency') }} {{ number_format($v, 0) }}"></div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex justify-between text-[0.65rem] text-zinc-400" style="padding-inline-start: 6.75rem">
                        @foreach (range(0, 4) as $i)
                            <span>{{ $this::short($pmax * $i / 4) }}</span>
                        @endforeach
                    </div>
                </div>
            @elseif ($this->modules)
                <div class="card p-5 xl:col-span-2">
                    <div class="card-title">{{ __('Your workspaces') }}</div>
                    <div class="card-subtitle mt-1">{{ __('Quick access') }}</div>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        @foreach (array_slice($this->modules, 0, 8) as $module)
                            <a href="{{ route($module['route']) }}" wire:navigate class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-hms-text hover:bg-hms-bg dark:text-zinc-100 dark:hover:bg-zinc-800">
                                <flux:icon :name="$module['icon']" class="size-4 text-brand-1" /> {{ __($module['label']) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Breakdown + targets row --}}
        <div class="grid gap-5 xl:grid-cols-5">
            @if ($this->canFinance())
                <div class="card p-5 xl:col-span-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="flex size-9 items-center justify-center rounded-lg text-white" style="background: linear-gradient(135deg, #1FB6D9, #4E7CFF)"><flux:icon name="list-bullet" class="size-4" /></div>
                            <div>
                                <div class="card-title">{{ __('Service breakdown') }}</div>
                                <div class="card-subtitle mt-1">{{ __('Top billed items') }} · {{ $year }}</div>
                            </div>
                        </div>
                        <div class="seg">
                            @foreach ($this::GROUPS as $k => $g)
                                <button type="button" aria-pressed="{{ $breakdown === $k ? 'true' : 'false' }}" wire:click="setBreakdown('{{ $k }}')">{{ __($g['label']) }}</button>
                            @endforeach
                        </div>
                    </div>
                    <div class="mt-4 divide-y divide-hms-line text-xs dark:divide-zinc-800">
                        @forelse ($this->topItems as $item)
                            @php
                                $rc = $palette[$loop->index % count($palette)];
                            @endphp
                            <div wire:key="ti-{{ $loop->index }}" class="grid grid-cols-12 items-center gap-3 py-2.5">
                                <div class="col-span-5 flex items-center gap-2 truncate text-hms-text dark:text-zinc-100">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-md text-[0.65rem] font-bold text-white" style="background: {{ $rc }}">{{ $loop->iteration }}</span>{{ $item->description }}
                                </div>
                                <div class="col-span-3"><span class="rounded-full px-2 py-0.5 text-[0.65rem] font-medium" style="background: {{ $rc }}1A; color: {{ $rc }}">{{ ucfirst($item->category) }}</span></div>
                                <div class="col-span-1 text-right text-zinc-500">{{ number_format((float) $item->qty) }}</div>
                                <div class="col-span-3 text-right font-medium text-hms-text dark:text-zinc-100">{{ config('hms.currency') }} {{ number_format((float) $item->total, 0) }}</div>
                            </div>
                        @empty
                            <div class="py-8 text-center text-zinc-400">{{ __('No billed items in :year yet.', ['year' => $year]) }}</div>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($this->targets)
                <div class="card p-5 {{ $this->canFinance() ? 'xl:col-span-2' : 'xl:col-span-5' }}">
                    <div class="flex items-center gap-3">
                        <div class="flex size-9 items-center justify-center rounded-lg text-white" style="background: linear-gradient(135deg, #F65164, #F5872F)"><flux:icon name="flag" class="size-4" /></div>
                        <div class="card-title">{{ __('Performance targets') }}</div>
                    </div>
                    @php
                        $barGrad = ['brand-1' => ['#4E7CFF', '#1FB6D9'], 'brand-2' => ['#7033FF', '#C06BFF'], 'brand-3' => ['#F65164', '#F5872F']];
                    @endphp
                    <div class="mt-5 space-y-5">
                        @foreach ($this->targets as [$label, $value, $pct, $color])
                            <div class="rounded-xl p-3" style="background: {{ $brand[$color] }}0F">
                                <div class="flex items-end justify-between gap-2 text-[0.7rem]">
                                    <span class="text-[0.65rem] font-medium uppercase tracking-[0.15em]" style="color: {{ $brand[$color] }}">{{ $label }}</span>
                                    <span class="rounded-full px-2 py-0.5 font-semibold text-white" style="background: {{ $brand[$color] }}">{{ $pct }}%</span>
                                </div>
                                <div class="mt-1 text-sm font-semibold text-hms-text dark:text-zinc-100">{{ $value }}</div>
                                <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-white dark:bg-zinc-800">
                                    <div class="h-full rounded-full" style="width: {{ max(2, min(100, $pct)) }}%; background: linear-gradient(90deg, {{ $barGrad[$color][0] }}, {{ $barGrad[$color][1] }})"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Workspaces --}}
        @if ($this->modules)
            @php
                // One colour per sidebar section, so modules group visually.
                $groupColor = [
                    'Front office' => '#4E7CFF', 'Clinical' => '#F65164', 'Maternity' => '#E84393',
                    'Diagnostics' => '#7033FF', 'Pharmacy' => '#14B8A6', 'Inpatient' => '#F5872F',
                    'Finance' => '#22A06B', 'Management' => '#1FB6D9', 'Administration' => '#6E768A',
                ];
            @endphp
            <div class="card p-5">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 items-center justify-center rounded-lg text-white" style="background: linear-gradient(135deg, #14B8A6, #4E7CFF)"><flux:icon name="squares-2x2" class="size-4" /></div>
                    <div class="card-title">{{ __('Your modules') }}</div>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4 xl:grid-cols-6">
                    @foreach ($this->modules as $module)
                        @php
                            $mc = $groupColor[$module['group']] ?? '#4E7CFF';
                        @endphp
                        <a href="{{ route($module['route']) }}" wire:navigate class="group flex items-center gap-3 rounded-xl p-3 transition hover:-translate-y-0.5 hover:shadow-md" style="background: {{ $mc }}12; border: 1px solid {{ $mc }}26">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-lg text-white shadow-sm" style="background: linear-gradient(135deg, {{ $mc }}, {{ $mc }}BB)"><flux:icon :name="$module['icon']" class="size-4" /></div>
                            <div class="min-w-0">
                                <div class="truncate text-xs font-semibold text-hms-text dark:text-zinc-100">{{ __($module['label']) }}</div>
                                <div class="truncate text-[0.65rem] font-medium" style="color: {{ $mc }}">{{ __($module['group']) }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="card border-amber-300 bg-amber-50 p-6 dark:border-amber-700 dark:bg-amber-950">
            <flux:heading level="2">{{ __('No hospital assigned') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your account is not a member of any hospital tenant yet. Contact your administrator.') }}</flux:text>
            @if (auth()->user()->isPlatformAdmin())
                <flux:button class="mt-3" :href="route('platform.tenants')" wire:navigate>{{ __('Go to platform administration') }}</flux:button>
            @endif
        </div>
    @endif
</div>
