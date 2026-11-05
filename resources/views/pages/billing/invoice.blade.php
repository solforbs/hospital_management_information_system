<?php

use App\Models\Invoice;
use App\Models\ServiceItem;
use App\Services\Billing\BillingService;
use App\Services\Billing\InsuranceService;
use App\Services\Clinical\EncounterService;
use App\Support\EncounterStage;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice')] class extends Component {
    public Invoice $invoice;

    public string $amount = '';

    public string $method = 'cash';

    public string $reference = '';

    public ?int $service_item_id = null;

    public int $service_quantity = 1;

    public string $discount = '';

    public string $discount_reason = '';

    public string $void_reason = '';

    public string $preauth = '';

    public function mount(Invoice $invoice): void
    {
        abort_unless(Auth::user()->canAny(['billing.invoices.view', 'billing.payments.receive']), 403);
        $this->invoice = $invoice;
        $this->amount = (string) $invoice->balance;
    }

    protected function reload(BillingService $billing): void
    {
        $this->invoice = $billing->recalculate($this->invoice->fresh());
        $this->amount = (string) $this->invoice->balance;
        unset($this->items, $this->payments);
    }

    #[Computed]
    public function items()
    {
        return $this->invoice->items()->orderBy('created_at')->get();
    }

    #[Computed]
    public function payments()
    {
        return $this->invoice->payments()->with('receivedBy')->orderBy('received_at')->get();
    }

    #[Computed]
    public function services()
    {
        return ServiceItem::where('active', true)->orderBy('category')->orderBy('name')->get();
    }

    #[Computed]
    public function claims()
    {
        return $this->invoice->claims()->latest()->get();
    }

    public ?int $refundingId = null;

    public string $refund_amount = '';

    public string $refund_method = 'cash';

    public string $refund_reference = '';

    public string $refund_reason = '';

    public function refund(BillingService $billing): void
    {
        abort_unless(Auth::user()->can('billing.refunds.approve'), 403);
        $this->validate(['refund_amount' => ['required', 'numeric', 'min:0.01'], 'refund_method' => ['required', 'in:cash,mpesa,bank_transfer,card'], 'refund_reason' => ['required', 'string', 'max:255'], 'refund_reference' => ['nullable', 'string', 'max:60']]);

        $payment = $this->invoice->payments()->findOrFail($this->refundingId);
        $refund = $billing->refundPayment($payment, (float) $this->refund_amount, $this->refund_method, $this->refund_reference ?: null, $this->refund_reason);

        $this->reset(['refundingId', 'refund_amount', 'refund_reference', 'refund_reason']);
        $this->invoice->refresh();
        unset($this->payments);
        Flux::toast(variant: 'success', text: __('Refund :n recorded.', ['n' => $refund->refund_number]));
    }

    public function receive(BillingService $billing, EncounterService $encounters): void
    {
        abort_unless(Auth::user()->can('billing.payments.receive'), 403);

        $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:'.implode(',', array_keys(config('hms.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        if ($this->method === 'waiver' && ! Auth::user()->can('billing.discounts.approve')) {
            $this->addError('method', __('Waivers require discount approval rights.'));

            return;
        }

        $payment = $billing->receivePayment($this->invoice, (float) $this->amount, $this->method, $this->reference ?: null);
        $this->reset(['reference']);
        $this->reload($billing);

        // Settled cash visits waiting at billing are closed automatically.
        $encounter = $this->invoice->encounter;
        if ($this->invoice->status === 'paid' && $encounter?->isOpen() && $encounter->stage === EncounterStage::WAITING_BILLING) {
            $encounters->complete($encounter, __('Bill settled'));
        }

        Flux::toast(variant: 'success', text: __('Payment received — receipt :n', ['n' => $payment->receipt_number]));
        $this->dispatch('open-receipt', url: route('billing.receipt', $payment));
    }

    public function addService(BillingService $billing): void
    {
        abort_unless(Auth::user()->can('billing.invoices.create'), 403);
        abort_unless(in_array($this->invoice->status, ['open', 'partially_paid'], true), 422, __('Invoice is closed.'));

        $this->validate(['service_item_id' => ['required', 'integer'], 'service_quantity' => ['required', 'integer', 'min:1', 'max:1000']]);

        $service = ServiceItem::where('active', true)->findOrFail($this->service_item_id);

        $this->invoice->items()->create([
            'tenant_id' => $this->invoice->tenant_id,
            'category' => $service->category,
            'description' => $service->name,
            'quantity' => $this->service_quantity,
            'unit_price' => $service->price,
            'total' => round((float) $service->price * $this->service_quantity, 2),
            'added_by' => Auth::id(),
        ]);

        $this->reset(['service_item_id', 'service_quantity']);
        $this->reload($billing);
    }

    public function removeItem(int $id, BillingService $billing): void
    {
        abort_unless(Auth::user()->can('billing.refunds.approve') || Auth::user()->can('billing.discounts.approve'), 403);
        $billing->removeCharge($this->invoice->items()->findOrFail($id), __('Removed at cashier'));
        $this->reload($billing);
    }

    public function applyDiscount(BillingService $billing): void
    {
        abort_unless(Auth::user()->can('billing.discounts.approve'), 403);
        $this->validate(['discount' => ['required', 'numeric', 'min:0'], 'discount_reason' => ['required', 'string', 'max:255']]);
        $billing->applyDiscount($this->invoice, (float) $this->discount, $this->discount_reason);
        $this->reset(['discount', 'discount_reason']);
        $this->reload($billing);
    }

    public function voidInvoice(BillingService $billing): void
    {
        abort_unless(Auth::user()->can('billing.refunds.approve'), 403);
        $this->validate(['void_reason' => ['required', 'string', 'max:255']]);
        $billing->void($this->invoice, $this->void_reason);
        $this->reload($billing);
    }

    public function createClaim(InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $insurance->createClaim($this->invoice, $this->preauth ?: null);
        unset($this->claims);
        Flux::toast(variant: 'success', text: __('Claim created — submit it from the Insurance page.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4" x-data x-on:open-receipt.window="window.open($event.detail.url, '_blank')">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading level="1">{{ __('Invoice') }} <span class="font-mono">{{ $invoice->invoice_number }}</span></flux:heading>
            <flux:text>{{ $invoice->created_at->format('d M Y H:i') }} · {{ ucfirst($invoice->payer_type) }} · <flux:badge size="sm">{{ str_replace('_', ' ', $invoice->status) }}</flux:badge>
                @if ($invoice->encounter) · {{ $invoice->encounter->encounter_number }} @endif</flux:text>
        </div>
        <flux:button variant="ghost" :href="route('billing.index')" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    <x-patient-banner :patient="$invoice->patient" :encounter="$invoice->encounter" />

    @if ($invoice->insurance)
        <flux:callout icon="shield-check" :heading="__('Insured: :p — member :m', ['p' => $invoice->insurance->provider->name, 'm' => $invoice->insurance->member_number])" />
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr><th class="px-3 py-2">{{ __('Item') }}</th><th class="px-3 py-2">{{ __('Category') }}</th><th class="px-3 py-2 text-right">{{ __('Qty') }}</th><th class="px-3 py-2 text-right">{{ __('Unit') }}</th><th class="px-3 py-2 text-right">{{ __('Total') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($this->items as $item)
                            <tr wire:key="ii-{{ $item->id }}">
                                <td class="px-3 py-2">{{ $item->description }}<div class="text-xs text-neutral-500">{{ $item->created_at->format('d M H:i') }}</div></td>
                                <td class="px-3 py-2">{{ ucfirst($item->category) }}</td>
                                <td class="px-3 py-2 text-right">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                                <td class="px-3 py-2 text-right"><x-money :amount="$item->unit_price" /></td>
                                <td class="px-3 py-2 text-right"><x-money :amount="$item->total" /></td>
                                <td class="px-3 py-2 text-right">
                                    @if ($invoice->status !== 'paid' && $invoice->status !== 'void')
                                        @canany(['billing.refunds.approve', 'billing.discounts.approve'])
                                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeItem({{ $item->id }})" wire:confirm="{{ __('Remove this charge? This is audited.') }}" />
                                        @endcanany
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-neutral-500">{{ __('No charges yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="text-sm">
                        <tr><td colspan="4" class="px-3 py-1 text-right text-neutral-500">{{ __('Subtotal') }}</td><td class="px-3 py-1 text-right"><x-money :amount="$invoice->subtotal" /></td><td></td></tr>
                        @if ((float) $invoice->discount > 0)
                            <tr><td colspan="4" class="px-3 py-1 text-right text-neutral-500">{{ __('Discount') }} ({{ $invoice->discount_reason }})</td><td class="px-3 py-1 text-right">-<x-money :amount="$invoice->discount" /></td><td></td></tr>
                        @endif
                        <tr class="font-semibold"><td colspan="4" class="px-3 py-1 text-right">{{ __('Total') }}</td><td class="px-3 py-1 text-right"><x-money :amount="$invoice->total" /></td><td></td></tr>
                        <tr><td colspan="4" class="px-3 py-1 text-right text-neutral-500">{{ __('Paid') }}</td><td class="px-3 py-1 text-right"><x-money :amount="$invoice->amount_paid" /></td><td></td></tr>
                        <tr class="text-lg font-bold"><td colspan="4" class="px-3 py-2 text-right">{{ __('Balance') }}</td><td class="px-3 py-2 text-right"><x-money :amount="$invoice->balance" /></td><td></td></tr>
                    </tfoot>
                </table>
            </div>

            @if (in_array($invoice->status, ['open', 'partially_paid']))
                @can('billing.invoices.create')
                    <form wire:submit="addService" class="flex flex-wrap items-end gap-2">
                        <flux:select wire:model="service_item_id" :label="__('Add service')" class="min-w-72">
                            <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                            @foreach ($this->services as $s)
                                <flux:select.option value="{{ $s->id }}">{{ $s->name }} — {{ number_format((float) $s->price, 2) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input type="number" min="1" wire:model="service_quantity" :label="__('Qty')" class="w-20" />
                        <flux:button type="submit" size="sm">{{ __('Add') }}</flux:button>
                    </form>
                @endcan
            @endif

            @php
                $payTenant = app(\App\Services\Tenancy\TenantContext::class)->tenant();
                $pay = $payTenant?->setting('payments', []) ?? [];
            @endphp
            @if (array_filter($pay) || $payTenant?->setting('documents.invoice_terms'))
                <div class="rounded-xl border border-neutral-200 p-4 text-xs dark:border-neutral-700">
                    <div class="mb-1 font-semibold">{{ __('How to pay') }}</div>
                    @if (! empty($pay['mpesa_paybill']))<div>M-Pesa {{ __('Paybill') }} <strong>{{ $pay['mpesa_paybill'] }}</strong> · {{ __('Account') }} <strong>{{ $pay['mpesa_account'] ?: $invoice->invoice_number }}</strong></div>@endif
                    @if (! empty($pay['mpesa_till']))<div>M-Pesa {{ __('Till (Buy goods)') }} <strong>{{ $pay['mpesa_till'] }}</strong></div>@endif
                    @if (! empty($pay['bank_account_number']))<div>{{ $pay['bank_name'] }} {{ $pay['bank_branch'] }} · {{ $pay['bank_account_name'] }} · <strong>{{ $pay['bank_account_number'] }}</strong></div>@endif
                    @if ($terms = $payTenant?->setting('documents.invoice_terms'))<div class="mt-1 text-zinc-500">{{ $terms }}</div>@endif
                </div>
            @endif

            <div>
                <flux:heading level="3" class="mb-2">{{ __('Payments') }}</flux:heading>
                @forelse ($this->payments as $p)
                    <div wire:key="p-{{ $p->id }}" class="flex justify-between border-b border-neutral-100 py-1 text-sm dark:border-neutral-800">
                        <span>{{ $p->received_at->format('d M H:i') }} · <span class="font-mono">{{ $p->receipt_number }}</span> · {{ config('hms.payment_methods.'.$p->method, $p->method) }} {{ $p->reference }} · {{ $p->receivedBy?->name }}</span>
                        <span class="flex items-center gap-2"><x-money :amount="$p->amount" /> <a href="{{ route('billing.receipt', $p) }}" target="_blank" class="text-xs underline">{{ __('Receipt') }}</a>
                            @can('billing.refunds.approve')<button type="button" wire:click="$set('refundingId', {{ $p->id }})" class="text-xs text-red-600 underline">{{ __('Refund') }}</button>@endcan</span>
                    </div>
                @empty
                    <flux:text>{{ __('No payments yet.') }}</flux:text>
                @endforelse
                @foreach ($invoice->refunds()->with('approver')->get() as $rf)
                    <div wire:key="rf-{{ $rf->id }}" class="flex justify-between border-b border-neutral-100 py-1 text-sm text-red-600 dark:border-neutral-800">
                        <span>{{ $rf->created_at->format('d M H:i') }} · <span class="font-mono">{{ $rf->refund_number }}</span> · {{ __('refund') }} ({{ config('hms.payment_methods.'.$rf->method, $rf->method) }}) · {{ $rf->reason }}</span>
                        <span class="flex items-center gap-2">− <x-money :amount="$rf->amount" /> <a href="{{ route('billing.refund', $rf) }}" target="_blank" class="text-xs underline">{{ __('Voucher') }}</a></span>
                    </div>
                @endforeach
                @if ($refundingId)
                    <form wire:submit="refund" class="mt-3 grid gap-3 rounded-xl border border-red-300 p-3 dark:border-red-900">
                        <div class="text-sm font-semibold text-red-600">{{ __('Refund payment') }}</div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:input type="number" step="0.01" wire:model="refund_amount" :label="__('Amount')" />
                            <flux:select wire:model="refund_method" :label="__('Method')">
                                @foreach (['cash', 'mpesa', 'bank_transfer', 'card'] as $m)<flux:select.option value="{{ $m }}">{{ config('hms.payment_methods.'.$m, $m) }}</flux:select.option>@endforeach
                            </flux:select>
                            <flux:input wire:model="refund_reference" :label="__('Reference')" />
                        </div>
                        <flux:input wire:model="refund_reason" :label="__('Reason')" />
                        <div class="flex gap-2"><flux:button type="submit" size="sm" variant="danger">{{ __('Refund') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('refundingId', null)">{{ __('Cancel') }}</flux:button></div>
                    </form>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            @if (in_array($invoice->status, ['open', 'partially_paid']) && (float) $invoice->balance > 0)
                @can('billing.payments.receive')
                    <form wire:submit="receive" class="grid gap-3 rounded-xl border border-lime-400 p-4 dark:border-lime-800">
                        <flux:heading level="3">{{ __('Receive payment') }}</flux:heading>
                        <flux:input type="number" step="0.01" wire:model="amount" :label="__('Amount')" />
                        <flux:select wire:model.live="method" :label="__('Method')">
                            @foreach (config('hms.payment_methods') as $k => $l)
                                <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @if ($method !== 'cash')
                            <flux:input wire:model="reference" :label="$method === 'mpesa' ? __('M-Pesa code') : __('Reference')" />
                        @endif
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Receive & print receipt') }}</flux:button>
                    </form>
                @endcan
            @endif

            @if (in_array($invoice->status, ['open', 'partially_paid']))
                @can('billing.discounts.approve')
                    <form wire:submit="applyDiscount" class="grid gap-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:heading level="4">{{ __('Discount') }}</flux:heading>
                        <flux:input size="sm" type="number" step="0.01" wire:model="discount" :label="__('Amount')" />
                        <flux:input size="sm" wire:model="discount_reason" :label="__('Reason')" />
                        <flux:button size="sm" type="submit">{{ __('Apply') }}</flux:button>
                    </form>
                @endcan
            @endif

            @if ($invoice->patient_insurance_id && $invoice->status !== 'void')
                @can('insurance.claims.submit')
                    <div class="grid gap-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:heading level="4">{{ __('Insurance claim') }}</flux:heading>
                        @forelse ($this->claims as $c)
                            <div class="text-sm"><span class="font-mono">{{ $c->claim_number }}</span> — {{ $c->status }} (<x-money :amount="$c->amount_claimed" />)</div>
                        @empty
                            <flux:input size="sm" wire:model="preauth" :label="__('Pre-authorisation no.')" />
                            <flux:button size="sm" wire:click="createClaim">{{ __('Create claim') }}</flux:button>
                        @endforelse
                    </div>
                @endcan
            @endif

            @if ($invoice->status !== 'void' && (float) $invoice->amount_paid == 0)
                @can('billing.refunds.approve')
                    <form wire:submit="voidInvoice" class="grid gap-2 rounded-xl border border-red-300 p-4 dark:border-red-900">
                        <flux:heading level="4">{{ __('Void invoice') }}</flux:heading>
                        <flux:input size="sm" wire:model="void_reason" :label="__('Reason')" />
                        <flux:button size="sm" variant="danger" type="submit" wire:confirm="{{ __('Void this invoice?') }}">{{ __('Void') }}</flux:button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
</div>
