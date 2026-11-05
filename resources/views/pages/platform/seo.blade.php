<?php

use App\Models\PlatformSetting;
use App\Services\Audit\AuditLogger;
use App\Support\Seo;
use Flux\Flux;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Platform → SEO & sitemap. Controls how the public pages appear in search
 * engines and link previews, and generates sitemap.xml / robots.txt.
 * Signed-in (clinical) pages are always noindex, whatever is set here.
 */
new #[Title('SEO & sitemap')] class extends Component {
    use WithFileUploads;

    public array $s = [];

    public string $extra_urls = '';

    public $share_image;

    public function mount(): void
    {
        $this->s = Seo::settings();
        $this->extra_urls = implode("\n", (array) ($this->s['extra_urls'] ?? []));
    }

    #[Computed]
    public function sitemapInfo(): ?array
    {
        return PlatformSetting::get('seo_sitemap');
    }

    #[Computed]
    public function sitemapUrls(): array
    {
        return Seo::sitemapUrls();
    }

    #[Computed]
    public function checks(): array
    {
        $desc = mb_strlen((string) ($this->s['description'] ?? ''));
        $https = str_starts_with((string) config('app.url'), 'https://');

        return [
            [__('Search engine indexing switched on'), (bool) $this->s['indexing'], __('Public pages are hidden from search engines while this is off.')],
            [__('Site served over HTTPS'), $https, __('Search engines rank HTTPS sites higher. Set APP_URL to https://.')],
            [__('Meta description is 50–160 characters'), $desc >= 50 && $desc <= 160, __('Currently :n characters.', ['n' => $desc])],
            [__('Sitemap generated'), (bool) $this->sitemapInfo, __('Generate it below; it also regenerates nightly.')],
            [__('Share image set'), (bool) $this->s['og_image'], __('Optional: a 1200×630 image for WhatsApp/LinkedIn/X link previews. The HMIS logo is used until then.')],
            [__('Google Search Console verified'), filled($this->s['google_verification']), __('Optional if the domain is already verified by DNS in Search Console.')],
            [__('Bing Webmaster Tools verified'), filled($this->s['bing_verification']), __('Optional. Bing can also import your site from Google Search Console.')],
            [__('Clinical pages protected'), true, __('Every signed-in page, invitation and reset link is sent with noindex and blocked in robots.txt.')],
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $this->validate([
            's.site_name' => ['required', 'string', 'max:60'],
            's.title_suffix' => ['nullable', 'string', 'max:80'],
            's.description' => ['required', 'string', 'min:20', 'max:300'],
            's.keywords' => ['nullable', 'string', 'max:500'],
            's.organization' => ['nullable', 'string', 'max:120'],
            's.organization_url' => ['nullable', 'url', 'max:255'],
            's.contact_email' => ['nullable', 'email', 'max:255'],
            's.twitter' => ['nullable', 'string', 'max:30', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
            's.google_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            's.bing_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'share_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            's.google_verification.regex' => __('Paste only the content value of the meta tag, e.g. AbC123xyz…'),
            's.bing_verification.regex' => __('Paste only the content value of the meta tag.'),
        ]);

        $urls = collect(preg_split('/\R/', $this->extra_urls))->map(fn ($u) => trim($u))->filter()->values();
        $foreign = $urls->reject(fn ($u) => Seo::isOwnUrl($u));
        if ($foreign->isNotEmpty()) {
            $this->addError('extra_urls', __('Only pages on :host can be in this sitemap: :urls', ['host' => parse_url(config('app.url'), PHP_URL_HOST), 'urls' => $foreign->implode(', ')]));

            return;
        }

        if ($this->share_image) {
            $path = $this->share_image->storeAs('seo', 'share-image-'.now()->format('YmdHis').'.'.$this->share_image->getClientOriginalExtension(), 'public');
            $this->s['og_image'] = Storage::disk('public')->url($path);
            $this->reset('share_image');
        }

        $this->s['indexing'] = (bool) $this->s['indexing'];
        $this->s['twitter'] = $this->s['twitter'] ? ltrim($this->s['twitter'], '@') : null;
        $this->s['extra_urls'] = $urls->all();

        Seo::save($this->s);
        Seo::generateSitemap();
        $audit->log('platform.seo_updated', null, ['indexing' => $this->s['indexing']]);
        unset($this->sitemapInfo, $this->sitemapUrls, $this->checks);

        Flux::toast(variant: 'success', text: __('SEO settings saved and sitemap regenerated.'));
    }

    public function removeShareImage(): void
    {
        $this->s['og_image'] = null;
        Seo::save($this->s);
        unset($this->checks);
    }

    public function generate(): void
    {
        $count = Seo::generateSitemap();
        unset($this->sitemapInfo, $this->sitemapUrls, $this->checks);
        Flux::toast(variant: 'success', text: trans_choice('Sitemap generated with :count URL.|Sitemap generated with :count URLs.', $count, ['count' => $count]));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('SEO & sitemap') }}</flux:heading>
        <flux:text>{{ __('How the public pages of :host appear in Google, Bing and link previews. Patient and clinical pages are never indexed, whatever is set here.', ['host' => parse_url(config('app.url'), PHP_URL_HOST)]) }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Settings --}}
        <form wire:submit="save" class="grid content-start gap-4 rounded-xl border border-neutral-200 p-5 lg:col-span-2 dark:border-neutral-700">
            <flux:switch wire:model.live="s.indexing" :label="__('Allow search engines to index the public pages')" :description="__('Turn off to hide the whole site from search results (robots.txt then blocks everything).')" />

            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.300ms="s.site_name" :label="__('Site name')" />
                <flux:input wire:model.live.debounce.300ms="s.title_suffix" :label="__('Title tagline')" />
            </div>
            <div>
                <flux:textarea wire:model.live.debounce.300ms="s.description" :label="__('Meta description')" rows="3" />
                @php($len = mb_strlen((string) $s['description']))
                <flux:text class="mt-1 text-xs" style="color: {{ $len >= 50 && $len <= 160 ? '#10B981' : '#F59E0B' }}">{{ __(':n characters (50–160 recommended)', ['n' => $len]) }}</flux:text>
            </div>
            <flux:input wire:model="s.keywords" :label="__('Keywords')" :description="__('Comma-separated. Minor for Google, still used by some search engines.')" />

            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="s.organization" :label="__('Organisation')" />
                <flux:input wire:model="s.organization_url" :label="__('Organisation website')" placeholder="https://solforbs.com" />
                <flux:input wire:model="s.contact_email" :label="__('Contact email')" />
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="s.google_verification" :label="__('Google verification code')" :description="__('From Search Console → HTML tag')" />
                <flux:input wire:model="s.bing_verification" :label="__('Bing verification code')" :description="__('From Bing Webmaster → Meta tag')" />
                <flux:input wire:model="s.twitter" :label="__('X / Twitter handle')" placeholder="@solforbs" />
            </div>

            <div class="grid gap-2">
                <flux:label>{{ __('Share image (link previews)') }}</flux:label>
                <div class="flex flex-wrap items-center gap-4">
                    <img src="{{ $share_image ? $share_image->temporaryUrl() : \App\Support\Seo::ogImageUrl() }}" alt="" class="h-16 w-28 rounded-lg border border-neutral-200 object-cover dark:border-neutral-700">
                    <input type="file" wire:model="share_image" accept="image/png,image/jpeg,image/webp" class="text-sm">
                    @if ($s['og_image'])
                        <flux:button size="sm" variant="ghost" wire:click="removeShareImage">{{ __('Use HMIS logo') }}</flux:button>
                    @endif
                </div>
                <flux:error name="share_image" />
            </div>

            <div>
                <flux:textarea wire:model="extra_urls" :label="__('Extra public pages for the sitemap')" rows="3" placeholder="{{ url('/about') }}" :description="__('Optional, one per line. Only public pages on this domain; never pages that need a login.')" />
                <flux:error name="extra_urls" />
            </div>

            <div>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save and regenerate sitemap') }}</flux:button>
            </div>
        </form>

        {{-- Preview + health --}}
        <div class="grid content-start gap-4">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3" class="mb-3">{{ __('Search result preview') }}</flux:heading>
                <div class="rounded-lg bg-white p-3 text-left" style="font-family: Arial, sans-serif;">
                    <div class="text-xs" style="color:#202124">{{ parse_url(config('app.url'), PHP_URL_HOST) }} › login</div>
                    <div class="mt-0.5 text-lg leading-snug" style="color:#1a0dab">{{ \Illuminate\Support\Str::limit(__('Log in').' · '.$s['site_name'].' — '.$s['title_suffix'], 60) }}</div>
                    <div class="mt-0.5 text-sm" style="color:#4d5156">{{ \Illuminate\Support\Str::limit($s['description'], 160) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3" class="mb-3">{{ __('SEO health') }}</flux:heading>
                <ul class="grid gap-2.5">
                    @foreach ($this->checks as [$label, $ok, $hint])
                        <li class="flex gap-2.5">
                            <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style="background: {{ $ok ? '#10B981' : '#F59E0B' }}">{{ $ok ? '✓' : '!' }}</span>
                            <div>
                                <div class="text-sm font-medium">{{ $label }}</div>
                                <div class="text-xs text-neutral-500">{{ $hint }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    {{-- Sitemap --}}
    <div class="grid gap-4 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading level="2">{{ __('Sitemap') }}</flux:heading>
                <flux:text class="text-sm">
                    @if ($this->sitemapInfo)
                        {{ trans_choice(':count URL|:count URLs', $this->sitemapInfo['count'], ['count' => $this->sitemapInfo['count']]) }} · {{ __('generated :when', ['when' => \Illuminate\Support\Carbon::parse($this->sitemapInfo['generated_at'])->diffForHumans()]) }} · {{ __('regenerates nightly') }}
                    @else
                        {{ __('Not generated yet.') }}
                    @endif
                </flux:text>
            </div>
            <div class="flex flex-wrap gap-2">
                <flux:button icon="arrow-path" wire:click="generate">{{ __('Generate sitemap now') }}</flux:button>
                <flux:button icon="document-text" :href="url('/sitemap.xml')" target="_blank">sitemap.xml</flux:button>
                <flux:button icon="shield-check" :href="url('/robots.txt')" target="_blank">robots.txt</flux:button>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('URL') }}</th><th class="px-3 py-2">{{ __('Change frequency') }}</th><th class="px-3 py-2">{{ __('Priority') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->sitemapUrls as $u)
                        <tr><td class="px-3 py-2 font-mono text-xs">{{ $u['loc'] }}</td><td class="px-3 py-2">{{ $u['changefreq'] }}</td><td class="px-3 py-2">{{ $u['priority'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-6 text-center text-neutral-500">{{ __('Indexing is off, so the sitemap is empty.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-neutral-50 p-4 text-sm dark:bg-neutral-800">
            <div class="mb-1 font-semibold">{{ __('Submit to search engines (once)') }}</div>
            <ol class="list-decimal space-y-1 pl-5 text-neutral-600 dark:text-neutral-300">
                <li>{!! __('Google: open <a class="underline" href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a>, choose the solforbs.com property (or add :url), then Sitemaps → submit <code>sitemap.xml</code>.', ['url' => e(url('/'))]) !!}</li>
                <li>{!! __('Bing: open <a class="underline" href="https://www.bing.com/webmasters" target="_blank" rel="noopener">Bing Webmaster Tools</a>, import from Google Search Console or add the site, then submit the same sitemap.') !!}</li>
                <li>{{ __('After that, search engines re-read the sitemap on their own; it regenerates here every night.') }}</li>
            </ol>
        </div>
    </div>
</div>
