<?php

declare(strict_types=1);

use WorkOS\SessionManager;

/**
 * @param  array<string, mixed>  $payload
 */
function authkitFakeJwt(array $payload): string
{
    $encode = static fn (array $part): string => rtrim(
        strtr(base64_encode((string) json_encode($part)), '+/', '-_'),
        '=',
    );

    return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($payload).'.fakesig';
}

/**
 * @return array<string, mixed>
 */
function authkitFullClaims(): array
{
    return [
        'iss' => 'https://api.workos.com/user_management/client_123',
        'aud' => 'client_123',
        'sub' => 'user_123',
        'org_id' => 'org_123',
        'role' => 'admin',
        'permissions' => ['posts:read', 'posts:write'],
        'feature_flags' => ['beta-dashboard'],
        'exp' => 1893456000,
        'iat' => 1893452400,
    ];
}

it('decodes a raw jwt and prints its claims', function (): void {
    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt(authkitFullClaims())])
        ->expectsOutputToContain('https://api.workos.com/user_management/client_123')
        ->expectsOutputToContain('client_123')
        ->expectsOutputToContain('admin')
        ->expectsOutputToContain('posts:read, posts:write')
        ->expectsOutputToContain('beta-dashboard')
        ->assertSuccessful();
});

it('reports absent claims with a greppable sentinel', function (): void {
    $claims = authkitFullClaims();
    unset($claims['feature_flags'], $claims['permissions']);

    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('(not present)')
        ->assertSuccessful();
});

it('reports an empty claim array distinctly', function (): void {
    $claims = authkitFullClaims();
    $claims['permissions'] = [];

    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('(empty array)')
        ->assertSuccessful();
});

it('distinguishes a present-but-null claim from an absent one', function (): void {
    $claims = authkitFullClaims();
    $claims['role'] = null;
    unset($claims['roles']);

    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('(null)')
        ->expectsOutputToContain('(not present)')
        ->assertSuccessful();
});

it('keeps the keys of an object-shaped claim', function (): void {
    $claims = authkitFullClaims();
    $claims['feature_flags'] = ['beta-dashboard' => true, 'new-nav' => false];

    // Asserted against the unabridged payload rather than the two-column table,
    // which pads to the terminal width and clips longer values. Only one
    // substring is asserted per test: both live in a single write, and Mockery
    // satisfies just the first matching expectation for a given call.
    // A false-valued key also proves the old implode() bug is gone — it rendered
    // booleans as '1'/'' and dropped the keys entirely.
    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('"new-nav": false')
        ->assertSuccessful();
});

it('prints claims that are outside the well-known key list', function (): void {
    $claims = authkitFullClaims();
    $claims['some_unexpected_claim'] = 'surprise';

    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('some_unexpected_claim')
        ->assertSuccessful();
});

it('renders object-shaped claim members as json instead of the word Array', function (): void {
    $claims = authkitFullClaims();
    $claims['feature_flags'] = [['slug' => 'beta-dashboard', 'enabled' => true]];

    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt($claims)])
        ->expectsOutputToContain('"slug":"beta-dashboard"')
        ->assertSuccessful();
});

it('decodes a sealed session to the same claims as the raw jwt', function (): void {
    $password = base64_encode(random_bytes(32));
    $jwt = authkitFakeJwt(authkitFullClaims());
    $sealed = SessionManager::sealData(['access_token' => $jwt], $password);

    $this->artisan('authkit:inspect-token', [
        'token' => $sealed,
        '--cookie-password' => $password,
    ])
        ->expectsOutputToContain('https://api.workos.com/user_management/client_123')
        ->expectsOutputToContain('admin')
        ->assertSuccessful();
});

it('falls back to the configured cookie password when unsealing', function (): void {
    $password = base64_encode(random_bytes(32));
    config()->set('authkit.cookie_password', $password);

    $sealed = SessionManager::sealData(['access_token' => authkitFakeJwt(authkitFullClaims())], $password);

    $this->artisan('authkit:inspect-token', ['token' => $sealed])
        ->expectsOutputToContain('user_123')
        ->assertSuccessful();
});

it('fails cleanly when the cookie password is wrong', function (): void {
    $sealed = SessionManager::sealData(
        ['access_token' => authkitFakeJwt(authkitFullClaims())],
        base64_encode(random_bytes(32)),
    );

    $this->artisan('authkit:inspect-token', [
        'token' => $sealed,
        '--cookie-password' => base64_encode(random_bytes(32)),
    ])
        ->expectsOutputToContain('--cookie-password')
        ->assertFailed();
});

it('fails when a sealed session is supplied with no cookie password available', function (): void {
    config()->set('authkit.cookie_password', '');

    $this->artisan('authkit:inspect-token', ['token' => 'not-a-jwt-and-not-unsealable'])
        ->expectsOutputToContain('No cookie password configured')
        ->assertFailed();
});

it('fails cleanly on malformed input', function (): void {
    $this->artisan('authkit:inspect-token', ['token' => 'garbage.garbage.garbage'])
        ->expectsOutputToContain('Could not decode token:')
        ->assertFailed();
});

it('warns when the token is passed as a shell argument', function (): void {
    $this->artisan('authkit:inspect-token', ['token' => authkitFakeJwt(authkitFullClaims())])
        ->expectsOutputToContain('shell history')
        ->assertSuccessful();
});
