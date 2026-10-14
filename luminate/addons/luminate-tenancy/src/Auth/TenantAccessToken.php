<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Auth;

use Illuminate\Support\Facades\Log;
use Laravel\Passport\Bridge\AccessToken;
use League\OAuth2\Server\CryptKey;
use Luminate\Tenancy\Context\CurrentTenant;

/**
 * Passport access-token entity that stamps the issuing tenant into the JWT as the `tenant` claim (contract §D.5).
 * Registered through Passport::useAccessTokenEntity(), a supported extension point, so no Passport/Wellms code is patched.
 *
 * Personal access tokens (what /api/auth/login and impersonate return) go through the same repository, so they carry it too.
 * With no active tenant at issue time the token is issued WITHOUT the claim and is therefore refused on every request.
 */
final class TenantAccessToken extends AccessToken
{
    private ?CryptKey $signingKey = null;

    public function setPrivateKey(CryptKey $privateKey)
    {
        $this->signingKey = $privateKey;
        parent::setPrivateKey($privateKey);
    }

    public function __toString()
    {
        $jwt = parent::__toString();

        $tid = app(CurrentTenant::class)->id();
        if ($tid === null || $this->signingKey === null) {
            Log::warning('access token issued with no active tenant; it carries no tenant claim and will be refused');

            return $jwt;
        }

        return JwtClaims::add($jwt, TenantTokenBinding::CLAIM, $tid, $this->signingKey->getKeyContents(), $this->signingKey->getPassPhrase());
    }
}
