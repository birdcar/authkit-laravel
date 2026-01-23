<?php

declare(strict_types=1);

namespace WorkOS\AuthKit\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class WorkOSGuard implements Guard
{
    protected ?Authenticatable $user = null;

    public function __construct(
        protected ?UserProvider $provider,
        protected SessionManager $session,
        protected Request $request,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $session = $this->session->getValidSession();

        if (! $session) {
            return null;
        }

        $this->user = $this->provider?->retrieveById($session->userId);

        if ($this->user && method_exists($this->user, 'setWorkOSSession')) {
            $this->user->setWorkOSSession($session);
        }

        return $this->user;
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return $this->session->getSession() !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }
}
