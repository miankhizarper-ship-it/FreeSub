# Composer installation note

`vendor/` is not committed (and cannot be produced in every environment), so this
file explains exactly what to install and why.

```bash
composer install --no-dev --classmap-authoritative
```

That pulls `clerkinc/backend-php` ^3.0 (the official Clerk Backend SDK) and its
transitive dependencies.

## What the application needs from the SDK

| Need | Where it is used |
|------|------------------|
| Backend API access (`/v1/users`, `/v1/sessions`, `/v1/clients/verify`, `/v1/me`) | `includes/services/Clerk/ClerkApiClient.php` — implemented with PHP streams so the app keeps working if `vendor/` is absent |
| Session-token verification (`AuthenticateRequest`) | Optional. `includes/services/Clerk/ClerkTokenVerifier.php` implements the same checks natively (signature, kid, alg, iss, exp, nbf, azp, sub) |

## Why verification is implemented natively

1. **Issuer checking.** The SDK verifies the signature, `exp` and `azp`, but it
   does not compare `iss` against the instance. This project requires it.
2. **The handoff design never trusts a browser cookie.** The only token that
   reaches PHP is minted by the browser SDK from a JWT template and is verified
   against the instance JWKS, so the full `AuthenticateRequest` flow (whose job
   is reading `__session`/`Authorization` from a PSR-7 request) is not the path
   that authenticates users here.
3. **No network at boot.** Key material is fetched lazily and cached with a TTL,
   with a single refetch on an unknown `kid` (key rotation).

To use the SDK's `AuthenticateRequest` instead (for example for a future mobile
API that sends `Authorization: Bearer <session token>`), install the dependency
and call it from a new endpoint; nothing in the existing flow has to change:

```php
use Clerk\Backend\Helpers\Jwks\AuthenticateRequest;
use Clerk\Backend\Helpers\Jwks\AuthenticateRequestOptions;

$state = AuthenticateRequest::authenticateRequest($psr7Request, new AuthenticateRequestOptions(
    secretKey: (string) config('clerk.secret_key'),
    authorizedParties: ClerkConfig::authorizedParties(),
));

if (!$state->isAuthenticated()) {
    // reject
}
```

The test suite covers the equivalent guarantees against a locally generated
RSA key pair and a stubbed Clerk Backend API, including expired tokens, tampered
signatures, wrong issuer, wrong authorized party and unknown `kid`.
