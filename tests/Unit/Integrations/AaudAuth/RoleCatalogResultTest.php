<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Http\RoleCatalogResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoleCatalogResultTest extends TestCase
{
    public function test_current_and_historical_receipts_have_different_meanings(): void
    {
        $uuid = 'a0000000-0000-4000-8000-000000000001';
        foreach (['applied', 'duplicate'] as $status) {
            $this->assertTrue((new RoleCatalogResult($status, 1, $uuid, 1, false))->confirmsCurrentCatalog());
        }
        $historical = new RoleCatalogResult('duplicate', 1, $uuid, 2, true);
        $this->assertFalse($historical->confirmsCurrentCatalog());
        $this->assertSame(2, $historical->currentVersion);
    }

    public static function inconsistentResults(): array
    {
        return [['success', 1, 1, false], ['applied', 0, 1, true], ['applied', 1, 2, true],
            ['duplicate', 2, 1, false], ['duplicate', 1, 2, false], ['duplicate', 1, 1, true],
            ['duplicate', 1, PHP_INT_MAX, true]];
    }

    #[DataProvider('inconsistentResults')]
    public function test_inconsistent_receipts_cannot_be_constructed(string $status, int $version, int $current, bool $historical): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RoleCatalogResult($status, $version, 'a0000000-0000-4000-8000-000000000001', $current, $historical);
    }
}
