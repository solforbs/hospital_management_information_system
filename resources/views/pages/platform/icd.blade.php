<?php

use App\Models\IcdCode;
use App\Services\Audit\AuditLogger;
use App\Services\Reference\Icd11Importer;
use Flux\Flux;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * ICD-11 reference data (global, shared by every hospital). Platform admins
 * download the WHO release (or upload it on an offline server), import it
 * when convenient, and browse/curate the codes clinicians pick from.
 */
new #[Title('ICD-11 codes')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $chapter = '';

    #[Url]
    public bool $commonOnly = false;

    public string $release = '2026-01';

    public $upload;

    public function updating($field): void
    {
        if (in_array($field, ['search', 'chapter', 'commonOnly'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => IcdCode::count(),
            'diagnosable' => IcdCode::diagnosable()->count(),
            'common' => IcdCode::where('is_common', true)->count(),
            'last' => Cache::get('icd11.last_import'),
        ];
    }

    #[Computed]
    public function chapterCounts(): array
    {
        return IcdCode::select('chapter', DB::raw('count(*) as c'))->groupBy('chapter')->pluck('c', 'chapter')->all();
    }

    #[Computed]
    public function files(): array
    {
        return app(Icd11Importer::class)->files();
    }

    #[Computed]
    public function codes()
    {
        $q = trim($this->search) !== '' ? IcdCode::search($this->search) : IcdCode::orderBy('code');

        return $q->when($this->chapter !== '', fn ($w) => $w->where('chapter', $this->chapter))
            ->when($this->commonOnly, fn ($w) => $w->where('is_common', true))
            ->paginate(50);
    }

    public function download(): void
    {
        $this->validate(['release' => ['required', 'regex:/^\d{4}-\d{2}$/']], ['release.regex' => __('Use the WHO release name, e.g. 2026-01.')]);

        try {
            $path = app(Icd11Importer::class)->download($this->release);
        } catch (\RuntimeException $e) {
            $this->addError('release', $e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('platform.icd11_downloaded', null, ['file' => basename($path)]);
        unset($this->files);
        Flux::toast(variant: 'success', text: __('Downloaded :file. Import it whenever you are ready.', ['file' => basename($path)]));
    }

    public function saveUpload(): void
    {
        $this->validate(['upload' => ['required', 'file', 'max:51200', 'extensions:zip,txt,tsv']], [], ['upload' => __('file')]);

        $importer = app(Icd11Importer::class);
        $name = 'Uploaded-'.now()->format('Ymd-His').'.'.strtolower($this->upload->getClientOriginalExtension());
        copy($this->upload->getRealPath(), $importer->directory().'/'.$name);
        $this->reset('upload');

        app(AuditLogger::class)->log('platform.icd11_uploaded', null, ['file' => $name]);
        unset($this->files);
        Flux::toast(variant: 'success', text: __('Saved :file. Import it whenever you are ready.', ['file' => $name]));
    }

    public function import(string $name): void
    {
        $importer = app(Icd11Importer::class);

        try {
            $count = $importer->import($importer->pathFor($name));
        } catch (\RuntimeException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        app(AuditLogger::class)->log('platform.icd11_imported', null, ['file' => basename($name), 'codes' => $count]);
        unset($this->stats, $this->chapterCounts, $this->codes);
        Flux::toast(variant: 'success', text: __(':count ICD-11 codes imported.', ['count' => number_format($count)]));
    }

    public function deleteFile(string $name): void
    {
        $importer = app(Icd11Importer::class);

        try {
            unlink($importer->pathFor($name));
        } catch (\RuntimeException) {
            // already gone
        }

        unset($this->files);
    }

    public function toggleCommon(int $id): void
    {
        $code = IcdCode::findOrFail($id);
        $code->update(['is_common' => ! $code->is_common]);
        unset($this->stats);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('ICD-11 codes') }}</flux:heading>
        <flux:text>{{ __('The WHO ICD-11 classification used for diagnosis coding in every hospital. Download a release from WHO (or upload one), import it when convenient, and mark the codes clinicians should see first.') }}</flux:text>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @php
            $last = $this->stats['last'];
            $cards = [
                [__('Codes loaded'), number_format($this->stats['total']), '#4E7CFF', '#7033FF'],
                [__('Usable as diagnosis'), number_format($this->stats['diagnosable']), '#10B981', '#059669'],
                [__('Marked common'), number_format($this->stats['common']), '#F59E0B', '#F97316'],
                [__('Last import'), $last ? \Illuminate\Support\Carbon::parse($last['at'])->format('d M Y H:i') : __('Starter list only'), '#EC4899', '#8B5CF6'],
            ];
        @endphp
        @foreach ($cards as [$label, $value, $c1, $c2])
            <div class="rounded-xl p-4 text-white shadow-sm" style="background: linear-gradient(135deg, {{ $c1 }}, {{ $c2 }});">
                <div class="text-xs uppercase tracking-wide opacity-80">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>
    @if ($last)
        <flux:text class="-mt-3 text-xs">{{ __('Last import: :count codes from :file by :by.', ['count' => number_format($last['count']), 'file' => $last['file'], 'by' => $last['by']]) }}</flux:text>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="grid content-start gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div>
                <flux:heading level="3">{{ __('1. Get the file') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Downloads are saved on the server and are not imported until you choose to.') }}</flux:text>
            </div>
            <form wire:submit="download" class="flex items-end gap-2">
                <flux:input wire:model="release" :label="__('WHO release')" placeholder="2026-01" class="max-w-40" />
                <flux:button type="submit" variant="primary" icon="arrow-down-tray" wire:loading.attr="disabled" wire:target="download">
                    <span wire:loading.remove wire:target="download">{{ __('Download from WHO') }}</span>
                    <span wire:loading wire:target="download">{{ __('Downloading…') }}</span>
                </flux:button>
            </form>
            <form wire:submit="saveUpload" class="flex items-end gap-2">
                <flux:input type="file" wire:model="upload" :label="__('Or upload the WHO file (.zip or .txt)')" accept=".zip,.txt,.tsv" />
                <flux:button type="submit" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="upload,saveUpload">{{ __('Save') }}</flux:button>
            </form>
            <flux:text class="text-xs">{{ __('Offline servers: download "Spreadsheet file" from icd.who.int (Info menu) on any computer and upload it here.') }}</flux:text>
        </div>

        <div class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div>
                <flux:heading level="3">{{ __('2. Import into the system') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Importing again is safe: existing codes are updated, nothing is duplicated, and "common" marks are kept.') }}</flux:text>
            </div>
            @forelse ($this->files as $file)
                <div class="flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2 dark:bg-neutral-800" wire:key="f-{{ $file['name'] }}">
                    <div class="min-w-0">
                        <div class="truncate font-mono text-sm">{{ $file['name'] }}</div>
                        <div class="text-xs text-neutral-500">{{ number_format($file['size'] / 1048576, 1) }} MB · {{ \Illuminate\Support\Carbon::createFromTimestamp($file['modified'])->format('d M Y H:i') }}</div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <flux:button size="sm" variant="primary" icon="arrow-path" wire:click="import('{{ $file['name'] }}')" wire:loading.attr="disabled" wire:target="import">
                            <span wire:loading.remove wire:target="import('{{ $file['name'] }}')">{{ __('Import') }}</span>
                            <span wire:loading wire:target="import('{{ $file['name'] }}')">{{ __('Importing…') }}</span>
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteFile('{{ $file['name'] }}')" wire:confirm="{{ __('Delete this file from the server? Imported codes stay.') }}" />
                    </div>
                </div>
            @empty
                <flux:text class="text-sm">{{ __('No files yet. Download or upload a release first.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div class="grid gap-3">
        <flux:heading level="2">{{ __('Browse codes') }}</flux:heading>
        <div class="flex flex-wrap items-center gap-3">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Code or condition, e.g. 1F40 or malaria')" class="max-w-sm" />
            <flux:select wire:model.live="chapter" class="max-w-md">
                <flux:select.option value="">{{ __('All chapters') }}</flux:select.option>
                @foreach (\App\Models\IcdCode::CHAPTERS as $no => $name)
                    <flux:select.option value="{{ $no }}">{{ $no }} · {{ $name }} ({{ number_format($this->chapterCounts[$no] ?? 0) }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:checkbox wire:model.live="commonOnly" :label="__('Common only')" />
        </div>

        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Code') }}</th><th class="px-3 py-2">{{ __('Title') }}</th><th class="px-3 py-2">{{ __('Chapter') }}</th><th class="px-3 py-2">{{ __('Use') }}</th><th class="px-3 py-2 text-center">{{ __('Common') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->codes as $code)
                        <tr wire:key="icd-{{ $code->id }}">
                            <td class="px-3 py-2 font-mono text-xs font-semibold whitespace-nowrap">{{ $code->code }}</td>
                            <td class="px-3 py-2">{{ $code->title }}</td>
                            <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap" title="{{ \App\Models\IcdCode::CHAPTERS[$code->chapter] ?? '' }}">{{ $code->chapter }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap">
                                @if (in_array($code->chapter, \App\Models\IcdCode::NON_DIAGNOSTIC_CHAPTERS, true))
                                    <flux:badge size="sm" color="zinc">{{ $code->chapter === 'X' ? __('Extension') : __('Functioning') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">{{ __('Diagnosis') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" wire:click="toggleCommon({{ $code->id }})" class="text-lg leading-none" title="{{ __('Common conditions are listed first when clinicians search') }}" style="color: {{ $code->is_common ? '#F59E0B' : '#D4D4D8' }}">★</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">{{ __('No codes match.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $this->codes->links() }}
    </div>
</div>
