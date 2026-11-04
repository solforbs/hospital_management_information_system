<?php

use App\Models\Facility;
use App\Services\Audit\AuditLogger;
use App\Services\Reports\MohReportService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('MOH reports')] class extends Component {
    #[Url]
    public string $report = '717';

    #[Url]
    public string $month = '';

    #[Url]
    public string $facility = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('reports.view'), 403);
        $this->month = $this->month ?: now()->subMonthNoOverflow()->format('Y-m');
        $this->facility = $this->facility ?: (string) (app(TenantContext::class)->facilityId() ?? '');
    }

    protected function validMonth(): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    protected function facilityModel(): ?Facility
    {
        return $this->facility !== '' ? Facility::find((int) $this->facility) : null;
    }

    #[Computed]
    public function facilities()
    {
        return Facility::where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function data(): array
    {
        $report = array_key_exists($this->report, MohReportService::REPORTS) ? $this->report : '717';

        return app(MohReportService::class)->build($report, $this->validMonth(), $this->facilityModel()?->id);
    }

    public function exportCsv(AuditLogger $audit): StreamedResponse
    {
        abort_unless(Auth::user()->can('reports.export'), 403);
        $data = $this->data;
        $audit->log('reports.moh_exported', null, ['report' => $this->report, 'month' => $this->month, 'format' => 'csv']);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$data['title'], $data['month'], app(TenantContext::class)->tenant()->name, $this->facilityModel()?->name]);

            if ($data['type'] === 'matrix') {
                fputcsv($out, array_merge(['Condition'], range(1, $data['days']), ['Total']));
                foreach ($data['rows'] as $r) {
                    fputcsv($out, array_merge([$r['label']], array_values($r['days']), [$r['total']]));
                }
                fputcsv($out, [$data['footer']['label'], $data['footer']['value']]);
            } else {
                fputcsv($out, ['Section', 'Indicator', 'Value']);
                foreach ($data['sections'] as $section => $rows) {
                    foreach ($rows as [$label, $value]) {
                        fputcsv($out, [$section, $label, $value]);
                    }
                }
            }
            fclose($out);
        }, "MOH{$this->report}-{$this->month}.csv", ['Content-Type' => 'text/csv']);
    }

    public function exportDhis2(AuditLogger $audit): StreamedResponse
    {
        abort_unless(Auth::user()->can('reports.export'), 403);
        $orgUnit = data_get($this->facilityModel()?->settings, 'khis_org_unit');
        $payload = app(MohReportService::class)->dhis2($this->report, $this->data, $orgUnit);
        $audit->log('reports.moh_exported', null, ['report' => $this->report, 'month' => $this->month, 'format' => 'dhis2']);

        return response()->streamDownload(fn () => print(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)), "MOH{$this->report}-{$this->month}.dhis2.json", ['Content-Type' => 'application/json']);
    }
};
?>

@php
    $d = $this->data;
@endphp
<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading level="1">{{ __('MOH reports') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Monthly summaries for KHIS (DHIS2). Export CSV for review or a DHIS2 data value set for import.') }}</flux:text>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('reports.index')" wire:navigate>{{ __('Reports') }}</flux:button>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="report" :label="__('Report')" class="min-w-80">
            @foreach (\App\Services\Reports\MohReportService::REPORTS as $k => $l)<flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>@endforeach
        </flux:select>
        <flux:input type="month" wire:model.live="month" :label="__('Month')" />
        <flux:select wire:model.live="facility" :label="__('Facility')">
            <flux:select.option value="">{{ __('All facilities') }}</flux:select.option>
            @foreach ($this->facilities as $f)<flux:select.option value="{{ $f->id }}">{{ $f->name }}</flux:select.option>@endforeach
        </flux:select>
        @can('reports.export')
            <flux:button icon="arrow-down-tray" wire:click="exportCsv">CSV</flux:button>
            <flux:button variant="primary" icon="arrow-up-tray" wire:click="exportDhis2">{{ __('DHIS2 data values') }}</flux:button>
        @endcan
    </div>

    <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <flux:heading level="3">{{ $d['title'] }} · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $d['month'])->format('F Y') }}</flux:heading>

        @if ($d['type'] === 'matrix')
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr><th class="sticky left-0 bg-white px-2 py-2 dark:bg-zinc-900">{{ __('Condition') }}</th>@foreach (range(1, $d['days']) as $day)<th class="px-1.5 py-2 text-center">{{ $day }}</th>@endforeach<th class="px-2 py-2 text-right">{{ __('Total') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($d['rows'] as $k => $r)
                            <tr wire:key="m-{{ $k }}">
                                <td class="sticky left-0 whitespace-nowrap bg-white px-2 py-1.5 dark:bg-zinc-900">{{ $r['label'] }}</td>
                                @foreach ($r['days'] as $n)<td class="px-1.5 py-1.5 text-center {{ $n ? 'font-semibold' : 'text-zinc-300' }}">{{ $n }}</td>@endforeach
                                <td class="px-2 py-1.5 text-right font-semibold">{{ $r['total'] }}</td>
                            </tr>
                        @endforeach
                        <tr><td class="px-2 py-2 font-semibold" colspan="{{ $d['days'] + 1 }}">{{ $d['footer']['label'] }}</td><td class="px-2 py-2 text-right font-semibold">{{ $d['footer']['value'] }}</td></tr>
                    </tbody>
                </table>
            </div>
        @else
            <div class="mt-4 grid gap-5 lg:grid-cols-2">
                @foreach ($d['sections'] as $section => $rows)
                    <div>
                        <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $section }}</div>
                        <div class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
                            @foreach ($rows as $k => [$label, $value])
                                <div wire:key="r-{{ $k }}" class="flex items-center justify-between px-3 py-2 text-sm"><span>{{ $label }}</span><span class="font-semibold">{{ number_format($value) }}</span></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
