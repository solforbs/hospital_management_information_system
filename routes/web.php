<?php

use App\Http\Controllers\ReceiptController;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Role-aware landing page after login (config('fortify.home')).
    Route::get('home', fn () => redirect(ModuleRegistry::landingRoute(auth()->user())))->name('landing');

    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::livewire('tenants', 'pages::platform.tenants')->name('tenants');
    });

    Route::middleware('tenant.active')->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
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

        Route::livewire('theatre', 'pages::theatre.index')->name('theatre.index');
        Route::livewire('theatre/{surgery}', 'pages::theatre.show')->name('theatre.show');

        Route::livewire('billing', 'pages::billing.index')->name('billing.index');
        Route::livewire('billing/services', 'pages::billing.services')->name('billing.services');
        Route::livewire('billing/invoices/{invoice}', 'pages::billing.invoice')->name('billing.invoice');
        Route::get('billing/receipts/{payment}', ReceiptController::class)->name('billing.receipt');

        Route::livewire('insurance', 'pages::insurance.index')->name('insurance.index');

        Route::livewire('reports', 'pages::reports.index')->name('reports.index');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::livewire('facilities', 'pages::admin.facilities')->name('facilities');
            Route::livewire('departments', 'pages::admin.departments')->name('departments');
            Route::livewire('staff', 'pages::admin.staff')->name('staff');
            Route::livewire('audit', 'pages::admin.audit')->name('audit');
        });
    });
});

require __DIR__.'/settings.php';
