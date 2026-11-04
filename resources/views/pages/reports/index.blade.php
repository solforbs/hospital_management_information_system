<?php

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\InvoiceItem;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Reports')] class extends Component {
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('reports.view'), 403);
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    protected function range(): array
    {
        return [Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to)->endOfDay()];
    }

    #[Computed]
    public function summary()
    {
        $r = $this->range();
        $beds = Bed::count();

        return [
            'new_patients' => Patient::whereBetween('registered_at', $r)->count(),
            'visits' => Encounter::whereBetween('started_at', $r)->count(),
            'opd' => Encounter::whereBetween('started_at', $r)->where('type', 'outpatient')->count(),
            'emergency' => Encounter::whereBetween('started_at', $r)->where('type', 'emergency')->count(),
            'admissions' => Admission::whereBetween('admitted_at', $r)->count(),
            'discharges' => Admission::whereBetween('discharged_at', $r)->count(),
            'deaths' => Admission::whereBetween('discharged_at', $r)->where('discharge_type', 'deceased')->count(),
            'occupancy' => $beds ? round(Bed::where('status', 'occupied')->count() / $beds * 100) : 0,
            'collections' => (float) Payment::whereBetween('received_at', $r)->sum('amount'),
            'billed' => (float) InvoiceItem::whereBetween('created_at', $r)->sum('total'),
            'lab_tests' => LabOrderItem::whereBetween('created_at', $r)->where('status', '!=', 'cancelled')->count(),
            'lab_tat' => (int) round((float) LabOrderItem::whereBetween('released_at', $r)->get()->avg(fn ($i) => $i->created_at->diffInMinutes($i->released_at))),
        ];
    }

    #[Computed]
    public function revenueByCategory()
    {
        return InvoiceItem::whereBetween('created_at', $this->range())->selectRaw('category, sum(total) as total, count(*) as n')->groupBy('category')->orderByDesc('total')->get();
    }

    #[Computed]
    public function collectionsByMethod()
    {
        return Payment::whereBetween('received_at', $this->range())->selectRaw('method, sum(amount) as total, count(*) as n')->groupBy('method')->orderByDesc('total')->get();
    }

    #[Computed]
    public function topDiagnoses()
    {
        return Diagnosis::whereBetween('created_at', $this->range())->selectRaw('code, description, count(*) as n')->groupBy('code', 'description')->orderByDesc('n')->limit(10)->get();
    }

    #[Computed]
    public function dailyVisits()
    {
        return Encounter::whereBetween('started_at', $this->range())->selectRaw('date(started_at) as d, count(*) as n')->groupBy('d')->orderBy('d')->pluck('n', 'd');
    }

    public function export(string $report, AuditLogger $audit): StreamedResponse
    {
        abort_unless(Auth::user()->can('reports.export'), 403);
        abort_unless(in_array($report, ['revenue', 'collections', 'diagnoses'], true), 404);

        $rows = match ($report) {
            'revenue' => $this->revenueByCategory->map(fn ($r) => [$r->category, $r->n, $r->total]),
            'collections' => $this->collectionsByMethod->map(fn ($r) => [$r->method, $r->n, $r->total]),
            'diagnoses' => $this->topDiagnoses->map(fn ($r) => [$r->code, $r->description, $r->n]),
        };
        $headers = ['revenue' => ['Category', 'Lines', 'Total'], 'collections' => ['Method', 'Receipts', 'Total'], 'diagnoses' => ['Code', 'Diagnosis', 'Count']][$report];

        $audit->log('report.exported', null, ['report' => $report, 'from' => $this->from, 'to' => $this->to]);

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "{$report}_{$this->from}_{$this->to}.csv", ['Content-Type' => 'text/csv']);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <flux:heading level="1">{{ __('Reports') }}</flux:heading>
        <div class="flex items-end gap-2">
            <flux:button variant="primary" icon="document-chart-bar" :href="route('reports.moh')" wire:navigate>{{ __('MOH reports (KHIS)') }}</flux:button>
            <flux:input type="date" wire:model.live="from" :label="__('From')" />
            <flux:input type="date" wire:model.live="to" :label="__('To')" />
        </div>
    </div>

    @php $s = $this->summary; @endphp
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
        @foreach ([
            __('New patients') => $s['new_patients'], __('Total visits') => $s['visits'], __('Outpatient') => $s['opd'], __('Emergency') => $s['emergency'],
            __('Admissions') => $s['admissions'], __('Discharges') => $s['discharges'], __('Deaths') => $s['deaths'], __('Bed occupancy (now)') => $s['occupancy'].'%',
            __('Lab tests') => $s['lab_tests'], __('Avg lab TAT') => $s['lab_tat'].' '.__('min'),
        ] as $label => $value)
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ $label }}</div><div class="text-2xl font-semibold">{{ $value }}</div></div>
        @endforeach
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ __('Billed') }}</div><div class="text-lg font-semibold"><x-money :amount="$s['billed']" /></div></div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ __('Collected') }}</div><div class="text-lg font-semibold"><x-money :amount="$s['collections']" /></div></div>
    </div>

    <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:heading level="3" class="mb-3">{{ __('Visits per day') }}</flux:heading>
        @php $max = max(1, $this->dailyVisits->max() ?? 1); @endphp
        <div class="flex h-40 items-end gap-1">
            @forelse ($this->dailyVisits as $d => $n)
                <div class="flex flex-1 flex-col items-center justify-end" title="{{ $d }}: {{ $n }}">
                    <div class="w-full rounded-t bg-indigo-500" style="height: {{ round($n / $max * 100) }}%"></div>
                    <div class="mt-1 text-[10px] text-neutral-500">{{ \Illuminate\Support\Carbon::parse($d)->format('d') }}</div>
                </div>
            @empty
                <flux:text>{{ __('No visits in range.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        @foreach ([
            'revenue' => [__('Revenue by category'), $this->revenueByCategory, 'category'],
            'collections' => [__('Collections by method'), $this->collectionsByMethod, 'method'],
        ] as $key => [$title, $rows, $field])
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="mb-2 flex items-center justify-between">
                    <flux:heading level="3">{{ $title }}</flux:heading>
                    @can('reports.export')<flux:button size="xs" icon="arrow-down-tray" wire:click="export('{{ $key }}')">CSV</flux:button>@endcan
                </div>
                @forelse ($rows as $r)
                    <div class="flex justify-between border-b border-neutral-100 py-1 text-sm dark:border-neutral-800"><span>{{ ucfirst(str_replace('_', ' ', $r->{$field})) }} <span class="text-xs text-neutral-500">({{ $r->n }})</span></span><x-money :amount="$r->total" /></div>
                @empty
                    <flux:text>—</flux:text>
                @endforelse
            </div>
        @endforeach
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="mb-2 flex items-center justify-between">
                <flux:heading level="3">{{ __('Top diagnoses') }}</flux:heading>
                @can('reports.export')<flux:button size="xs" icon="arrow-down-tray" wire:click="export('diagnoses')">CSV</flux:button>@endcan
            </div>
            @forelse ($this->topDiagnoses as $d)
                <div class="flex justify-between border-b border-neutral-100 py-1 text-sm dark:border-neutral-800"><span>@if ($d->code)<span class="font-mono text-xs">{{ $d->code }}</span> @endif{{ $d->description }}</span><span>{{ $d->n }}</span></div>
            @empty
                <flux:text>—</flux:text>
            @endforelse
        </div>
    </div>
</div>
