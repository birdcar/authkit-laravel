<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use WorkOS\AuthKit\Http\Controllers\WebhookController;

Route::post('/', [WebhookController::class, 'handle'])->name('workos.webhook');
