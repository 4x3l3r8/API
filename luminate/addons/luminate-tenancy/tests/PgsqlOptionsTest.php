<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Database\PgsqlOptions;
use PDO;
use PHPUnit\Framework\TestCase;

final class PgsqlOptionsTest extends TestCase
{
    private function disable(): int
    {
        return (int) constant('PDO::PGSQL_ATTR_DISABLE_PREPARES');
    }

    public function testEveryPgsqlConnectionGetsThePoolerSafeOptions(): void
    {
        $out = PgsqlOptions::forTransactionPooling([
            'pgsql' => ['driver' => 'pgsql', 'host' => 'pgbouncer'],
            'pgsql_b' => ['driver' => 'pgsql', 'options' => []],
        ]);
        foreach (['pgsql', 'pgsql_b'] as $name) {
            $this->assertTrue($out[$name]['options'][$this->disable()], 'no named server-side statement: nothing to DEALLOCATE');
            $this->assertFalse($out[$name]['options'][PDO::ATTR_EMULATE_PREPARES], 'emulation OFF: Postgres keeps inferring parameter types');
        }
        $this->assertSame('pgbouncer', $out['pgsql']['host'], 'nothing else is touched');
    }

    public function testEmulatedPreparesAreNeverLeftOn(): void
    {
        // Emulation inlines Laravel's bool-as-int bindings as integer literals: `revoked = 0` against a boolean column fails.
        $out = PgsqlOptions::forTransactionPooling(['pgsql' => ['driver' => 'pgsql', 'options' => [PDO::ATTR_EMULATE_PREPARES => true]]]);
        $this->assertFalse($out['pgsql']['options'][PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testExistingOptionsAreKept(): void
    {
        $out = PgsqlOptions::forTransactionPooling(['pgsql' => ['driver' => 'pgsql', 'options' => [PDO::ATTR_PERSISTENT => true, PDO::ATTR_TIMEOUT => 3]]]);
        $this->assertTrue($out['pgsql']['options'][PDO::ATTR_PERSISTENT]);
        $this->assertSame(3, $out['pgsql']['options'][PDO::ATTR_TIMEOUT]);
        $this->assertTrue($out['pgsql']['options'][$this->disable()]);
    }

    public function testOtherDriversAreLeftAlone(): void
    {
        $in = ['mysql' => ['driver' => 'mysql'], 'sqlite' => ['driver' => 'sqlite', 'options' => [PDO::ATTR_TIMEOUT => 3]], 'odd' => []];
        $this->assertSame($in, PgsqlOptions::forTransactionPooling($in));
    }

    public function testAMalformedOptionsValueDoesNotCrash(): void
    {
        $out = PgsqlOptions::forTransactionPooling(['pgsql' => ['driver' => 'pgsql', 'options' => 'nonsense']]);
        $this->assertSame([PDO::ATTR_EMULATE_PREPARES => false, $this->disable() => true], $out['pgsql']['options']);
    }

    public function testNothingToDoIsNotAnError(): void
    {
        $this->assertSame([], PgsqlOptions::forTransactionPooling([]));
    }

    public function testTheOptionIsIdempotent(): void
    {
        $once = PgsqlOptions::forTransactionPooling(['pgsql' => ['driver' => 'pgsql']]);
        $this->assertSame($once, PgsqlOptions::forTransactionPooling($once));
    }
}
