<?php

use App\Models\TrainingAttempt;
use App\Services\Tenancy\TenantContext;
use App\Support\Training;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

/**
 * Training records for the hospital: which staff have passed the modules
 * required for their role. Evidence for DHA certification and ODPC audits.
 */
new #[Title('Training records')] class extends Component {
    public string $search = '';

    public bool $incompleteOnly = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('staff.manage') || Auth::user()->can('audit.view'), 403);
    }

    #[Computed]
    public function rows()
    {
        $tenant = app(TenantContext::class)->tenant();
        $staff = $tenant->users()->wherePivot('status', 'active')->orderBy('name')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->get();

        $attempts = TrainingAttempt::whereIn('user_id', $staff->pluck('id'))->get()->groupBy('user_id');
        $registrar = app(PermissionRegistrar::class);

        return $staff->map(function ($user) use ($attempts, $registrar, $tenant) {
            $registrar->setPermissionsTeamId($tenant->id);
            $user->unsetRelation('roles');
            $roles = $user->getRoleNames()->all();
            $required = array_keys(Training::requiredModules($roles));
            $mine = ($attempts->get($user->id) ?? collect())->groupBy('module');

            $modules = collect($required)->mapWithKeys(fn ($key) => [$key => [
                'passed' => (bool) $mine->get($key)?->contains('passed', true),
                'best' => $mine->get($key)?->max('percent'),
                'passed_at' => $mine->get($key)?->where('passed', true)->min('created_at'),
            ]]);
            $passed = $modules->where('passed', true)->count();

            return [
                'user' => $user,
                'roles' => $roles,
                'modules' => $modules,
                'passed' => $passed,
                'required' => count($required),
                'complete' => $passed === count($required),
            ];
        })->when($this->incompleteOnly, fn ($rows) => $rows->where('complete', false))->values();
    }

    #[Computed]
    public function summary(): array
    {
        $rows = $this->rows;

        return ['staff' => $rows->count(), 'complete' => $rows->where('complete', true)->count()];
    }

    public function export()
    {
        $rows = $this->rows;
        $titles = collect(Training::modules())->map(fn ($m) => $m['title']);

        return response()->streamDownload(function () use ($rows, $titles) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Roles', 'Required modules', 'Passed', 'Complete', 'Module', 'Module status', 'Best score %', 'Passed on']);
            foreach ($rows as $r) {
                foreach ($r['modules'] as $key => $m) {
                    fputcsv($out, [$r['user']->name, $r['user']->email, implode('; ', $r['roles']), $r['required'], $r['passed'], $r['complete'] ? 'yes' : 'no',
                        $titles[$key] ?? $key, $m['passed'] ? 'passed' : ($m['best'] !== null ? 'attempted' : 'not started'), $m['best'] ?? '', $m['passed_at'] ? \Illuminate\Support\Carbon::parse($m['passed_at'])->format('Y-m-d') : '']);
                }
            }
            fclose($out);
        }, 'training-records-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Training records') }}</flux:heading>
            <flux:text>{{ __('Staff progress on the modules required for their roles. Keep this as evidence of user training for DHA certification and data protection audits.') }}</flux:text>
        </div>
        <flux:button icon="arrow-down-tray" wire:click="export">{{ __('Export CSV') }}</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        @php
            $s = $this->summary;
            $pct = $s['staff'] ? (int) round($s['complete'] * 100 / $s['staff']) : 0;
        @endphp
        <div class="rounded-xl p-4 text-white" style="background: linear-gradient(135deg, #4E7CFF, #7033FF);">
            <div class="text-xs uppercase opacity-80">{{ __('Active staff shown') }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ $s['staff'] }}</div>
        </div>
        <div class="rounded-xl p-4 text-white" style="background: linear-gradient(135deg, #10B981, #059669);">
            <div class="text-xs uppercase opacity-80">{{ __('Fully trained') }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ $s['complete'] }}</div>
        </div>
        <div class="rounded-xl p-4 text-white" style="background: linear-gradient(135deg, #F59E0B, #F97316);">
            <div class="text-xs uppercase opacity-80">{{ __('Completion') }}</div>
            <div class="mt-1 text-2xl font-semibold">{{ $pct }}%</div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Name or email')" class="max-w-xs" />
        <flux:checkbox wire:model.live="incompleteOnly" :label="__('Only staff with training outstanding')" />
    </div>

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr>
                    <th class="px-3 py-2">{{ __('Staff member') }}</th>
                    <th class="px-3 py-2">{{ __('Role') }}</th>
                    <th class="px-3 py-2">{{ __('Required modules') }}</th>
                    <th class="px-3 py-2">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->rows as $r)
                    <tr wire:key="tr-{{ $r['user']->id }}">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $r['user']->name }}</div>
                            <div class="text-xs text-neutral-500">{{ $r['user']->email }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ implode(', ', $r['roles']) ?: '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($r['modules'] as $key => $m)
                                    <flux:badge size="sm" :color="$m['passed'] ? 'green' : ($m['best'] !== null ? 'amber' : 'zinc')"
                                                title="{{ $m['passed'] ? __('Passed :date', ['date' => \Illuminate\Support\Carbon::parse($m['passed_at'])->format('d M Y')]) : ($m['best'] !== null ? __('Best :pct%', ['pct' => $m['best']]) : __('Not started')) }}">
                                        {{ __(\App\Support\Training::module($key)['title']) }}{{ $m['best'] !== null ? ' · '.$m['best'].'%' : '' }}
                                    </flux:badge>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            @if ($r['complete'])
                                <flux:badge color="green" icon="check-circle">{{ __('Complete') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __(':p of :t passed', ['p' => $r['passed'], 't' => $r['required']]) }}</flux:badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-8 text-center text-neutral-500">{{ __('No staff match.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
