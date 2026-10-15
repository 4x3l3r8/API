<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Provisioning\AdminSecret;
use PHPUnit\Framework\TestCase;

final class AdminSecretTest extends TestCase
{
    public function testGeneratedSecretsAreLongBase62AndAcceptedByTheirOwnPolicy(): void
    {
        $s = AdminSecret::generate();
        $this->assertSame(48, strlen($s));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $s);
        $this->assertNull(AdminSecret::problem($s));
    }

    public function testGeneratedSecretsDoNotRepeat(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $seen[AdminSecret::generate()] = true;
        }
        $this->assertCount(500, $seen);
    }

    public function testEveryCharacterClassOccursOverManyDraws(): void
    {
        $all = '';
        for ($i = 0; $i < 200; $i++) {
            $all .= AdminSecret::generate();
        }
        $this->assertMatchesRegularExpression('/[A-Z]/', $all);
        $this->assertMatchesRegularExpression('/[a-z]/', $all);
        $this->assertMatchesRegularExpression('/[0-9]/', $all);
        $this->assertGreaterThanOrEqual(60, count(array_unique(str_split($all))), 'the alphabet is really used');
    }

    /** @dataProvider rejected */
    public function testWeakOrMalformedSuppliedSecretsAreRefused(mixed $secret, string $why): void
    {
        $this->assertStringContainsString($why, (string) AdminSecret::problem($secret));
    }

    public static function rejected(): array
    {
        return [
            'not a string' => [12345678901234567890123456789012, 'string'],
            'null' => [null, 'string'],
            'too short' => [str_repeat('aB3', 10), '32-128'],
            'too long' => [str_repeat('aB3xY9', 22), '32-128'],
            'contains a space' => [str_repeat('aB3xY9kQ', 4) . ' ' . 'zz', 'printable'],
            'contains a control character' => [str_repeat('aB3xY9kQ', 5) . "\n", 'printable'],
            'non-ascii' => [str_repeat('aB3xY9kQ', 5) . 'é', 'printable'],
            'low variety' => [str_repeat('ab', 20), 'distinct'],
        ];
    }

    public function testBoundariesAreInclusive(): void
    {
        $ok32 = substr(str_repeat('aB3xY9kQmN', 4), 0, 32);
        $ok128 = substr(str_repeat('aB3xY9kQmN', 13), 0, 128);
        $this->assertNull(AdminSecret::problem($ok32));
        $this->assertNull(AdminSecret::problem($ok128));
        $this->assertNotNull(AdminSecret::problem(substr($ok32, 0, 31)));
        $this->assertNotNull(AdminSecret::problem($ok128 . 'a'));
    }

    public function testSymbolsAreAllowedInASuppliedSecret(): void
    {
        $this->assertNull(AdminSecret::problem('Correct-Horse_Battery.Staple!42' . '#x'));
    }
}
