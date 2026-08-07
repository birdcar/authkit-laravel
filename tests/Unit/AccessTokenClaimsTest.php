<?php

declare(strict_types=1);

use Authkit\Authkit\Auth\AccessTokenClaims;
use Authkit\Authkit\Auth\JwtClaimsValidator;
use Authkit\Authkit\Auth\JwtPayloadDecoder;
use Authkit\Authkit\Tests\Fixtures\JwtFixture;

/**
 * @return array<string, mixed>
 */
function claimsPayload(array $overrides = []): array
{
    return array_merge([
        'sub' => 'user_01',
        'iss' => 'https://api.workos.com',
        'client_id' => 'client_01',
        'sid' => 'session_01',
        'jti' => 'jwt_01',
        'iat' => 1_767_225_600,
        'exp' => 1_767_229_200,
    ], $overrides);
}

it('maps a full claim set onto the DTO', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload([
        'org_id' => 'org_01',
        'role' => 'admin',
        'roles' => ['admin', 'billing'],
        'permissions' => ['widgets:read'],
        'feature_flags' => ['new-dashboard'],
    ]));

    expect($claims->sub)->toBe('user_01')
        ->and($claims->iss)->toBe('https://api.workos.com')
        ->and($claims->clientId)->toBe('client_01')
        ->and($claims->organizationId)->toBe('org_01')
        ->and($claims->role)->toBe('admin')
        ->and($claims->roles)->toBe(['admin', 'billing'])
        ->and($claims->permissions)->toBe(['widgets:read'])
        ->and($claims->featureFlags)->toBe(['new-dashboard'])
        ->and($claims->sessionId)->toBe('session_01')
        ->and($claims->jwtId)->toBe('jwt_01');
});

it('defaults missing optional claims instead of throwing', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload());

    expect($claims->organizationId)->toBeNull()
        ->and($claims->role)->toBeNull()
        ->and($claims->roles)->toBe([])
        ->and($claims->permissions)->toBe([])
        ->and($claims->featureFlags)->toBe([]);
});

it('treats a null-valued optional claim the same as an absent one', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload([
        'org_id' => null,
        'role' => null,
        'permissions' => null,
    ]));

    expect($claims->organizationId)->toBeNull()
        ->and($claims->role)->toBeNull()
        ->and($claims->permissions)->toBe([]);
});

it('rejects a payload missing a required claim', function (): void {
    $payload = claimsPayload();
    unset($payload['sid']);

    expect(fn () => AccessTokenClaims::fromPayload($payload))
        ->toThrow(InvalidArgumentException::class, "missing required claim 'sid'");
});

it('tolerates a token template that omits jti and iat', function (): void {
    // Carried but never acted on, and their presence in a default AuthKit token is
    // still unconfirmed — requiring them would turn a template quirk into a lockout.
    $payload = claimsPayload();
    unset($payload['jti'], $payload['iat']);

    $claims = AccessTokenClaims::fromPayload($payload);

    expect($claims->jwtId)->toBe('')
        ->and($claims->issuedAt)->toBe(0)
        ->and($claims->sub)->toBe('user_01');
});

it('reports impersonation from the act.sub claim', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload(['act' => ['sub' => 'user_admin']]));

    expect($claims->isImpersonated())->toBeTrue()
        ->and($claims->actorId)->toBe('user_admin');
});

it('reports no impersonation when act is absent', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload());

    expect($claims->isImpersonated())->toBeFalse()
        ->and($claims->actorId)->toBeNull();
});

it('counts seconds until expiry from the exp claim', function (): void {
    $claims = AccessTokenClaims::fromPayload(claimsPayload(['exp' => time() + 120]));

    expect($claims->secondsUntilExpiry())->toBeGreaterThan(118)->toBeLessThanOrEqual(120);
});

it('decodes a JWT payload segment without verifying the signature', function (): void {
    $payload = JwtPayloadDecoder::decode(JwtFixture::sign(['sub' => 'user_decoded']));

    expect($payload['sub'])->toBe('user_decoded')
        ->and($payload['client_id'])->toBe(JwtFixture::CLIENT_ID);
});

it('rejects a JWT without three segments', function (): void {
    expect(fn () => JwtPayloadDecoder::decode('not.ajwt'))
        ->toThrow(InvalidArgumentException::class, 'expected 3 JWT segments');
});

it('rejects a JWT whose payload segment is not JSON', function (): void {
    expect(fn () => JwtPayloadDecoder::decode('aGVhZGVy.bm90LWpzb24.c2ln'))
        ->toThrow(InvalidArgumentException::class, 'not valid JSON');
});

it('accepts claims whose issuer and client_id both match', function (): void {
    $validator = new JwtClaimsValidator('https://api.workos.com', 'client_01');

    expect($validator->validate(AccessTokenClaims::fromPayload(claimsPayload())))->toBeTrue();
});

it('rejects claims whose client_id does not match, even with a matching issuer', function (): void {
    $validator = new JwtClaimsValidator('https://api.workos.com', 'client_other');

    expect($validator->validate(AccessTokenClaims::fromPayload(claimsPayload())))->toBeFalse();
});

it('rejects claims whose issuer does not match, even with a matching client_id', function (): void {
    $validator = new JwtClaimsValidator('https://auth.acme.com', 'client_01');

    expect($validator->validate(AccessTokenClaims::fromPayload(claimsPayload())))->toBeFalse();
});

it('skips issuer validation when no issuer is configured but still enforces client_id', function (): void {
    $validator = new JwtClaimsValidator(null, 'client_01');

    expect($validator->validate(AccessTokenClaims::fromPayload(claimsPayload(['iss' => 'https://anything.example']))))->toBeTrue()
        ->and($validator->validate(AccessTokenClaims::fromPayload(claimsPayload(['client_id' => 'client_other']))))->toBeFalse();
});
