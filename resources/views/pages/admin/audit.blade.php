<?php

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    #[Url]
    public string $action = '';

    #[Url]
    public string $user = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('audit.view'), 403);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function actions()
    {
        return AuditLog::select('action')->distinct()->orderBy('action')->pluck('action');
    }

    #[Computed]
    public function logs()
    {
        return AuditLog::with('user')
            ->when($this->action, fn ($q) => $q->where('action', $this->action))
            ->when($this->user, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->user}%")->orWhere('email', 'like', "%{$this->user}%")))
            ->latest('created_at')
            ->paginate(50);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Audit log') }}</flux:heading>
    <div class="flex flex-wrap gap-3">
        <flux:select wire:model.live="action" class="max-w-xs">
            <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
            @foreach ($this->actions as $a)
                <flux:select.option value="{{ $a }}">{{ $a }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.400ms="user" icon="magnifying-glass" :placeholder="__('User name or email')" class="max-w-xs" />
    </div>
    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('When') }}</th><th class="px-3 py-2">{{ __('User') }}</th><th class="px-3 py-2">{{ __('Action') }}</th><th class="px-3 py-2">{{ __('Record') }}</th><th class="px-3 py-2">{{ __('Details') }}</th><th class="px-3 py-2">IP</th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->logs as $log)
                    <tr wire:key="al-{{ $log->id }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="px-3 py-2">{{ $log->user?->name ?? __('system') }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $log->action }}</td>
                        <td class="px-3 py-2 text-xs">{{ class_basename((string) $log->auditable_type) }} {{ $log->auditable_id ? '#'.$log->auditable_id : '' }}</td>
                        <td class="px-3 py-2 text-xs text-neutral-500">{{ $log->properties ? json_encode($log->properties) : '' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No entries.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->logs->links() }}
</div>
