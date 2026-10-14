<?php

declare(strict_types=1);

namespace Luminate\Tenancy\Tests;

use Luminate\Tenancy\Database\AppRoleGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppRoleGuardTest extends TestCase
{
    public function testTheAppRolePasses(): void
    {
        (new AppRoleGuard(['wellms_owner', 'db_bootstrap'], 'wellms_app'))->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testTheOwnerIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new AppRoleGuard(['wellms_owner', 'db_bootstrap'], 'wellms_owner'))->assertSafe();
    }

    public function testTheBootstrapSuperuserIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new AppRoleGuard(['wellms_owner', 'db_bootstrap'], 'db_bootstrap'))->assertSafe();
    }

    public function testTheComparisonIgnoresCaseAndWhitespace(): void
    {
        $this->expectException(RuntimeException::class);
        (new AppRoleGuard(['wellms_owner'], '  WELLMS_Owner '))->assertSafe();
    }

    public function testNoForbiddenListMeansNothingIsForbidden(): void
    {
        (new AppRoleGuard([], 'wellms_owner'))->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testAnEmptyConfiguredUserIsNotMatchedAgainstAnEmptyEntry(): void
    {
        (new AppRoleGuard([''], ''))->assertSafe();
        $this->addToAssertionCount(1);
    }

    public function testTheMessageNamesTheRoleButNeverAPassword(): void
    {
        try {
            (new AppRoleGuard(['wellms_owner'], 'wellms_owner'))->assertSafe();
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wellms_owner', $e->getMessage());
            $this->assertStringNotContainsString('password', strtolower(str_replace('mis-set', '', $e->getMessage())));
        }
    }
}
