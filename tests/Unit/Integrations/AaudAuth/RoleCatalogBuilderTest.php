<?php

namespace Tests\Unit\Integrations\AaudAuth;

use App\Integrations\AaudAuth\Exceptions\InvalidRoleCatalogException;
use App\Integrations\AaudAuth\Roles\RoleCatalogBuilder;
use App\Models\Role;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleCatalogBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \LogicException('Catalog transformation must never call HTTP'));
    }

    private function role(array $attributes = []): Role
    {
        return (new Role)->setRawAttributes([
            'code' => 'operador', 'name' => 'Operador', 'is_active' => true, 'guard_name' => 'web', ...$attributes,
        ], true);
    }

    public function test_transformation_preserves_names_codes_and_binary_order(): void
    {
        $roles = [
            $this->role(['code' => 'z', 'name' => ' Revisión Técnica ']),
            $this->role(['code' => 'a_']), $this->role(['code' => 'a-']), $this->role(['code' => 'a.']),
        ];
        $before = array_map(fn ($role) => $role->getAttributes(), $roles);
        $result = (new RoleCatalogBuilder)->fromRoles($roles);
        $this->assertSame(['a-', 'a.', 'a_', 'z'], array_column($result, 'code'));
        $this->assertSame(' Revisión Técnica ', $result[3]['name']);
        $this->assertSame($before, array_map(fn ($role) => $role->getAttributes(), $roles));
        foreach ($result as $item) {
            $this->assertSame(['code', 'name', 'active'], array_keys($item));
        }
        Http::assertNothingSent();
    }

    public static function validStates(): array
    {
        return [[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false]];
    }

    #[DataProvider('validStates')]
    public function test_state_conversion_is_explicit(mixed $state, bool $expected): void
    {
        $result = (new RoleCatalogBuilder)->fromRoles([$this->role(['is_active' => $state])]);
        $this->assertSame($expected, $result[0]['active']);
    }

    public static function invalidData(): array
    {
        $cases = [];
        foreach ([null, '', 'Admin', ' contains spaces', 'á', '-start', str_repeat('a', 81), "valid\n", 123] as $value) {
            $cases[] = ['code', $value, 'invalid_code'];
        }
        foreach ([null, '', '   ', "\u{00a0}", "name\nline", "bad\x7f", "\xff", str_repeat('ñ', 256), 123] as $value) {
            $cases[] = ['name', $value, 'invalid_name'];
        }
        foreach ([null, '', 'false', 'true', 'unknown', 2, -1, [], 0.0] as $value) {
            $cases[] = ['is_active', $value, 'invalid_state'];
        }
        $cases[] = ['guard_name', 'api', 'invalid_record'];

        return $cases;
    }

    #[DataProvider('invalidData')]
    public function test_invalid_data_fails_without_exposing_the_bad_value(string $field, mixed $value, string $reason): void
    {
        $this->expectException(InvalidRoleCatalogException::class);
        $this->expectExceptionMessage(match ($reason) {
            'invalid_code' => 'El catálogo contiene un código ausente o inválido.',
            'invalid_name' => 'El catálogo contiene un nombre vacío o inválido.',
            'invalid_state' => 'El catálogo contiene un estado activo inválido.',
            default => 'El catálogo contiene un registro que no corresponde a un rol del guard web.',
        });
        (new RoleCatalogBuilder)->fromRoles([$this->role([$field => $value])]);
    }

    public function test_duplicate_codes_are_not_silently_collapsed(): void
    {
        $this->expectException(InvalidRoleCatalogException::class);
        $this->expectExceptionMessage('El catálogo contiene códigos duplicados.');
        (new RoleCatalogBuilder)->fromRoles([$this->role(), $this->role(['name' => 'Another'])]);
    }

    public function test_missing_attributes_and_non_role_records_are_rejected(): void
    {
        $this->expectException(InvalidRoleCatalogException::class);
        (new RoleCatalogBuilder)->fromRoles([['code' => 'operador']]);
    }

    public function test_empty_catalog_is_rejected(): void
    {
        $this->expectException(InvalidRoleCatalogException::class);
        $this->expectExceptionMessage('El catálogo web está vacío');
        (new RoleCatalogBuilder)->fromRoles([]);
    }

    public function test_catalog_limit_is_checked_without_truncation(): void
    {
        $roles = [];
        for ($i = 0; $i < 1000; $i++) {
            $roles[] = $this->role(['code' => 'role_'.$i]);
        }
        $this->assertCount(1000, (new RoleCatalogBuilder)->fromRoles($roles));
        $roles[] = $this->role(['code' => 'one_more']);
        $this->expectException(InvalidRoleCatalogException::class);
        (new RoleCatalogBuilder)->fromRoles($roles);
    }

    public function test_maximum_length_unicode_name_and_code_are_preserved(): void
    {
        $code = str_repeat('a', 80);
        $name = str_repeat('ñ', 255);
        $this->assertSame([['code' => $code, 'name' => $name, 'active' => true]],
            (new RoleCatalogBuilder)->fromRoles([$this->role(['code' => $code, 'name' => $name])]));
    }
}
