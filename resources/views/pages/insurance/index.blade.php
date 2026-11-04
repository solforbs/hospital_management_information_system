<?php

use App\Models\InsuranceClaim;
use App\Models\InsuranceProvider;
use App\Models\Invoice;
use App\Services\Billing\InsuranceService;
use App\Services\Hie\HieException;
use App\Services\Hie\ShaClaimGateway;
use App\Services\Hie\ShaService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Insurance')] class extends Component {
    use \Livewire\WithFileUploads;

    #[Url]
    public string $tab = 'claims';

    // Claim documents
    public ?int $docsClaimId = null;

    public string $doc_type = 'discharge_summary';

    public $doc_file = null;

    // Remittance import
    public $remit_file = null;

    public string $remit_payer = 'SHA';

    public string $remit_reference = '';

    public ?string $remit_date = null;

    // Manual pre-auth responses
    public ?int $preauthRespondingId = null;

    public string $preauth_status = 'approved';

    public string $preauth_amount = '';

    public string $preauth_note = '';

    #[Computed]
    public function docsClaim()
    {
        return $this->docsClaimId ? InsuranceClaim::with('documents.uploader', 'invoice.patient')->find($this->docsClaimId) : null;
    }

    public function uploadDocument(): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $this->validate([
            'doc_type' => ['required', Rule::in(array_keys(\App\Models\ClaimDocument::TYPES))],
            'doc_file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ], [], ['doc_file' => 'document']);

        $claim = InsuranceClaim::findOrFail($this->docsClaimId);
        abort_if(in_array($claim->status, ['paid'], true), 422);

        $claim->documents()->create([
            'tenant_id' => $claim->tenant_id,
            'document_type' => $this->doc_type,
            'path' => $this->doc_file->store("tenants/{$claim->tenant_id}/claims/{$claim->id}", 'local'),
            'original_name' => mb_substr($this->doc_file->getClientOriginalName(), 0, 200),
            'mime' => $this->doc_file->getMimeType(),
            'size' => $this->doc_file->getSize(),
            'uploaded_by' => Auth::id(),
        ]);
        app(\App\Services\Audit\AuditLogger::class)->log('insurance.claim_document_added', $claim, ['type' => $this->doc_type]);

        $this->reset('doc_file');
        unset($this->docsClaim);
        Flux::toast(variant: 'success', text: __('Document attached.'));
    }

    public function removeDocument(int $id): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $doc = \App\Models\ClaimDocument::findOrFail($id);
        abort_if($doc->sent_at !== null, 422, __('Documents already sent to the payer cannot be removed.'));
        \Illuminate\Support\Facades\Storage::disk('local')->delete($doc->path);
        $doc->delete();
        unset($this->docsClaim);
    }

    #[Computed]
    public function preauths()
    {
        return \App\Models\Preauthorization::with(['patient', 'encounter'])->latest('id')->limit(100)->get();
    }

    public function respondPreauth(\App\Services\Billing\PreauthorizationService $service): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
        $this->validate([
            'preauth_status' => ['required', 'in:approved,partially_approved,rejected'],
            'preauth_amount' => ['nullable', 'numeric', 'min:0'],
            'preauth_note' => ['nullable', 'string', 'max:255'],
        ]);
        $service->recordResponse(\App\Models\Preauthorization::findOrFail($this->preauthRespondingId), $this->preauth_status, $this->preauth_amount !== '' ? (float) $this->preauth_amount : null, $this->preauth_note ?: null);
        $this->reset(['preauthRespondingId', 'preauth_amount', 'preauth_note']);
        unset($this->preauths);
    }

    public function refreshPreauth(int $id, \App\Services\Billing\PreauthorizationService $service): void
    {
        try {
            $service->refresh(\App\Models\Preauthorization::findOrFail($id));
        } catch (HieException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
        unset($this->preauths);
    }

    #[Computed]
    public function remittances()
    {
        return \App\Models\Remittance::with(['lines.claim'])->latest('id')->limit(30)->get();
    }

    public function importRemittance(\App\Services\Billing\RemittanceService $service): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);
        $this->validate([
            'remit_file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'remit_payer' => ['required', 'string', 'max:40'],
            'remit_reference' => ['required', 'string', 'max:80'],
            'remit_date' => ['nullable', 'date'],
        ], [], ['remit_file' => 'payment advice file']);

        $lines = $service->parseCsv(file_get_contents($this->remit_file->getRealPath()));
        $r = $service->ingest($this->remit_payer, $this->remit_reference, $this->remit_date, $lines, 'import');

        $this->reset(['remit_file', 'remit_reference', 'remit_date']);
        unset($this->remittances, $this->claims);
        Flux::toast(variant: $r->unmatched_count ? 'warning' : 'success', text: __(':m claim(s) paid, :u line(s) need attention.', ['m' => $r->matched_count, 'u' => $r->unmatched_count]));
    }

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
        return InsuranceClaim::with(['invoice.patient', 'insurance.provider'])->withCount('documents')->where('status', $this->claimStatus)->latest()->limit(100)->get();
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

    public ?int $shaClaimId = null;

    public string $discharge_otp = '';

    public string $discharge_reason = 'RECOVERED';

    public function submit(int $id, InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $claim = InsuranceClaim::findOrFail($id);

        // Electronic SHA claims need the member's discharge OTP first.
        if ($insurance->gatewayFor($claim)->key() === 'sha') {
            $this->reset(['discharge_otp', 'discharge_reason']);
            $this->shaClaimId = $claim->id;

            // A visit that ended in an outgoing referral is discharged as REFERRED.
            $encounterId = $claim->invoice?->encounter_id;
            if ($encounterId && \App\Models\Referral::where('encounter_id', $encounterId)->where('direction', 'outgoing')->whereNotIn('status', ['draft', 'cancelled'])->exists()) {
                $this->discharge_reason = 'REFERRED';
            }

            return;
        }

        $insurance->submit($claim);
        unset($this->claims);
        Flux::toast(variant: 'success', text: __('Claim submitted.'));
    }

    public function sendDischargeOtp(ShaService $sha): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $claim = InsuranceClaim::with('invoice.encounter')->findOrFail($this->shaClaimId);

        try {
            Flux::toast(text: $sha->requestDischargeOtp($claim->invoice->encounter));
        } catch (HieException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function submitSha(InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.claims.submit'), 403);
        $this->validate([
            'discharge_otp' => ['required', 'regex:/^[0-9]{4,8}$/'],
            'discharge_reason' => ['required', 'in:'.implode(',', array_keys(ShaClaimGateway::DISCHARGE_REASONS))],
        ], [], ['discharge_otp' => 'discharge OTP']);

        try {
            $insurance->submit(InsuranceClaim::findOrFail($this->shaClaimId), ['otp' => $this->discharge_otp, 'discharge_reason' => $this->discharge_reason]);
            $this->reset(['shaClaimId', 'discharge_otp']);
            Flux::toast(variant: 'success', text: __('Claim submitted to SHA.'));
        } catch (HieException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        unset($this->claims);
    }

    public function syncStatus(int $id, InsuranceService $insurance): void
    {
        abort_unless(Auth::user()->can('insurance.manage'), 403);

        try {
            $result = $insurance->syncStatus(InsuranceClaim::findOrFail($id));
            Flux::toast(text: $result ? __('SHA status: :s', ['s' => $result['external_status'] ?? '—']) : __('This claim is tracked manually.'));
        } catch (HieException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        unset($this->claims);
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
        @foreach (['claims' => __('Claims'), 'unclaimed' => __('Insured invoices to claim'), 'preauths' => __('Pre-authorisations'), 'remittances' => __('Remittances'), 'providers' => __('Providers & schemes')] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'claims')
        <div class="flex flex-wrap gap-2">
            @foreach (['draft', 'submitted', 'approved', 'partially_approved', 'rejected', 'paid'] as $s)
                <flux:button size="sm" :variant="$claimStatus === $s ? 'primary' : 'ghost'" wire:click="$set('claimStatus', '{{ $s }}')">{{ str_replace('_', ' ', ucfirst($s)) }}</flux:button>
            @endforeach
        </div>

        @error('claim')<flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror
        @error('sha')<flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror

        @if ($shaClaimId)
            <form wire:submit="submitSha" class="grid max-w-xl gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                <flux:heading level="3">{{ __('Submit to SHA') }}</flux:heading>
                <flux:text class="text-sm">{{ __('SHA requires the member to confirm discharge with an OTP before the claim is submitted. Lines, diagnoses and the attending doctor are sent automatically.') }}</flux:text>
                <div><flux:button size="sm" icon="device-phone-mobile" wire:click="sendDischargeOtp">{{ __('Send discharge OTP') }}</flux:button></div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="discharge_otp" :label="__('Discharge OTP')" inputmode="numeric" autocomplete="one-time-code" />
                    <flux:select wire:model="discharge_reason" :label="__('Discharge reason')">
                        @foreach (ShaClaimGateway::DISCHARGE_REASONS as $k => $l)
                            <flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                @error('otp')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                <div class="flex gap-2"><flux:button type="submit" variant="primary" size="sm">{{ __('Submit claim') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('shaClaimId', null)">{{ __('Cancel') }}</flux:button></div>
            </form>
        @endif

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
                            <td class="px-3 py-2 font-mono text-xs">{{ $c->claim_number }}<div class="font-sans text-neutral-500">{{ $c->invoice->invoice_number }}</div>
                                @if ($c->gateway === 'sha' && ($c->external_reference || $c->external_status))
                                    <div class="font-sans text-indigo-600">SHA {{ $c->external_reference }} · {{ $c->external_status }}</div>
                                @endif
                            </td>
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
                                @if ($c->gateway === 'sha' && ($c->status !== 'draft' || $c->external_status === 'SUBMIT_UNCONFIRMED') && ! in_array($c->status, ['paid', 'rejected']))
                                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="syncStatus({{ $c->id }})">{{ __('Check SHA status') }}</flux:button>
                                @endif
                                <flux:button size="xs" variant="ghost" icon="paper-clip" wire:click="$set('docsClaimId', {{ $c->id }})">{{ $c->documents_count ?? $c->documents()->count() }}</flux:button>
                                <flux:button size="xs" variant="ghost" :href="route('billing.invoice', $c->invoice_id)" wire:navigate>{{ __('Invoice') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No claims.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->docsClaim)
            <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <flux:heading level="3">{{ __('Supporting documents') }} — {{ $this->docsClaim->claim_number }} · {{ $this->docsClaim->invoice->patient->full_name }}</flux:heading>
                    <flux:button size="xs" variant="ghost" wire:click="$set('docsClaimId', null)">{{ __('Close') }}</flux:button>
                </div>
                @foreach ($this->docsClaim->documents as $d)
                    <div wire:key="cd-{{ $d->id }}" class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">
                        <span>{{ \App\Models\ClaimDocument::TYPES[$d->document_type] ?? $d->document_type }} · {{ $d->original_name }} <span class="text-xs text-zinc-500">({{ round($d->size / 1024) }} KB)</span></span>
                        @if ($d->sent_at)
                            <flux:badge size="sm" color="lime">{{ __('Sent') }} {{ $d->sent_at->format('d M H:i') }}</flux:badge>
                        @else
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeDocument({{ $d->id }})" />
                        @endif
                    </div>
                @endforeach
                @if ($this->docsClaim->status !== 'paid')
                    <form wire:submit="uploadDocument" class="flex flex-wrap items-end gap-3">
                        <flux:select wire:model="doc_type" :label="__('Type')" class="max-w-xs">
                            @foreach (\App\Models\ClaimDocument::TYPES as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                        </flux:select>
                        <input type="file" wire:model="doc_file" accept="application/pdf,image/png,image/jpeg" class="text-sm" />
                        <flux:button type="submit" size="sm" variant="primary">{{ __('Attach') }}</flux:button>
                    </form>
                    @error('doc_file')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    <flux:text class="text-xs">{{ __('PDF or image, up to 10 MB. Electronic SHA claims send these automatically on submission.') }}</flux:text>
                @endif
            </div>
        @endif
    @elseif ($tab === 'preauths')
        @if ($preauthRespondingId)
            <form wire:submit="respondPreauth" class="grid max-w-xl gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                <flux:heading level="3">{{ __('Record payer decision') }}</flux:heading>
                <flux:select wire:model="preauth_status" :label="__('Decision')">
                    <flux:select.option value="approved">{{ __('Approved') }}</flux:select.option>
                    <flux:select.option value="partially_approved">{{ __('Partially approved') }}</flux:select.option>
                    <flux:select.option value="rejected">{{ __('Rejected') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="preauth_amount" type="number" step="0.01" :label="__('Approved amount')" />
                <flux:input wire:model="preauth_note" :label="__('Note / approval reference')" />
                <div class="flex gap-2"><flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('preauthRespondingId', null)">{{ __('Cancel') }}</flux:button></div>
            </form>
        @endif
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Number') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Intervention') }}</th><th class="px-3 py-2 text-right">{{ __('Estimate') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->preauths as $p)
                        <tr wire:key="pa-{{ $p->id }}">
                            <td class="px-3 py-2 font-mono text-xs">{{ $p->preauth_number }}<div class="font-sans text-neutral-500">{{ strtoupper($p->gateway) }}</div></td>
                            <td class="px-3 py-2">{{ $p->patient->full_name }}<div class="text-xs text-neutral-500">{{ \Illuminate\Support\Str::limit($p->justification, 60) }}</div></td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $p->intervention_code ?? '—' }}</td>
                            <td class="px-3 py-2 text-right"><x-money :amount="$p->estimated_cost" />@if ($p->approved_amount !== null)<div class="text-xs text-lime-600">{{ __('approved') }} {{ number_format((float) $p->approved_amount, 2) }}</div>@endif</td>
                            <td class="px-3 py-2"><flux:badge size="sm" :color="$p->isApproved() ? 'lime' : ($p->status === 'rejected' ? 'red' : 'amber')">{{ str_replace('_', ' ', $p->status) }}</flux:badge></td>
                            <td class="px-3 py-2 text-right">
                                @if ($p->status === 'submitted' && $p->gateway === 'sha')<flux:button size="xs" icon="arrow-path" wire:click="refreshPreauth({{ $p->id }})">{{ __('Check SHA') }}</flux:button>@endif
                                @if ($p->status === 'submitted' && $p->gateway === 'manual')<flux:button size="xs" wire:click="$set('preauthRespondingId', {{ $p->id }})">{{ __('Record decision') }}</flux:button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No pre-authorisations. They are requested from the SHA verification page of a visit.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'remittances')
        <form wire:submit="importRemittance" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Import payment advice') }}</flux:heading>
            <flux:text class="text-xs">{{ __('CSV with a claim reference column (claim no., SHA invoice no. or provider claim no.) and an amount column. Each line is matched to its claim and the payment posted automatically. SHA can also push remittances to the callback URL shown on the integration page.') }}</flux:text>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="remit_payer" :label="__('Payer')" />
                <flux:input wire:model="remit_reference" :label="__('Payment / batch reference')" />
                <flux:input wire:model="remit_date" type="date" :label="__('Paid on')" />
            </div>
            <input type="file" wire:model="remit_file" accept=".csv,text/csv" class="text-sm" />
            @error('remit_file')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            @error('file')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            @error('remittance')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div><flux:button type="submit" size="sm" variant="primary">{{ __('Import and match') }}</flux:button></div>
        </form>
        <div class="space-y-3">
            @forelse ($this->remittances as $r)
                <details wire:key="rm-{{ $r->id }}" class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                    <summary class="cursor-pointer">
                        <span class="font-medium">{{ $r->payer }} · {{ $r->reference }}</span> · {{ $r->paid_on?->format('d M Y') }} · <x-money :amount="$r->total_amount" />
                        · <span class="text-lime-600">{{ $r->matched_count }} {{ __('matched') }}</span> @if ($r->unmatched_count) · <span class="text-red-600">{{ $r->unmatched_count }} {{ __('unmatched') }}</span>@endif
                        <span class="text-xs text-neutral-500">({{ $r->source }})</span>
                    </summary>
                    <div class="mt-2 space-y-1">
                        @foreach ($r->lines as $l)
                            <div class="flex justify-between text-xs">
                                <span class="font-mono">{{ $l->claim_reference }}</span>
                                <span>{{ number_format((float) $l->amount, 2) }} · <span class="{{ $l->status === 'matched' ? 'text-lime-600' : 'text-red-600' }}">{{ str_replace('_', ' ', $l->status) }}</span>@if ($l->note) — {{ $l->note }}@endif</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty
                <flux:text>{{ __('No remittances yet.') }}</flux:text>
            @endforelse
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
