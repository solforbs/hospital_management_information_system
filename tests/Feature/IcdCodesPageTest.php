<?php

namespace Tests\Feature;

use App\Models\IcdCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class IcdCodesPageTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/hms-icd-test-'.uniqid();
        config(['hms.icd11_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A miniature WHO SimpleTabulation file, zipped as WHO ships it. */
    protected function whoZip(): string
    {
        $rows = [
            "\xEF\xBB\xBFFoundation URI\tLinearization URI\tCode\tBlockId\tTitle\tClassKind\tDepthInKind\tIsResidual\tChapterNo\tBrowserLink\tisLeaf",
            "u\tu\t\t\t\"Certain infectious or parasitic diseases\"\tchapter\t1\tFalse\t01\t\tFalse",
            "u\tu\t1F40\t\t\"- - Malaria due to Plasmodium falciparum\"\tcategory\t1\tFalse\t01\t\tTrue",
            "u\tu\tCB21\t\t\"- Pleural effusion\"\tcategory\t2\tFalse\t12\t\tTrue",
            "u\tu\tXS5W\t\t\"- Mild\"\tcategory\t1\tFalse\tX\t\tTrue",
        ];
        $txt = tempnam(sys_get_temp_dir(), 'icd');
        file_put_contents($txt, implode("\r\n", $rows)."\r\n");
        $zipPath = tempnam(sys_get_temp_dir(), 'icdzip').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($txt, 'SimpleTabulation-ICD-11-MMS-en.txt');
        $zip->addFromString('readme.txt', 'WHO');
        $zip->close();
        unlink($txt);

        return $zipPath;
    }

    protected function platformAdmin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]);
    }

    public function test_platform_admin_downloads_then_imports_later(): void
    {
        $zip = $this->whoZip();
        Http::fake(['icdcdn.who.int/*' => Http::response(file_get_contents($zip))]);
        $this->actingAs($this->platformAdmin());

        $page = Livewire::test('pages::platform.icd')->set('release', '2026-01')->call('download')->assertHasNoErrors();

        // Downloaded but not yet imported.
        $this->assertFileExists($this->dir.'/SimpleTabulation-2026-01.zip');
        $this->assertFalse(IcdCode::where('code', 'CB21')->exists());
        $page->assertSee('SimpleTabulation-2026-01.zip');

        $page->call('import', 'SimpleTabulation-2026-01.zip');
        $this->assertSame('Pleural effusion', IcdCode::where('code', 'CB21')->value('title'));
        $this->assertSame('Malaria due to Plasmodium falciparum', IcdCode::where('code', '1F40')->value('title'));
        $this->assertTrue(IcdCode::where('code', '1F40')->value('is_common'), 'starter "common" flag is kept');

        // Re-import is idempotent.
        $before = IcdCode::count();
        $page->call('import', 'SimpleTabulation-2026-01.zip');
        $this->assertSame($before, IcdCode::count());

        // Extension codes are browsable but cannot be a diagnosis.
        $this->assertTrue(IcdCode::where('code', 'XS5W')->exists());
        $this->assertFalse(IcdCode::isValid('XS5W'));
        $this->assertTrue(IcdCode::isValid('cb21'));

        $page->set('search', 'pleural')->assertSee('CB21')->assertDontSee('XS5W');
        $page->set('search', '')->set('chapter', 'X')->assertSee('XS5W')->assertSee('Extension');
    }

    public function test_bad_release_and_failed_download_are_reported(): void
    {
        Http::fake(['icdcdn.who.int/*' => Http::response('nope', 404)]);
        $this->actingAs($this->platformAdmin());

        Livewire::test('pages::platform.icd')->set('release', 'latest')->call('download')->assertHasErrors(['release']);
        Livewire::test('pages::platform.icd')->set('release', '1999-01')->call('download')->assertHasErrors(['release']);
        $this->assertSame([], glob($this->dir.'/*'));
    }

    public function test_upload_is_saved_for_offline_import_and_common_can_be_toggled(): void
    {
        $this->actingAs($this->platformAdmin());
        $file = UploadedFile::fake()->createWithContent('SimpleTabulation-ICD-11-MMS-en.zip', file_get_contents($this->whoZip()));

        $page = Livewire::test('pages::platform.icd')->set('upload', $file)->call('saveUpload')->assertHasNoErrors();
        $saved = basename(glob($this->dir.'/Uploaded-*.zip')[0]);
        $page->call('import', $saved);

        $code = IcdCode::where('code', 'CB21')->firstOrFail();
        $this->assertFalse($code->is_common);
        $page->call('toggleCommon', $code->id);
        $this->assertTrue($code->fresh()->is_common);
    }

    public function test_only_platform_admins_can_manage_codes(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->get(route('platform.icd'))->assertForbidden();

        $this->actingAs($this->platformAdmin());
        $this->get(route('platform.icd'))->assertOk()->assertSee('ICD-11 codes');
    }

    public function test_artisan_command_can_download_only(): void
    {
        Http::fake(['icdcdn.who.int/*' => Http::response(file_get_contents($this->whoZip()))]);

        $this->artisan('hms:import-icd11 --download-only')->assertSuccessful();
        $this->assertFileExists($this->dir.'/SimpleTabulation-2026-01.zip');
        $this->assertFalse(IcdCode::where('code', 'CB21')->exists());

        $this->artisan('hms:import-icd11', ['file' => $this->dir.'/SimpleTabulation-2026-01.zip'])->assertSuccessful();
        $this->assertTrue(IcdCode::where('code', 'CB21')->exists());
    }
}
