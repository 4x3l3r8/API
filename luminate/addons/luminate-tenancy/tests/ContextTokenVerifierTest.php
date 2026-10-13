<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Context\ContextRejected;
use Luminate\Tenancy\Context\ContextTokenVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContextTokenVerifierTest extends TestCase
{
    private function verifier(array $keys = [TokenFactory::KEY, TokenFactory::PREVIOUS_KEY], int $now = TokenFactory::NOW): ContextTokenVerifier
    {
        return new ContextTokenVerifier($keys, 300, 30, static fn () => $now);
    }

    private function assertRejected(string $token, string $reason, int $status, ?ContextTokenVerifier $v = null): void
    {
        try {
            ($v ?? $this->verifier())->verify($token);
            $this->fail('token was accepted');
        } catch (ContextRejected $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public function testAcceptsAValidToken(): void
    {
        $ctx = $this->verifier()->verify(TokenFactory::mint(TokenFactory::claims()));
        $this->assertSame('tenant-alpha', $ctx->tid);
        $this->assertSame('pooled', $ctx->mode);
        $this->assertSame('shard-1', $ctx->connection);
        $this->assertSame('alpha', $ctx->cachePrefix);
        $this->assertSame('jti-0001', $ctx->jti);
    }

    public function testAcceptsThePreviousKeyForRotation(): void
    {
        $token = TokenFactory::mint(TokenFactory::claims(), TokenFactory::PREVIOUS_KEY);
        $this->assertSame('tenant-alpha', $this->verifier()->verify($token)->tid);
    }

    public function testRejectsTheOldKeyOnceItIsNoLongerConfigured(): void
    {
        $token = TokenFactory::mint(TokenFactory::claims(), TokenFactory::PREVIOUS_KEY);
        $this->assertRejected($token, 'bad_signature', 401, $this->verifier([TokenFactory::KEY]));
    }

    public function testRejectsAWrongKey(): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims(), str_repeat('x', 40)), 'bad_signature', 401);
    }

    public function testRejectsATamperedPayload(): void
    {
        [$h, , $s] = explode('.', TokenFactory::mint(TokenFactory::claims()));
        $forged = TokenFactory::b64(json_encode(TokenFactory::claims(['tid' => 'tenant-beta']), JSON_THROW_ON_ERROR));
        $this->assertRejected("$h.$forged.$s", 'bad_signature', 401);
    }

    public function testRejectsAlgNone(): void
    {
        $h = TokenFactory::b64(json_encode(['alg' => 'none']));
        $p = TokenFactory::b64(json_encode(TokenFactory::claims()));
        $this->assertRejected("$h.$p." . TokenFactory::b64('x'), 'bad_algorithm', 401);
        $this->assertRejected("$h.$p.", 'bad_algorithm', 401); // alg is checked before the empty signature is decoded
        $okHeader = TokenFactory::b64(json_encode(['alg' => 'HS256']));
        $this->assertRejected("$okHeader.$p.", 'malformed', 401);
    }

    #[DataProvider('wrongAlgorithms')]
    public function testRejectsOtherAlgorithms(array $header): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims(), TokenFactory::KEY, $header), 'bad_algorithm', 401);
    }

    public static function wrongAlgorithms(): array
    {
        return [
            'HS512' => [['alg' => 'HS512']],
            'RS256' => [['alg' => 'RS256']],
            'lowercase' => [['alg' => 'hs256']],
            'missing' => [['typ' => 'JWT']],
            'array' => [['alg' => ['HS256']]],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->assertRejected($token, 'malformed', 401);
    }

    public static function malformedTokens(): array
    {
        $valid = TokenFactory::mint(TokenFactory::claims());

        return [
            'empty segments' => ['..'],
            'two parts' => ['a.b'],
            'four parts' => [$valid . '.extra'],
            'padding' => [$valid . '='],
            'standard alphabet' => [str_replace('-', '+', 'a+b/c.d.e')],
            'not json' => [TokenFactory::b64('nope') . '.' . TokenFactory::b64('nope') . '.' . TokenFactory::b64('s')],
            'whitespace' => [' ' . $valid],
        ];
    }

    #[DataProvider('missingOrBadClaims')]
    public function testRejectsInvalidClaims(array $claims): void
    {
        $this->assertRejected(TokenFactory::mint($claims), 'invalid_claims', 401);
    }

    public static function missingOrBadClaims(): array
    {
        $good = TokenFactory::claims();
        $without = static function (string $k) use ($good): array {
            unset($good[$k]);

            return $good;
        };
        $withoutPlacement = static function (string $k) use ($good): array {
            unset($good['placement'][$k]);

            return $good;
        };
        $set = static fn (array $o): array => array_replace_recursive($good, $o);

        return [
            'no tid' => [$without('tid')],
            'no status' => [$without('status')],
            'no placement' => [$without('placement')],
            'no iat' => [$without('iat')],
            'no exp' => [$without('exp')],
            'no jti' => [$without('jti')],
            'no mode' => [$withoutPlacement('mode')],
            'no connection' => [$withoutPlacement('connection')],
            'no bucket' => [$withoutPlacement('bucket')],
            'no cache_prefix' => [$withoutPlacement('cache_prefix')],
            'tid with slash' => [$set(['tid' => 'a/b'])],
            'tid with sql' => [$set(['tid' => "x'; drop table users;--"])],
            'tid too long' => [$set(['tid' => str_repeat('a', 65)])],
            'tid empty' => [$set(['tid' => ''])],
            'tid number' => [$set(['tid' => 42])],
            'iat string' => [$set(['iat' => '1799999990'])],
            'exp float' => [$set(['exp' => 1_800_000_290.5])],
            'unknown mode' => [$set(['placement' => ['mode' => 'shared']])],
            'dsn as connection' => [$set(['placement' => ['connection' => 'pgsql://u:p@host/db']])],
            'host as connection' => [$set(['placement' => ['connection' => 'db.internal:5432']])],
            'bucket traversal' => [$set(['placement' => ['bucket' => 'wellms/../other']])],
            'bucket trailing slash' => [$set(['placement' => ['bucket' => 'wellms/']])],
            'cache prefix with space' => [$set(['placement' => ['cache_prefix' => 'a b']])],
            'placement is a list' => [array_merge($good, ['placement' => ['x']])],
            'exp before iat' => [$set(['iat' => TokenFactory::NOW, 'exp' => TokenFactory::NOW - 5])],
            'lifetime 301s' => [$set(['iat' => TokenFactory::NOW - 10, 'exp' => TokenFactory::NOW - 10 + 301])],
        ];
    }

    public function testAcceptsExactlyMaxLifetime(): void
    {
        $claims = TokenFactory::claims(['iat' => TokenFactory::NOW - 10, 'exp' => TokenFactory::NOW - 10 + 300]);
        $this->assertSame('tenant-alpha', $this->verifier()->verify(TokenFactory::mint($claims))->tid);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims()), 'expired', 401, $this->verifier(now: TokenFactory::NOW + 290));
    }

    public function testRejectsATokenIssuedBeyondLeewayInTheFuture(): void
    {
        $claims = TokenFactory::claims(['iat' => TokenFactory::NOW + 31, 'exp' => TokenFactory::NOW + 300]);
        $this->assertRejected(TokenFactory::mint($claims), 'not_yet_valid', 401);
    }

    public function testAcceptsSmallClockSkewWithinLeeway(): void
    {
        $claims = TokenFactory::claims(['iat' => TokenFactory::NOW + 20, 'exp' => TokenFactory::NOW + 300]);
        $this->assertSame('tenant-alpha', $this->verifier()->verify(TokenFactory::mint($claims))->tid);
    }

    #[DataProvider('inactiveStatuses')]
    public function testRefusesAnyStatusOtherThanActiveWith403(string $status): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims(['status' => $status])), 'tenant_inactive', 403);
    }

    public static function inactiveStatuses(): array
    {
        return [['suspended'], ['provisioning'], ['ACTIVE'], ['active '], ['deleted']];
    }

    public function testFailsClosedWhenNoKeyIsConfigured(): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims()), 'misconfigured', 503, $this->verifier([]));
    }

    public function testFailsClosedWhenAKeyIsTooShort(): void
    {
        $this->assertRejected(TokenFactory::mint(TokenFactory::claims(), 'short'), 'misconfigured', 503, $this->verifier(['short']));
    }
}
