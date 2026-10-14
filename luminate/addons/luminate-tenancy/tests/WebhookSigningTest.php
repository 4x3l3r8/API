<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Webhooks\Backoff;
use Luminate\Tenancy\Webhooks\WebhookSettings;
use Luminate\Tenancy\Webhooks\WebhookSigner;
use PHPUnit\Framework\TestCase;

final class WebhookSigningTest extends TestCase
{
    private const SECRET = 'a-webhook-secret-of-sufficient-length';
    private const DELIVERY = '6f1c2a9e-0b7d-4c1e-9d55-3f0a1b2c3d4e';
    private const BODY = '{"event":"enrolment.created","delivery_id":"6f1c2a9e","tenant_id":"t-alpha","occurred_at":"2026-10-14T12:00:00Z","data":{"wellms_user_id":7,"wellms_course_id":3}}';

    public function testTheSignatureIsExactlyTheContractFormula(): void
    {
        // contract v4 §F: sha256=<hex HMAC-SHA256(webhook_secret, "{delivery_id}.{timestamp}.{raw_body}")>
        $expected = 'sha256=' . hash_hmac('sha256', self::DELIVERY . '.1791900000.' . self::BODY, self::SECRET);
        $this->assertSame($expected, WebhookSigner::signature(self::SECRET, self::DELIVERY, 1791900000, self::BODY));
        $this->assertMatchesRegularExpression('/^sha256=[0-9a-f]{64}$/', $expected);
    }

    public function testAKnownVectorSoReceiversInOtherLanguagesCanCheckThemselves(): void
    {
        // Fixed inputs -> fixed output. A .NET receiver must reproduce this exact string.
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', 'd1.1700000000.{"a":1}', 'k' . str_repeat('x', 20)),
            WebhookSigner::signature('k' . str_repeat('x', 20), 'd1', 1700000000, '{"a":1}')
        );
    }

    public function testEveryInputIsBound(): void
    {
        $base = WebhookSigner::signature(self::SECRET, self::DELIVERY, 1791900000, self::BODY);
        $this->assertNotSame($base, WebhookSigner::signature('another-secret-of-sufficient-length', self::DELIVERY, 1791900000, self::BODY), 'secret');
        $this->assertNotSame($base, WebhookSigner::signature(self::SECRET, 'other-delivery', 1791900000, self::BODY), 'delivery id: no cross-delivery reuse');
        $this->assertNotSame($base, WebhookSigner::signature(self::SECRET, self::DELIVERY, 1791900001, self::BODY), 'timestamp');
        $this->assertNotSame($base, WebhookSigner::signature(self::SECRET, self::DELIVERY, 1791900000, self::BODY . ' '), 'a single extra byte of body');
    }

    public function testTheDotJoinedFormulaIsOnlyUnambiguousBecauseIdsAreUuidsAndTimestampsAreDigits(): void
    {
        // A property of the contract's formula, "{delivery_id}.{timestamp}.{raw_body}", not something Wellms can fix alone:
        // joined with dots, a dotted id would let two different (id, timestamp, body) triples sign the same string.
        $this->assertSame(
            WebhookSigner::signature(self::SECRET, 'a', 1, '2.x'),
            WebhookSigner::signature(self::SECRET, 'a.1', 2, 'x'),
            'documents the ambiguity for ids that contain a dot'
        );
        // It cannot be exploited because Wellms only ever signs a UUID (36 chars, no dot) and a digits-only timestamp, which
        // pins where each field ends. A receiver should enforce those shapes before it verifies (the live test checks Wellms
        // really sends them); this is a note for the contract owner, not a wire change.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', self::DELIVERY);
        $this->assertStringNotContainsString('.', self::DELIVERY);
    }

    public function testVerifyAcceptsTheRightSignatureAndRejectsTamperingAndGarbage(): void
    {
        $sig = WebhookSigner::signature(self::SECRET, self::DELIVERY, 1791900000, self::BODY);
        $this->assertTrue(WebhookSigner::verify($sig, self::SECRET, self::DELIVERY, 1791900000, self::BODY));
        $this->assertFalse(WebhookSigner::verify($sig, self::SECRET, self::DELIVERY, 1791900000, str_replace('7', '8', self::BODY)));
        $this->assertFalse(WebhookSigner::verify(strtoupper($sig), self::SECRET, self::DELIVERY, 1791900000, self::BODY), 'case matters');
        $this->assertFalse(WebhookSigner::verify('', self::SECRET, self::DELIVERY, 1791900000, self::BODY));
        $this->assertFalse(WebhookSigner::verify(substr($sig, 7), self::SECRET, self::DELIVERY, 1791900000, self::BODY), 'prefix required');
    }

    // ---- backoff -----------------------------------------------------------------------------------------------------------------

    public function testBackoffFollowsTheContractScheduleAndRepeatsTheLastDelay(): void
    {
        $b = new Backoff([60, 300, 1800, 7200, 21600], 86400);
        $this->assertSame([60, 300, 1800, 7200, 21600, 21600, 21600], array_map($b->delayAfter(...), [1, 2, 3, 4, 5, 6, 7]));
    }

    public function testWithTheDefaultsADeliveryGetsEightAttemptsOverAboutTwentyOneHours(): void
    {
        $b = new Backoff([60, 300, 1800, 7200, 21600], 86400);
        $age = 0;
        $attempt = 1;
        $times = [0];
        while (!$b->exhausted($attempt, $age)) {
            $age += $b->delayAfter($attempt);
            $attempt++;
            $times[] = $age;
        }
        $this->assertSame(8, $attempt, 'attempts made before giving up');
        $this->assertSame([0, 60, 360, 2160, 9360, 30960, 52560, 74160], $times);
        $this->assertLessThanOrEqual(86400, $age, 'never schedules beyond the cap');
    }

    public function testExhaustedLooksAtWhereTheNextAttemptWouldLand(): void
    {
        $b = new Backoff([100], 1000);
        $this->assertFalse($b->exhausted(1, 900));  // next at 1000 == cap: allowed
        $this->assertTrue($b->exhausted(1, 901));   // next at 1001 > cap: dead
    }

    public function testBackoffRejectsNonsense(): void
    {
        foreach ([[[], 10], [[-1], 10], [[5], 0]] as [$delays, $max]) {
            try {
                new Backoff($delays, $max);
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- settings: fail closed ------------------------------------------------------------------------------------------------------

    public function testNoUrlMeansTheModuleIsOffNotBroken(): void
    {
        foreach ([null, '', '   '] as $url) {
            $s = new WebhookSettings($url, self::SECRET);
            $this->assertFalse($s->enabled());
            $this->assertNotNull($s->unusable());
        }
    }

    public function testAUrlWithoutAUsableSecretIsRefusedNotSentUnsigned(): void
    {
        foreach ([null, '', 'short'] as $secret) {
            $s = new WebhookSettings('https://luminate.example/hooks', $secret);
            $this->assertTrue($s->enabled());
            $this->assertStringContainsString('secret', (string) $s->unusable());
        }
    }

    public function testOnlyHttpsIsAcceptedUnlessHttpIsExplicitlyAllowedForDev(): void
    {
        $this->assertNull((new WebhookSettings('https://luminate.example/hooks', self::SECRET))->unusable());
        $this->assertStringContainsString('https', (string) (new WebhookSettings('http://luminate.example/hooks', self::SECRET))->unusable());
        $this->assertNull((new WebhookSettings('http://receiver:8099/hook', self::SECRET, allowHttp: true))->unusable());
        $this->assertNotNull((new WebhookSettings('ftp://luminate.example/hooks', self::SECRET, allowHttp: true))->unusable());
        $this->assertNotNull((new WebhookSettings('file:///etc/passwd', self::SECRET, allowHttp: true))->unusable());
    }

    public function testCredentialsEmbeddedInTheUrlAndMalformedUrlsAreRefused(): void
    {
        $this->assertStringContainsString('credentials', (string) (new WebhookSettings('https://user:pw@luminate.example/hooks', self::SECRET))->unusable());
        $this->assertNotNull((new WebhookSettings('https://', self::SECRET))->unusable());
        $this->assertNotNull((new WebhookSettings('not a url', self::SECRET))->unusable());
    }

    public function testSettingsAreBuiltFromConfig(): void
    {
        $s = WebhookSettings::fromConfig(['url' => 'https://x.example/h', 'secret' => self::SECRET, 'allow_http' => false, 'timeout' => 7, 'connect_timeout' => 3]);
        $this->assertSame(7, $s->timeout);
        $this->assertSame(3, $s->connectTimeout);
        $this->assertNull($s->unusable());
    }
}
