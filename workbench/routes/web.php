<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

// Guest routes
Route::view('/', 'auth.login')->name('home')->middleware('guest');

// Protected routes
Route::middleware(['auth:workos', 'workos.organization.current'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Todo routes
    Route::get('/todos', function () {
        return view('todos.index', [
            'currentOrganization' => request()->attributes->get('current_organization'),
        ]);
    })->name('todos.index');

    // Organization routes
    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/settings', [OrganizationController::class, 'settings'])->name('settings');
    });
});
