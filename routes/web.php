<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::prefix('patients')->name('patients.')->group(function () {
        Route::livewire('/', 'pages::patients.index')->name('index');
        Route::livewire('/create', 'pages::patients.create')->name('create');
        Route::livewire('/{patient}', 'pages::patients.show')->name('show');
    });

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('facilities', 'pages::admin.facilities')->name('facilities');
        Route::livewire('departments', 'pages::admin.departments')->name('departments');
        Route::livewire('staff', 'pages::admin.staff')->name('staff');
    });
});

require __DIR__.'/settings.php';
