<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Http\Controllers\OrganizationController;

Route::post('/switch', [OrganizationController::class, 'switch'])->name('workos.organizations.switch');
Route::post('/{organization}/invitations', [OrganizationController::class, 'invite'])->name('workos.organizations.invite');
Route::delete('/{organization}/invitations/{invitation}', [OrganizationController::class, 'revokeInvitation'])->name('workos.organizations.revoke');
