<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Http\Controllers\AuthController;

Route::get('login', [AuthController::class, 'login'])->name('login');
Route::get('callback', [AuthController::class, 'callback'])->name('workos.callback');
Route::match(['get', 'post'], 'logout', [AuthController::class, 'logout'])->name('logout');
