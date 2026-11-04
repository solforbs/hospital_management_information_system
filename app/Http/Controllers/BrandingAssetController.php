<?php

namespace App\Http\Controllers;

use App\Models\Signatory;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves institution branding files from private storage (no public
 * symlink needed). Logos are public — they appear on printed documents and
 * the browser tab. Stamps and signatures are only served to signed-in
 * members of the same institution.
 */
class BrandingAssetController extends Controller
{
    public function logo(Tenant $tenant): Response
    {
        abort_unless($tenant->logo_path, 404);

        return $this->serve($tenant->logo_path, public: true);
    }

    public function stamp(TenantContext $context): Response
    {
        $tenant = $context->tenant();
        abort_unless($tenant?->stamp_path, 404);

        return $this->serve($tenant->stamp_path);
    }

    public function signature(Signatory $signatory): Response
    {
        // Route-model binding is tenant-scoped (BelongsToTenant), so another
        // institution's signatory id 404s before reaching here.
        abort_unless($signatory->signature_path, 404);

        return $this->serve($signatory->signature_path);
    }

    protected function serve(string $path, bool $public = false): Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Cache-Control' => ($public ? 'public' : 'private').', max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }
}
