<?php

use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\FhirExportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LiveController;
use App\Http\Controllers\MpesaCallbackController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\ShaCallbackController;
use App\Http\Middleware\IdentifyTenant;
use App\Support\ModuleRegistry;
use App\Support\SpecialtyClinics;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Search engines: generated sitemap, robots rules and web app manifest.
// No session or tenant needed, and no cookies handed to crawlers.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class, ValidateCsrfToken::class, IdentifyTenant::class])->group(function () {
    Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
    Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
    Route::get('site.webmanifest', [SeoController::class, 'manifest'])->name('seo.manifest');
});

// Staff invitation emails: signed, time-limited "set your password" link.
Route::get('invitation/{user}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('invitation/{user}', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitation.store');

// No public landing page: guests go straight to login, staff to their workspace.
Route::get('/', fn () => auth()->check() ? redirect()->route('landing') : redirect()->route('login'))->name('home');

// SHA / HIE server-to-server callbacks (HMAC-signed; no session or CSRF).
Route::post('hooks/sha/{tenant:slug}', ShaCallbackController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('hooks.sha');

Route::post('hooks/mpesa/{tenant:slug}/{token}', MpesaCallbackController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('hooks.mpesa');

// Institution logos are public (printed documents, browser tab icon).
Route::get('branding/{tenant:slug}/logo', [BrandingAssetController::class, 'logo'])->name('branding.logo');

Route::middleware(['auth', 'verified'])->group(function () {
    // Role-aware landing page after login (config('fortify.home')).
    Route::get('home', fn () => redirect(ModuleRegistry::landingRoute(auth()->user())))->name('landing');

    // Help & training centre: open to every signed-in user, including platform admins.
    Route::livewire('training', 'pages::training.index')->name('training.index');
    Route::livewire('training/{module}', 'pages::training.module')->where('module', '[a-z-]+')->name('training.module');

    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::livewire('tenants', 'pages::platform.tenants')->name('tenants');
        Route::livewire('icd-11', 'pages::platform.icd')->name('icd');
        Route::livewire('seo', 'pages::platform.seo')->name('seo');
    });

    Route::middleware(['tenant.active', 'security.policy', 'module.enabled'])->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::get('branding/stamp', [BrandingAssetController::class, 'stamp'])->name('branding.stamp');
        Route::get('branding/signatures/{signatory}', [BrandingAssetController::class, 'signature'])->name('branding.signature');
        Route::livewire('notifications', 'pages::notifications')->name('notifications');

        Route::prefix('patients')->name('patients.')->group(function () {
            Route::livewire('/', 'pages::patients.index')->name('index');
            Route::livewire('/create', 'pages::patients.create')->name('create');
            Route::livewire('/{patient}', 'pages::patients.show')->name('show');
            Route::livewire('/{patient}/edit', 'pages::patients.edit')->name('edit');
        });

        Route::livewire('appointments', 'pages::appointments.index')->name('appointments.index');
        Route::livewire('reception', 'pages::reception.index')->name('reception.index');
        Route::livewire('triage', 'pages::triage.index')->name('triage.index');
        Route::livewire('emergency', 'pages::emergency.index')->name('emergency.index');

        Route::livewire('consultation', 'pages::consultation.index')->name('consultation.index');
        Route::livewire('consultation/{encounter}', 'pages::consultation.show')->name('consultation.show');
        Route::get('fhir/encounters/{encounter}', FhirExportController::class)->name('fhir.encounter');
        Route::get('live', LiveController::class)->middleware('throttle:120,1')->name('live');

        // Printable clinical documents (letterhead + signatories).
        Route::prefix('print')->name('print.')->controller(PrintController::class)->group(function () {
            Route::get('discharge/{admission}', 'discharge')->name('discharge');
            Route::get('lab/{labOrder}', 'lab')->name('lab');
            Route::get('radiology/{radiologyOrder}', 'radiology')->name('radiology');
            Route::get('certificate/{certificate}', 'certificate')->name('certificate');
            Route::get('birth/{newborn}', 'birth')->name('birth');
            Route::get('death/{death}', 'death')->name('death');
        });

        Route::livewire('laboratory', 'pages::lab.worklist')->name('lab.worklist');
        Route::livewire('laboratory/catalog', 'pages::lab.catalog')->name('lab.catalog');

        Route::livewire('radiology', 'pages::radiology.worklist')->name('radiology.worklist');
        Route::livewire('radiology/catalog', 'pages::radiology.catalog')->name('radiology.catalog');

        Route::livewire('pharmacy', 'pages::pharmacy.queue')->name('pharmacy.queue');
        Route::livewire('pharmacy/medicines', 'pages::pharmacy.medicines')->name('pharmacy.medicines');

        Route::livewire('wards', 'pages::wards.board')->name('wards.board');
        Route::livewire('wards/setup', 'pages::wards.setup')->name('wards.setup');
        Route::livewire('wards/admissions', 'pages::wards.admissions')->name('wards.admissions');
        Route::livewire('wards/admissions/{admission}', 'pages::wards.admission')->name('wards.admission');

        Route::livewire('maternity', 'pages::maternity.index')->name('maternity.index');
        Route::livewire('maternity/register', 'pages::maternity.register')->name('maternity.register');
        Route::livewire('maternity/pregnancies/{pregnancy}', 'pages::maternity.pregnancy')->name('maternity.pregnancy');
        Route::livewire('mortuary', 'pages::departments.mortuary')->name('mortuary.index');
        Route::livewire('blood-bank', 'pages::departments.blood-bank')->name('bloodbank.index');
        Route::livewire('dialysis', 'pages::departments.dialysis')->name('dialysis.index');
        foreach (array_keys(SpecialtyClinics::CLINICS) as $clinic) {
            Route::livewire("clinics/{$clinic}", 'pages::departments.clinic')->name("clinics.{$clinic}");
        }

        Route::livewire('child-welfare', 'pages::cwc.index')->name('cwc.index');
        Route::livewire('child-welfare/{patient}', 'pages::cwc.child')->name('cwc.child');
        Route::livewire('labour-ward', 'pages::maternity.labour-board')->name('labour.index');
        Route::livewire('labour-ward/{labour}', 'pages::maternity.labour')->name('labour.show');

        Route::livewire('critical-care', 'pages::critical-care.index')->name('critical-care.index');
        Route::livewire('critical-care/{admission}', 'pages::critical-care.chart')->name('critical-care.chart');

        Route::livewire('referrals', 'pages::referrals.index')->name('referrals.index');
        Route::livewire('referrals/new', 'pages::referrals.create')->name('referrals.create');
        Route::livewire('referrals/{referral}', 'pages::referrals.show')->name('referrals.show');

        Route::livewire('theatre', 'pages::theatre.index')->name('theatre.index');
        Route::livewire('theatre/{surgery}', 'pages::theatre.show')->name('theatre.show');

        Route::livewire('billing', 'pages::billing.index')->name('billing.index');
        Route::livewire('billing/services', 'pages::billing.services')->name('billing.services');
        Route::livewire('billing/invoices/{invoice}', 'pages::billing.invoice')->name('billing.invoice');
        Route::get('billing/receipts/{payment}', ReceiptController::class)->name('billing.receipt');
        Route::get('billing/refunds/{refund}', [ReceiptController::class, 'refund'])->name('billing.refund');
        Route::livewire('patients-merge', 'pages::patients.merge')->name('patients.merge');
        Route::livewire('admin/roles', 'pages::admin.roles')->name('admin.roles');
        Route::livewire('stores', 'pages::stores.index')->name('stores.index');

        Route::livewire('insurance', 'pages::insurance.index')->name('insurance.index');
        Route::livewire('sha/visits/{encounter}', 'pages::sha.visit')->name('sha.visit');

        Route::livewire('reports', 'pages::reports.index')->name('reports.index');
        Route::livewire('reports/moh', 'pages::reports.moh')->name('reports.moh');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::livewire('facilities', 'pages::admin.facilities')->name('facilities');
            Route::livewire('departments', 'pages::admin.departments')->name('departments');
            Route::livewire('staff', 'pages::admin.staff')->name('staff');
            Route::livewire('training-records', 'pages::training.records')->name('training-records');
            Route::livewire('audit', 'pages::admin.audit')->name('audit');
            Route::livewire('integrations', 'pages::admin.integrations')->name('integrations');
            Route::livewire('institution', 'pages::admin.institution')->name('institution');
        });
    });
});

require __DIR__.'/settings.php';

// Unknown URLs go through the web middleware (session, tenant) so the 404
// page knows who is signed in and can show their hospital's branding.
Route::fallback(fn () => abort(404));
