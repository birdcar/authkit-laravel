<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrganizationController;
use App\Models\Todo;
use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Facades\WorkOS;

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
    })->middleware('workos.permission:todos.read')->name('todos.index');

    // Admin-only todo deletion via route (demonstrates workos.role middleware — D-09)
    Route::delete('/todos/{todo}', function (Todo $todo) {
        $todo->delete();

        WorkOS::audit('todo.deleted', [
            ['type' => 'todo', 'id' => (string) $todo->id, 'name' => $todo->title],
        ]);

        return response()->json(['message' => 'Todo deleted']);
    })->middleware('workos.role:admin')->name('todos.destroy');

    // Organization routes
    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/settings', [OrganizationController::class, 'settings'])->name('settings');
    });
});
