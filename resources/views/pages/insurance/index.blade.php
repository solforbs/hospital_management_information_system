<?php

use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Services\Billing\InsuranceService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Insurance')] class extends Component {
    #[Url]
    public string $tab = 'claims';

    public string $claimStatus = 'draft';

    public array $provider = ['name' => '', 'code' => '', 'type' => 'private', 'contact_email' => '', 'contact_phone' => '', 'requires_preauthorization' => false];

    public ?int $respondingId = null;

    public string $response_status = 'approved';

    public string $approved_amount = '';

    public string $rejection_reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
    }

    #[Computed]
    public function providers()
    {
        return InsuranceProvider::withCount('memberships')->orderBy('name')->get();
    }

    #[Computed]
    public function claims()
    {
        return InsuranceClaim::with(['invoice.patient', 'insurance.provider'])->where('status', $this->claimStatus)->latest()->limit(100)->get();
    }

    #[Computed]
    public function unclaimed()
    {
        return Invoice::with(['patient', 'insurance.provider'])
            ->whereNotNull('patient_insurance_id')
            ->whereIn('status', ['open', 'partially_paid'])
            ->where('balance', '>', 0)
            ->whereDoesntHave('claims', fn ($q) => $q->where('status', '!=', 'rejected'))
            ->latest()->limit(50)->get();
    }

    public function saveProvider(): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
        $data = $this->validate([
            'provider.name' => ['required', 'string', 'max:150'],
            'provider.code' => ['required', 'string', 'max:30', Rule::unique('insurance_providers', 'code')->where('tenant_id', app(TenantContext::class)->id())],
            'provider.type' => ['required', 'in:sha,private,corporate'],
            'provider.contact_email' => ['nullable', 'email'],
            'provider.contact_phone' => ['nullable', 'string', 'max:30'],
            'provider.requires_preauthorization' => ['boolean'],
        ])['provider'];
        $data['code'] = strtoupper($data['code']);
        InsuranceProvider::create($data);
        $this->reset('provider');
        unset($this->providers);
        Flux::toast(variant: 'success', text: __('Provider added.'));
    }

    public function toggleProvider(int $id): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
        $p = InsuranceProvider::findOrFail($id);
        $p->update(['active' => ! $p->active]);
        unset($this->providers);
    }

    public function createClaim(int $invoiceId, InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $insurance->createClaim(Invoice::findOrFail($invoiceId));
        unset($this->unclaimed, $this->claims);
    }

    public function submit(int $id, InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $insurance->submit(InsuranceClaim::findOrFail($id));
        unset($this->claims);
        Flux::toast(variant: 'success', text: __('Claim submitted.'));
    }

    public function respond(InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
        $this->validate([
            'response_status' => ['required', 'in:approved,partially_approved,rejected,paid'],
            'approved_amount' => ['nullable', 'numeric', 'min:0', 'required_unless:response_status,rejected'],
            'rejection_reason' => ['nullable', 'string', 'max:255', 'required_if:response_status,rejected'],
        ]);
        $insurance->recordResponse(InsuranceClaim::findOrFail($this->respondingId), $this->response_status, $this->approved_amount !== '' ? (float) $this->approved_amount : null, $this->rejection_reason ?: null);
        $this->reset(['respondingId', 'approved_amount', 'rejection_reason']);
        unset($this->claims);
        Flux::toast(variant: 'success', text: __('Claim updated.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Insurance') }}</flux:heading>

    <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['claims' => __('Claims'), 'unclaimed' => __('Insured invoices to claim'), 'providers' => __('Providers & schemes')] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'claims')
        <div class="flex flex-wrap gap-2">
            @foreach (['draft', 'submitted', 'approved', 'partially_approved', 'rejected', 'paid'] as $s)
                <flux:button size="sm" :variant="$claimStatus === $s ? 'primary' : 'ghost'" wire:click="$set('claimStatus', '{{ $s }}')">{{ str_replace('_', ' ', ucfirst($s)) }}</flux:button>
            @endforeach
        </div>

        @if ($respondingId)
            <form wire:submit="respond" class="grid max-w-xl gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                <flux:heading level="3">{{ __('Record payer response') }}</flux:heading>
                <flux:select wire:model.live="response_status" :label="__('Outcome')">
                    <flux:select.option value="approved">{{ __('Approved') }}</flux:select.option>
                    <flux:select.option value="partially_approved">{{ __('Partially approved') }}</flux:select.option>
                    <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
                    <flux:select.option value="paid">{{ __('Paid (posts payment to invoice)') }}</flux:select.option>
                </flux:select>
                @if ($response_status !== 'rejected')
                    <flux:input type="number" step="0.01" wire:model="approved_amount" :label="__('Approved amount')" />
                @else
                    <flux:input wire:model="rejection_reason" :label="__('Rejection reason')" />
                @endif
                <div class="flex gap-2"><flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('respondingId', null)">{{ __('Cancel') }}</flux:button></div>
            </form>
        @endif

        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Claim') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Payer') }}</th><th class="px-3 py-2 text-right">{{ __('Claimed') }}</th><th class="px-3 py-2 text-right">{{ __('Approved') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->claims as $c)
                        <tr wire:key="c-{{ $c->id }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $c->claim_number }}<div class="font-sans text-neutral-500">{{ $c->invoice->invoice_number }}</div></td>
                            <td class="px-3 py-2">{{ $c->invoice->patient->full_name }}</td>
                            <td class="px-3 py-2">{{ $c->insurance->provider->name }}<div class="text-xs text-neutral-500">{{ $c->insurance->member_number }}</div></td>
                            <td class="px-3 py-2 text-right"><x-money :amount="$c->amount_claimed" /></td>
                            <td class="px-3 py-2 text-right">{{ $c->amount_approved !== null ? number_format((float) $c->amount_approved, 2) : '—' }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if ($c->status === 'draft')
                                    @can('insurance.claims.submit')<flux:button size="xs" variant="primary" wire:click="submit({{ $c->id }})">{{ __('Submit') }}</flux:button>@endcan
                                @elseif (in_array($c->status, ['submitted', 'approved', 'partially_approved']))
                                    <flux:button size="xs" wire:click="$set('respondingId', {{ $c->id }})">{{ __('Record response') }}</flux:button>
                                @elseif ($c->status === 'rejected')
                                    <span class="text-xs text-red-600">{{ $c->rejection_reason }}</span>
                                @endif
                                <flux:button size="xs" variant="ghost" :href="route('billing.invoice', $c->invoice_id)" wire:navigate>{{ __('Invoice') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No claims.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'unclaimed')
        <div class="space-y-2">
            @forelse ($this->unclaimed as $inv)
                <div wire:key="u-{{ $inv->id }}" class="flex items-center justify-between rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                    <div>{{ $inv->patient->full_name }} · <span class="font-mono text-xs">{{ $inv->invoice_number }}</span><div class="text-xs text-neutral-500">{{ $inv->insurance->provider->name }} · {{ $inv->insurance->member_number }}</div></div>
                    <div class="flex items-center gap-3"><x-money :amount="$inv->balance" />
                        @can('insurance.claims.submit')<flux:button size="xs" variant="primary" wire:click="createClaim({{ $inv->id }})">{{ __('Create claim') }}</flux:button>@endcan
                    </div>
                </div>
            @empty
                <flux:text>{{ __('All insured invoices have claims.') }}</flux:text>
            @endforelse
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-2">
                @foreach ($this->providers as $p)
                    <div wire:key="ip-{{ $p->id }}" class="flex items-center justify-between rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700 {{ $p->active ? '' : 'opacity-50' }}">
                        <div>{{ $p->name }} <span class="font-mono text-xs text-neutral-500">{{ $p->code }}</span><div class="text-xs text-neutral-500">{{ strtoupper($p->type) }} · {{ $p->memberships_count }} {{ __('members') }} @if ($p->requires_preauthorization) · {{ __('pre-auth required') }} @endif</div></div>
                        <flux:button size="xs" variant="ghost" wire:click="toggleProvider({{ $p->id }})">{{ $p->active ? __('Deactivate') : __('Activate') }}</flux:button>
                    </div>
                @endforeach
            </div>
            <form wire:submit="saveProvider" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Add provider') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-2"><flux:input wire:model="provider.name" :label="__('Name')" /></div>
                    <flux:input wire:model="provider.code" :label="__('Code')" />
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:select wire:model="provider.type" :label="__('Type')">
                        <flux:select.option value="sha">SHA</flux:select.option>
                        <flux:select.option value="private">{{ __('Private') }}</flux:select.option>
                        <flux:select.option value="corporate">{{ __('Corporate') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="provider.contact_email" :label="__('Email')" />
                    <flux:input wire:model="provider.contact_phone" :label="__('Phone')" />
                </div>
                <flux:checkbox wire:model="provider.requires_preauthorization" :label="__('Requires pre-authorisation')" />
                <div><flux:button type="submit" variant="primary" size="sm">{{ __('Add') }}</flux:button></div>
            </form>
        </div>
    @endif
</div>
