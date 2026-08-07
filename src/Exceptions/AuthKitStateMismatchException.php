<?php

declare(strict_types=1);

namespace Authkit\Authkit\Exceptions;

use Authkit\Authkit\Http\LoginRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class AuthKitStateMismatchException extends RuntimeException
{
    public function __construct(string $message = 'The AuthKit callback state did not match the value stored at login.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): RedirectResponse
    {
        return LoginRedirect::make([
            'authkit' => 'Login expired or was tampered with, please try again.',
        ]);
    }
}
