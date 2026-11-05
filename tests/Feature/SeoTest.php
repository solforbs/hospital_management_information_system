<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function platformAdmin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]);
    }

    public function test_login_page_is_indexable_with_full_meta_tags(): void
    {
        $res = $this->get('/login')->assertOk();

        $this->assertFalse($res->headers->has('X-Robots-Tag'));
        $res->assertSee('<meta name="robots" content="index, follow, max-image-preview:large">', false)
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical" href="'.url('/login').'">', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('name="twitter:card"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('"@type": "SoftwareApplication"', false)
            ->assertSee('<link rel="manifest" href="/site.webmanifest">', false);
    }

    public function test_private_and_token_pages_are_never_indexable(): void
    {
        foreach (['/register', '/forgot-password', '/login?next=x'] as $url) {
            $res = $this->get($url);
            $this->assertStringContainsString('noindex', (string) $res->headers->get('X-Robots-Tag'), $url);
        }

        $this->get('/invitation/1?h=x&signature=y')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->actingAs($this->platformAdmin());
        $res = $this->get(route('platform.seo'))->assertOk();
        $this->assertStringContainsString('noindex', $res->headers->get('X-Robots-Tag'));
        $res->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertDontSee('application/ld+json', false);
    }

    public function test_sitemap_lists_only_public_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<loc>'.url('/login').'</loc>', $xml);
        $this->assertStringNotContainsString('/patients', $xml);
        $this->assertStringNotContainsString('/dashboard', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'valid XML');
        $this->assertFalse($this->get('/sitemap.xml')->headers->has('Set-Cookie'), 'no session cookie for crawlers');
    }

    public function test_robots_txt_allows_public_pages_and_blocks_the_rest(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        $this->assertStringContainsString('Allow: /login$', $txt);
        $this->assertStringContainsString('Allow: /build/', $txt);
        $this->assertStringContainsString("Disallow: /\n", $txt);
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $txt);
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'a static robots.txt would bypass these rules');
    }

    public function test_platform_admin_manages_seo_and_generates_sitemap(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test('pages::platform.seo')
            ->set('s.description', 'Kenyan hospital management system for SHA claims, billing, pharmacy, laboratory and MOH reports.')
            ->set('s.google_verification', 'AbC123-xyz_9')
            ->set('extra_urls', url('/about'))
            ->call('save')->assertHasNoErrors();

        $this->assertSame('AbC123-xyz_9', Seo::settings()['google_verification']);
        $admin = auth()->user();
        auth()->logout();
        $this->get('/login')->assertSee('<meta name="google-site-verification" content="AbC123-xyz_9">', false)
            ->assertSee('SHA claims, billing, pharmacy', false);
        $this->assertStringContainsString(url('/about'), $this->get('/sitemap.xml')->getContent());
        $this->actingAs($admin);

        // Foreign URLs and bad verification codes are refused.
        Livewire::test('pages::platform.seo')->set('extra_urls', 'https://evil.example.com/page')->call('save')->assertHasErrors('extra_urls');
        Livewire::test('pages::platform.seo')->set('s.google_verification', '<script>')->call('save')->assertHasErrors('s.google_verification');

        Livewire::test('pages::platform.seo')->call('generate')->assertSee('generated');
    }

    public function test_switching_indexing_off_hides_everything(): void
    {
        $this->actingAs($this->platformAdmin());
        Livewire::test('pages::platform.seo')->set('s.indexing', false)->call('save')->assertHasNoErrors();
        auth()->logout();

        $this->assertStringContainsString("Disallow: /\n", $this->get('/robots.txt')->getContent());
        $this->assertStringNotContainsString('Allow: /login', $this->get('/robots.txt')->getContent());
        $this->assertStringContainsString('noindex', $this->get('/login')->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('<loc>', $this->get('/sitemap.xml')->getContent());
    }

    public function test_only_platform_admins_open_the_seo_page(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get(route('platform.seo'))->assertForbidden();
    }

    public function test_manifest_and_daily_schedule(): void
    {
        $this->get('/site.webmanifest')->assertOk()->assertJsonPath('short_name', 'HMIS');
        $this->artisan('hms:generate-sitemap')->assertSuccessful();
        $this->artisan('schedule:list')->expectsOutputToContain('hms:generate-sitemap');
    }
}
