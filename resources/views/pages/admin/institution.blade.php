<?php

use App\Models\Signatory;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Institution profile')] class extends Component {
    use WithFileUploads;

    #[Url]
    public string $tab = 'profile';

    public array $profile = [];

    public array $contacts = [];

    public array $brand = ['primary_color' => '#4E7CFF', 'secondary_color' => '#7033FF'];

    public array $docs = [];

    public $logo = null;

    public $stamp = null;

    // Signatory form
    public ?int $editingSignatory = null;

    public array $sig = [];

    public $signature = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);
        $this->fillFromTenant();
        $this->resetSignatoryForm();
    }

    protected function tenantModel(): Tenant
    {
        return app(TenantContext::class)->tenant()->fresh();
    }

    protected function fillFromTenant(): void
    {
        $t = $this->tenantModel();

        $this->profile = collect($t->only(['name', 'short_name', 'legal_name', 'motto', 'type', 'ownership', 'registration_number', 'license_number', 'kra_pin', 'nhif_sha_code']))->map(fn ($v) => (string) $v)->all();
        $this->contacts = collect($t->only(['email', 'phone', 'phone_alt', 'emergency_phone', 'website', 'address', 'po_box', 'town', 'county', 'sub_county', 'country']))->map(fn ($v) => (string) $v)->all();
        $this->brand = ['primary_color' => $t->brandColor('primary'), 'secondary_color' => $t->brandColor('secondary')];

        $s = $t->settings ?? [];
        $this->docs = [
            'receipt_footer' => (string) data_get($s, 'documents.receipt_footer', 'Thank you. Get well soon.'),
            'invoice_terms' => (string) data_get($s, 'documents.invoice_terms', ''),
            'document_footer' => (string) data_get($s, 'documents.document_footer', ''),
            'working_hours' => (string) data_get($s, 'documents.working_hours', ''),
            'bank_name' => (string) data_get($s, 'payments.bank_name', ''),
            'bank_branch' => (string) data_get($s, 'payments.bank_branch', ''),
            'bank_account_name' => (string) data_get($s, 'payments.bank_account_name', ''),
            'bank_account_number' => (string) data_get($s, 'payments.bank_account_number', ''),
            'mpesa_paybill' => (string) data_get($s, 'payments.mpesa_paybill', ''),
            'mpesa_account' => (string) data_get($s, 'payments.mpesa_account', ''),
            'mpesa_till' => (string) data_get($s, 'payments.mpesa_till', ''),
            'show_logo_on_documents' => (bool) data_get($s, 'documents.show_logo', true),
            'show_stamp_on_documents' => (bool) data_get($s, 'documents.show_stamp', false),
        ];
    }

    protected function resetSignatoryForm(): void
    {
        $this->editingSignatory = null;
        $this->signature = null;
        $this->sig = ['name' => '', 'title' => '', 'qualifications' => '', 'registration' => '', 'user_id' => '', 'documents' => [], 'sort_order' => '0', 'active' => true];
    }

    #[Computed]
    public function tenant(): Tenant
    {
        return $this->tenantModel();
    }

    #[Computed]
    public function signatories()
    {
        return Signatory::with('user')->orderBy('sort_order')->orderBy('id')->get();
    }

    #[Computed]
    public function staff()
    {
        return app(TenantContext::class)->tenant()->users()->orderBy('name')->get(['users.id', 'users.name', 'users.regulator', 'users.registration_number']);
    }

    /** Percentage of key profile fields filled (encourages completeness). */
    #[Computed]
    public function completeness(): int
    {
        $t = $this->tenant;
        $fields = ['name', 'legal_name', 'registration_number', 'license_number', 'kra_pin', 'email', 'phone', 'address', 'county', 'logo_path'];
        $filled = collect($fields)->filter(fn ($f) => filled($t->{$f}))->count() + ($this->signatories->isNotEmpty() ? 1 : 0);

        return (int) round($filled / (count($fields) + 1) * 100);
    }

    protected function audit(string $action, array $props = []): void
    {
        app(AuditLogger::class)->log($action, $this->tenantModel(), $props);
    }

    public function saveProfile(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);

        $data = $this->validate([
            'profile.name' => ['required', 'string', 'max:150'],
            'profile.short_name' => ['nullable', 'string', 'max:40'],
            'profile.legal_name' => ['nullable', 'string', 'max:200'],
            'profile.motto' => ['nullable', 'string', 'max:150'],
            'profile.type' => ['required', 'in:hospital,clinic,medical_centre,nursing_home,health_centre,dispensary'],
            'profile.ownership' => ['nullable', 'in:public,county,private,faith_based,ngo,armed_forces'],
            'profile.registration_number' => ['nullable', 'string', 'max:60'],
            'profile.license_number' => ['nullable', 'string', 'max:60'],
            'profile.kra_pin' => ['nullable', 'string', 'regex:/^[AP][0-9]{9}[A-Z]$/i'],
            'profile.nhif_sha_code' => ['nullable', 'string', 'max:60'],
        ], [], ['profile.kra_pin' => 'KRA PIN'])['profile'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['kra_pin'] = $data['kra_pin'] ? strtoupper($data['kra_pin']) : null;
        $this->tenantModel()->update($data);
        $this->audit('institution.profile_updated');
        unset($this->tenant, $this->completeness);
        Flux::toast(variant: 'success', text: __('Institution profile saved.'));
    }

    public function saveContacts(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);

        $data = $this->validate([
            'contacts.email' => ['nullable', 'email', 'max:150'],
            'contacts.phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s()-]+$/'],
            'contacts.phone_alt' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s()-]+$/'],
            'contacts.emergency_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s()-]+$/'],
            'contacts.website' => ['nullable', 'url', 'max:150'],
            'contacts.address' => ['nullable', 'string', 'max:200'],
            'contacts.po_box' => ['nullable', 'string', 'max:40'],
            'contacts.town' => ['nullable', 'string', 'max:80'],
            'contacts.county' => ['nullable', 'string', 'max:80'],
            'contacts.sub_county' => ['nullable', 'string', 'max:80'],
            'contacts.country' => ['required', 'string', 'max:80'],
        ])['contacts'];

        $this->tenantModel()->update(array_map(fn ($v) => $v === '' ? null : $v, $data));
        $this->audit('institution.contacts_updated');
        unset($this->tenant, $this->completeness);
        Flux::toast(variant: 'success', text: __('Contact details saved.'));
    }

    public function saveBranding(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);

        $this->validate([
            'brand.primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand.secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // SVG is excluded on purpose: it can carry scripts.
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
            'stamp' => ['nullable', 'image', 'mimes:png,webp', 'max:1024'],
        ], [], ['logo' => 'logo', 'stamp' => 'stamp']);

        $tenant = $this->tenantModel();
        $updates = ['branding' => array_merge($tenant->branding ?? [], [
            'primary_color' => strtoupper($this->brand['primary_color']),
            'secondary_color' => strtoupper($this->brand['secondary_color']),
        ])];

        if ($this->logo) {
            $updates['logo_path'] = $this->logo->store("tenants/{$tenant->id}/branding", 'local');
            $this->deleteFile($tenant->logo_path);
        }

        if ($this->stamp) {
            $updates['stamp_path'] = $this->stamp->store("tenants/{$tenant->id}/branding", 'local');
            $this->deleteFile($tenant->stamp_path);
        }

        $tenant->update($updates);
        $this->reset(['logo', 'stamp']);
        $this->audit('institution.branding_updated', ['logo' => isset($updates['logo_path']), 'stamp' => isset($updates['stamp_path'])]);
        unset($this->tenant, $this->completeness);
        Flux::toast(variant: 'success', text: __('Branding saved. Refresh to see the new logo in the sidebar.'));
    }

    public function removeLogo(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);
        $tenant = $this->tenantModel();
        $this->deleteFile($tenant->logo_path);
        $tenant->update(['logo_path' => null]);
        $this->audit('institution.logo_removed');
        unset($this->tenant, $this->completeness);
    }

    public function removeStamp(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);
        $tenant = $this->tenantModel();
        $this->deleteFile($tenant->stamp_path);
        $tenant->update(['stamp_path' => null]);
        unset($this->tenant);
    }

    public function saveDocuments(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);

        $d = $this->validate([
            'docs.receipt_footer' => ['nullable', 'string', 'max:300'],
            'docs.invoice_terms' => ['nullable', 'string', 'max:1000'],
            'docs.document_footer' => ['nullable', 'string', 'max:300'],
            'docs.working_hours' => ['nullable', 'string', 'max:150'],
            'docs.bank_name' => ['nullable', 'string', 'max:100'],
            'docs.bank_branch' => ['nullable', 'string', 'max:100'],
            'docs.bank_account_name' => ['nullable', 'string', 'max:150'],
            'docs.bank_account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z\s-]+$/'],
            'docs.mpesa_paybill' => ['nullable', 'digits_between:5,7'],
            'docs.mpesa_account' => ['nullable', 'string', 'max:40'],
            'docs.mpesa_till' => ['nullable', 'digits_between:5,8'],
            'docs.show_logo_on_documents' => ['boolean'],
            'docs.show_stamp_on_documents' => ['boolean'],
        ], [], ['docs.mpesa_paybill' => 'M-Pesa paybill', 'docs.mpesa_till' => 'M-Pesa till'])['docs'];

        $tenant = $this->tenantModel();
        $settings = $tenant->settings ?? [];
        $settings['documents'] = [
            'receipt_footer' => $d['receipt_footer'] ?: null,
            'invoice_terms' => $d['invoice_terms'] ?: null,
            'document_footer' => $d['document_footer'] ?: null,
            'working_hours' => $d['working_hours'] ?: null,
            'show_logo' => (bool) $d['show_logo_on_documents'],
            'show_stamp' => (bool) $d['show_stamp_on_documents'],
        ];
        $settings['payments'] = collect($d)->only(['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_number', 'mpesa_paybill', 'mpesa_account', 'mpesa_till'])->map(fn ($v) => $v ?: null)->all();

        $tenant->update(['settings' => $settings]);
        $this->audit('institution.documents_updated');
        unset($this->tenant);
        Flux::toast(variant: 'success', text: __('Document and payment settings saved.'));
    }

    public function editSignatory(int $id): void
    {
        $s = Signatory::findOrFail($id);
        $this->editingSignatory = $s->id;
        $this->signature = null;
        $this->sig = [
            'name' => $s->name, 'title' => $s->title, 'qualifications' => (string) $s->qualifications, 'registration' => (string) $s->registration,
            'user_id' => (string) ($s->user_id ?? ''), 'documents' => $s->documents ?? [], 'sort_order' => (string) $s->sort_order, 'active' => $s->active,
        ];
    }

    public function cancelSignatory(): void
    {
        $this->resetSignatoryForm();
    }

    /** Pre-fill a signatory from a staff member (name + licence). */
    public function updatedSigUserId($value): void
    {
        $user = $value !== '' ? $this->staff->firstWhere('id', (int) $value) : null;
        if ($user && $this->sig['name'] === '') {
            $this->sig['name'] = $user->name;
        }
        if ($user && $this->sig['registration'] === '' && $user->registration_number) {
            $this->sig['registration'] = trim($user->regulator.' '.$user->registration_number);
        }
    }

    public function saveSignatory(): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);

        $data = $this->validate([
            'sig.name' => ['required', 'string', 'max:120'],
            'sig.title' => ['required', 'string', 'max:120'],
            'sig.qualifications' => ['nullable', 'string', 'max:150'],
            'sig.registration' => ['nullable', 'string', 'max:80'],
            'sig.user_id' => ['nullable', 'integer'],
            'sig.documents' => ['array'],
            'sig.documents.*' => [Rule::in(array_keys(Tenant::DOCUMENTS))],
            'sig.sort_order' => ['required', 'integer', 'between:0,99'],
            'sig.active' => ['boolean'],
            'signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512'],
        ], [], ['sig.name' => 'name', 'sig.title' => 'title'])['sig'];

        // A linked user must be a member of this institution.
        if (($data['user_id'] ?? '') !== '' && ! $this->staff->contains('id', (int) $data['user_id'])) {
            $this->addError('sig.user_id', __('Choose a member of this institution.'));

            return;
        }

        $attrs = [
            'name' => $data['name'],
            'title' => $data['title'],
            'qualifications' => $data['qualifications'] ?: null,
            'registration' => $data['registration'] ?: null,
            'user_id' => ($data['user_id'] ?? '') !== '' ? (int) $data['user_id'] : null,
            'documents' => array_values($data['documents'] ?? []),
            'sort_order' => (int) $data['sort_order'],
            'active' => (bool) $data['active'],
        ];

        $signatory = $this->editingSignatory ? Signatory::findOrFail($this->editingSignatory) : new Signatory;

        if ($this->signature) {
            $tenantId = app(TenantContext::class)->id();
            $old = $signatory->signature_path;
            $attrs['signature_path'] = $this->signature->store("tenants/{$tenantId}/signatures", 'local');
            $this->deleteFile($old);
        }

        $signatory->fill($attrs)->save();
        $this->audit($this->editingSignatory ? 'institution.signatory_updated' : 'institution.signatory_added', ['signatory' => $signatory->name]);

        $this->resetSignatoryForm();
        unset($this->signatories, $this->completeness);
        Flux::toast(variant: 'success', text: __('Signatory saved.'));
    }

    public function deleteSignatory(int $id): void
    {
        abort_unless(Auth::user()->can('settings.manage'), 403);
        $s = Signatory::findOrFail($id);
        $this->deleteFile($s->signature_path);
        $this->audit('institution.signatory_removed', ['signatory' => $s->name]);
        $s->delete();
        if ($this->editingSignatory === $id) {
            $this->resetSignatoryForm();
        }
        unset($this->signatories, $this->completeness);
    }

    protected function deleteFile(?string $path): void
    {
        if ($path && str_starts_with($path, 'tenants/'.app(TenantContext::class)->id().'/')) {
            Storage::disk('local')->delete($path);
        }
    }
};
?>

@php
    $t = $this->tenant;
@endphp
<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading level="1">{{ __('Institution profile') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your identity across the system: name, logo, statutory numbers, contacts, payment details and the people who sign your documents.') }}</flux:text>
        </div>
        <div class="w-56">
            <div class="flex justify-between text-xs"><span class="text-zinc-500">{{ __('Profile complete') }}</span><span class="font-semibold">{{ $this->completeness }}%</span></div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200"><div class="h-full rounded-full" style="width: {{ $this->completeness }}%; background: linear-gradient(90deg, var(--page-accent), var(--page-accent-2))"></div></div>
        </div>
    </div>

    <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['profile' => __('Identity'), 'contacts' => __('Contacts & location'), 'branding' => __('Logo & colours'), 'documents' => __('Documents & payments'), 'signatories' => __('Signatories').' ('.$this->signatories->count().')'] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-5">
        <div class="xl:col-span-3">
            @if ($tab === 'profile')
                <form wire:submit="saveProfile" class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Identity') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2"><flux:input wire:model="profile.name" :label="__('Institution name')" /></div>
                        <flux:input wire:model="profile.short_name" :label="__('Short name')" :placeholder="__('e.g. NGH')" :description="__('Shown in the sidebar')" />
                    </div>
                    <flux:input wire:model="profile.legal_name" :label="__('Registered (legal) name')" />
                    <flux:input wire:model="profile.motto" :label="__('Motto / tagline')" :placeholder="__('e.g. Quality care, close to home')" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:select wire:model="profile.type" :label="__('Institution type')">
                            @foreach (['hospital' => 'Hospital', 'medical_centre' => 'Medical centre', 'health_centre' => 'Health centre', 'clinic' => 'Clinic', 'dispensary' => 'Dispensary', 'nursing_home' => 'Nursing / maternity home'] as $k => $l)
                                <flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="profile.ownership" :label="__('Ownership')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (['public' => 'Public (national)', 'county' => 'County government', 'private' => 'Private', 'faith_based' => 'Faith-based', 'ngo' => 'NGO', 'armed_forces' => 'Armed forces'] as $k => $l)
                                <flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:heading level="3">{{ __('Registration & statutory numbers') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="profile.registration_number" :label="__('Business registration no.')" />
                        <flux:input wire:model="profile.license_number" :label="__('KMPDC facility licence no.')" />
                        <flux:input wire:model="profile.kra_pin" :label="__('KRA PIN')" placeholder="P051234567X" />
                        <flux:input wire:model="profile.nhif_sha_code" :label="__('SHA provider / contract code')" />
                    </div>
                    <div><flux:button type="submit" variant="primary">{{ __('Save identity') }}</flux:button></div>
                </form>
            @elseif ($tab === 'contacts')
                <form wire:submit="saveContacts" class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Contacts') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="contacts.phone" :label="__('Main phone')" />
                        <flux:input wire:model="contacts.phone_alt" :label="__('Alternative phone')" />
                        <flux:input wire:model="contacts.emergency_phone" :label="__('Emergency / ambulance line')" />
                        <flux:input wire:model="contacts.email" type="email" :label="__('Email')" />
                        <div class="sm:col-span-2"><flux:input wire:model="contacts.website" :label="__('Website')" placeholder="https://" /></div>
                    </div>
                    <flux:heading level="3">{{ __('Location') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2"><flux:input wire:model="contacts.address" :label="__('Physical address / street')" /></div>
                        <flux:input wire:model="contacts.po_box" :label="__('P.O. Box')" placeholder="12345-00100" />
                        <flux:input wire:model="contacts.town" :label="__('Town')" />
                        <flux:input wire:model="contacts.county" :label="__('County')" />
                        <flux:input wire:model="contacts.sub_county" :label="__('Sub-county')" />
                        <flux:input wire:model="contacts.country" :label="__('Country')" />
                    </div>
                    <div><flux:button type="submit" variant="primary">{{ __('Save contacts') }}</flux:button></div>
                </form>
            @elseif ($tab === 'branding')
                <form wire:submit="saveBranding" class="grid gap-5 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Logo') }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-5">
                        <div class="flex size-24 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-zinc-300 bg-white">
                            @if ($logo)
                                <img src="{{ $logo->temporaryUrl() }}" class="max-h-full max-w-full object-contain" alt="" />
                            @elseif ($t->logoUrl())
                                <img src="{{ $t->logoUrl() }}" class="max-h-full max-w-full object-contain" alt="{{ $t->name }}" />
                            @else
                                <x-app-logo-icon class="size-14" />
                            @endif
                        </div>
                        <div class="space-y-2 text-sm">
                            <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block text-sm" />
                            <flux:text class="text-xs">{{ __('PNG, JPG or WebP, up to 1 MB. A square logo on a transparent background works best.') }}</flux:text>
                            <div wire:loading wire:target="logo" class="text-xs text-zinc-500">{{ __('Uploading…') }}</div>
                            @error('logo')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            @if ($t->logo_path)<flux:button size="xs" variant="ghost" wire:click="removeLogo" wire:confirm="{{ __('Remove the logo?') }}">{{ __('Remove logo (use system logo)') }}</flux:button>@endif
                        </div>
                    </div>

                    <flux:heading level="3">{{ __('Official stamp') }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-5">
                        <div class="flex h-24 w-32 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-zinc-300 bg-white">
                            @if ($stamp)
                                <img src="{{ $stamp->temporaryUrl() }}" class="max-h-full max-w-full object-contain" alt="" />
                            @elseif ($t->stampUrl())
                                <img src="{{ $t->stampUrl() }}" class="max-h-full max-w-full object-contain" alt="" />
                            @else
                                <span class="text-xs text-zinc-400">{{ __('No stamp') }}</span>
                            @endif
                        </div>
                        <div class="space-y-2 text-sm">
                            <input type="file" wire:model="stamp" accept="image/png,image/webp" class="block text-sm" />
                            <flux:text class="text-xs">{{ __('Transparent PNG or WebP, up to 1 MB. Printed on documents when enabled under Documents & payments.') }}</flux:text>
                            @error('stamp')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            @if ($t->stamp_path)<flux:button size="xs" variant="ghost" wire:click="removeStamp">{{ __('Remove stamp') }}</flux:button>@endif
                        </div>
                    </div>

                    <flux:heading level="3">{{ __('Brand colours') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['primary_color' => __('Primary colour'), 'secondary_color' => __('Secondary colour')] as $k => $l)
                            <div>
                                <div class="mb-2 text-sm font-medium">{{ $l }}</div>
                                <div class="flex items-center gap-2">
                                    <input type="color" wire:model.live="brand.{{ $k }}" class="h-10 w-14 cursor-pointer rounded border border-zinc-200 bg-white" />
                                    <flux:input wire:model.live="brand.{{ $k }}" class="flex-1" />
                                </div>
                                @error('brand.'.$k)<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            </div>
                        @endforeach
                    </div>
                    <flux:text class="text-xs">{{ __('Used on letterheads, receipts and the dashboard banner.') }}</flux:text>
                    <div><flux:button type="submit" variant="primary">{{ __('Save branding') }}</flux:button></div>
                </form>
            @elseif ($tab === 'documents')
                <form wire:submit="saveDocuments" class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Printed documents') }}</flux:heading>
                    <flux:input wire:model="docs.receipt_footer" :label="__('Receipt footer message')" />
                    <flux:textarea wire:model="docs.invoice_terms" rows="2" :label="__('Invoice payment terms')" :placeholder="__('e.g. Payable on discharge. Cheques payable to …')" />
                    <flux:input wire:model="docs.document_footer" :label="__('Footer on letters and reports')" :placeholder="__('e.g. ISO 9001:2015 certified · This is a computer-generated document')" />
                    <flux:input wire:model="docs.working_hours" :label="__('Working hours')" :placeholder="__('e.g. OPD Mon–Sat 8am–5pm · Emergency 24/7')" />
                    <div class="flex flex-wrap gap-6">
                        <flux:checkbox wire:model="docs.show_logo_on_documents" :label="__('Show logo on documents')" />
                        <flux:checkbox wire:model="docs.show_stamp_on_documents" :label="__('Show official stamp on documents')" />
                    </div>

                    <flux:heading level="3">{{ __('Payment details (printed on invoices)') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input wire:model="docs.mpesa_paybill" :label="__('M-Pesa paybill')" />
                        <flux:input wire:model="docs.mpesa_account" :label="__('Paybill account (e.g. patient no.)')" />
                        <flux:input wire:model="docs.mpesa_till" :label="__('M-Pesa till (Buy goods)')" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="docs.bank_name" :label="__('Bank')" />
                        <flux:input wire:model="docs.bank_branch" :label="__('Branch')" />
                        <flux:input wire:model="docs.bank_account_name" :label="__('Account name')" />
                        <flux:input wire:model="docs.bank_account_number" :label="__('Account number')" />
                    </div>
                    <div><flux:button type="submit" variant="primary">{{ __('Save documents & payments') }}</flux:button></div>
                </form>
            @else
                <div class="space-y-4">
                    <form wire:submit="saveSignatory" class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                        <flux:heading level="3">{{ $editingSignatory ? __('Edit signatory') : __('Add signatory') }}</flux:heading>
                        <flux:select wire:model.live="sig.user_id" :label="__('Link to a staff account (optional)')">
                            <flux:select.option value="">{{ __('Not linked') }}</flux:select.option>
                            @foreach ($this->staff as $u)
                                <flux:select.option value="{{ $u->id }}">{{ $u->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('sig.user_id')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="sig.name" :label="__('Full name')" />
                            <flux:input wire:model="sig.title" :label="__('Title / designation')" :placeholder="__('e.g. Medical Superintendent')" />
                            <flux:input wire:model="sig.qualifications" :label="__('Qualifications')" :placeholder="__('e.g. MBChB, MMed (Surgery)')" />
                            <flux:input wire:model="sig.registration" :label="__('Registration')" :placeholder="__('e.g. KMPDC A1234')" />
                        </div>
                        <div>
                            <div class="mb-2 text-sm font-medium">{{ __('Signs these documents') }}</div>
                            <div class="grid gap-1 sm:grid-cols-2">
                                @foreach (\App\Models\Tenant::DOCUMENTS as $k => $l)
                                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="sig.documents" value="{{ $k }}" class="rounded" /> {{ __($l) }}</label>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-5">
                            <div class="flex h-16 w-40 items-center justify-center overflow-hidden rounded-lg border border-dashed border-zinc-300 bg-white">
                                @if ($signature)
                                    <img src="{{ $signature->temporaryUrl() }}" class="max-h-full max-w-full object-contain" alt="" />
                                @elseif ($editingSignatory && ($cur = $this->signatories->firstWhere('id', $editingSignatory))?->signatureUrl())
                                    <img src="{{ $cur->signatureUrl() }}" class="max-h-full max-w-full object-contain" alt="" />
                                @else
                                    <span class="text-xs text-zinc-400">{{ __('No signature') }}</span>
                                @endif
                            </div>
                            <div class="space-y-1">
                                <input type="file" wire:model="signature" accept="image/png,image/jpeg,image/webp" class="block text-sm" />
                                <flux:text class="text-xs">{{ __('Scanned signature, transparent PNG preferred, up to 512 KB.') }}</flux:text>
                                @error('signature')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <flux:input type="number" wire:model="sig.sort_order" :label="__('Order')" class="w-24" />
                            <flux:checkbox wire:model="sig.active" :label="__('Active')" />
                        </div>
                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary">{{ $editingSignatory ? __('Save changes') : __('Add signatory') }}</flux:button>
                            @if ($editingSignatory)<flux:button variant="ghost" wire:click="cancelSignatory">{{ __('Cancel') }}</flux:button>@endif
                        </div>
                    </form>

                    <div class="grid gap-3 sm:grid-cols-2">
                        @forelse ($this->signatories as $s)
                            <div wire:key="s-{{ $s->id }}" class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700 {{ $s->active ? '' : 'opacity-60' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="font-semibold">{{ $s->name }}</div>
                                        <div class="text-xs text-zinc-500">{{ $s->title }}@if ($s->qualifications) · {{ $s->qualifications }}@endif</div>
                                        @if ($s->registration)<div class="text-xs text-zinc-500">{{ $s->registration }}</div>@endif
                                    </div>
                                    @if ($s->signatureUrl())<img src="{{ $s->signatureUrl() }}" class="h-10 max-w-24 object-contain" alt="" />@endif
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @forelse ($s->documents ?? [] as $d)
                                        <flux:badge size="sm" color="indigo">{{ __(\App\Models\Tenant::DOCUMENTS[$d] ?? $d) }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-amber-600">{{ __('Not assigned to any document') }}</span>
                                    @endforelse
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <flux:button size="xs" wire:click="editSignatory({{ $s->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="deleteSignatory({{ $s->id }})" wire:confirm="{{ __('Remove this signatory?') }}">{{ __('Remove') }}</flux:button>
                                </div>
                            </div>
                        @empty
                            <flux:text>{{ __('No signatories yet. Add the people who sign receipts, referral letters, reports and certificates.') }}</flux:text>
                        @endforelse
                    </div>
                </div>
            @endif
        </div>

        {{-- Live letterhead preview --}}
        <div class="xl:col-span-2">
            <div class="sticky top-4 space-y-2">
                <div class="text-xs font-medium uppercase tracking-[0.15em] text-zinc-500">{{ __('Letterhead preview') }}</div>
                <div class="rounded-xl border border-neutral-200 bg-white p-5 text-zinc-800 dark:border-neutral-700">
                    <x-letterhead :tenant="$t" :facility="app(\App\Services\Tenancy\TenantContext::class)->facility()" :title="__('Referral letter')" number="REF-26-000123" :primary="$brand['primary_color']" :secondary="$brand['secondary_color']" :logo-preview="$logo?->temporaryUrl()" />
                    <div class="my-4 space-y-1.5">
                        <div class="h-2 w-3/4 rounded bg-zinc-100"></div>
                        <div class="h-2 w-full rounded bg-zinc-100"></div>
                        <div class="h-2 w-5/6 rounded bg-zinc-100"></div>
                    </div>
                    <x-signatures :tenant="$t" document="referral" />
                    <x-document-footer :tenant="$t" />
                </div>
            </div>
        </div>
    </div>
</div>
