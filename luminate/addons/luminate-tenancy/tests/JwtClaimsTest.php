<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Auth\JwtClaims;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtClaimsTest extends TestCase
{
    private string $privatePem;
    private string $publicPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privatePem = $pem;
        $this->publicPem = openssl_pkey_get_details($key)['key'];
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** A token shaped like the one league/oauth2-server + lcobucci produce for Passport. */
    private function passportLikeToken(?string $pem = null, array $header = ['typ' => 'JWT', 'alg' => 'RS256']): string
    {
        $claims = [
            'aud' => '9a1c0e7e-1111-2222-3333-444455556666',
            'jti' => 'f00dfeed',
            'iat' => 1790000000.123456,
            'nbf' => 1790000000.123456,
            'exp' => 1821536000.5,
            'sub' => '42',
            'scopes' => ['admin', 'read/write'],
        ];
        $input = $this->b64(json_encode($header)) . '.' . $this->b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        openssl_sign($input, $sig, openssl_pkey_get_private($pem ?? $this->privatePem), OPENSSL_ALGO_SHA256);

        return $input . '.' . $this->b64($sig);
    }

    private function signatureValid(string $jwt): bool
    {
        [$h, $p, $s] = explode('.', $jwt);
        $raw = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);

        return openssl_verify($h . '.' . $p, $raw, $this->publicPem, OPENSSL_ALGO_SHA256) === 1;
    }

    public function testAddingAClaimKeepsEveryExistingClaimAndReSigns(): void
    {
        $original = $this->passportLikeToken();
        $before = JwtClaims::peek($original);

        $tenanted = JwtClaims::add($original, 'tenant', 't-alpha', $this->privatePem);
        $after = JwtClaims::peek($tenanted);

        $this->assertSame('t-alpha', $after['tenant']);
        unset($after['tenant']);
        $this->assertSame($before, $after, 'upstream claims must be preserved exactly (values and types)');
        $this->assertTrue($this->signatureValid($original), 'sanity: the fixture token verifies');
        $this->assertTrue($this->signatureValid($tenanted), 'the re-signed token must verify with the public key');
    }

    public function testTheHeaderIsKeptByteForByte(): void
    {
        $original = $this->passportLikeToken();
        $tenanted = JwtClaims::add($original, 'tenant', 't-alpha', $this->privatePem);
        $this->assertSame(explode('.', $original)[0], explode('.', $tenanted)[0]);
    }

    public function testAPassphraseProtectedKeyWorks(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $protected, 'correct horse');
        openssl_pkey_export($key, $plain);
        $this->publicPem = openssl_pkey_get_details($key)['key'];
        $jwt = $this->passportLikeToken($plain);

        $tenanted = JwtClaims::add($jwt, 'tenant', 't-beta', $protected, 'correct horse');
        $this->assertSame('t-beta', JwtClaims::peek($tenanted)['tenant']);
        $this->assertTrue($this->signatureValid($tenanted));
    }

    public function testAWrongPassphraseFails(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $protected, 'right');
        $this->expectException(RuntimeException::class);
        JwtClaims::add($this->passportLikeToken(), 'tenant', 't-alpha', $protected, 'wrong');
    }

    public function testOnlyRs256TokensAreReSigned(): void
    {
        $this->expectException(RuntimeException::class);
        JwtClaims::add($this->passportLikeToken(null, ['typ' => 'JWT', 'alg' => 'none']), 'tenant', 't-alpha', $this->privatePem);
    }

    public function testNonJwtInputIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        JwtClaims::add('not-a-jwt', 'tenant', 't-alpha', $this->privatePem);
    }

    public function testPeekReadsClaimsWithoutVerifying(): void
    {
        $forged = $this->b64('{"alg":"RS256"}') . '.' . $this->b64('{"tenant":"t-alpha"}') . '.' . $this->b64('garbage-signature');
        $this->assertSame(['tenant' => 't-alpha'], JwtClaims::peek($forged), 'peek is NOT proof of anything; it only reads');
        $this->assertFalse($this->signatureValid($forged), 'and the forged token does not verify');
    }

    public function testPeekReturnsNullForAnythingThatIsNotAJwtObject(): void
    {
        foreach (['', 'abc', 'a.b', 'a.b.c.d', '..', 'e30.not base64.sig', $this->b64('x') . '.' . $this->b64('[1,2]') . '.s', $this->b64('x') . '.' . $this->b64('"str"') . '.s'] as $bad) {
            $this->assertNull(JwtClaims::peek($bad), "peek must reject: {$bad}");
        }
    }
}
