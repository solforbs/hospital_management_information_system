<?php

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    #[Computed]
    public function notifications()
    {
        $tenantId = app(TenantContext::class)->id();

        return Auth::user()->notifications()
            ->where('data->tenant_id', $tenantId)
            ->latest()
            ->limit(100)
            ->get();
    }

    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if ($url && str_starts_with($url, url('/'))) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->where('data->tenant_id', app(TenantContext::class)->id())->update(['read_at' => now()]);
        unset($this->notifications);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Notifications') }}</flux:heading>
        <flux:button size="sm" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
    </div>
    <div class="space-y-2">
        @forelse ($this->notifications as $n)
            @php $color = ['danger' => 'border-red-400', 'warning' => 'border-amber-400', 'success' => 'border-lime-400'][$n->data['level'] ?? 'info'] ?? 'border-neutral-200 dark:border-neutral-700'; @endphp
            <button type="button" wire:key="n-{{ $n->id }}" wire:click="open('{{ $n->id }}')" class="block w-full rounded-lg border-l-4 border-y border-r p-3 text-left text-sm {{ $color }} {{ $n->read_at ? 'opacity-60' : '' }}">
                <div class="flex justify-between"><span class="font-medium">{{ $n->data['title'] ?? '' }}</span><span class="text-xs text-neutral-500">{{ $n->created_at->diffForHumans() }}</span></div>
                <div class="text-neutral-600 dark:text-neutral-300">{{ $n->data['body'] ?? '' }}</div>
            </button>
        @empty
            <flux:text>{{ __('No notifications.') }}</flux:text>
        @endforelse
    </div>
</div>
