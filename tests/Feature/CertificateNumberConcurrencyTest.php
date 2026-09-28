<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CertificateNumberConcurrencyTest extends TestCase
{
    public static function concurrentCases(): array
    {
        return [
            'new year, both succeed' => [false, false],
            'new year, first rolls back' => [false, true],
            'existing year, both succeed' => [true, false],
            'existing year, first rolls back' => [true, true],
        ];
    }

    #[DataProvider('concurrentCases')]
    public function test_parallel_reservations_are_serialized_and_rollback_releases_folio(bool $existingYear, bool $rollback): void
    {
        $connection = config('database.connections.pgsql');
        $this->assertSame('pgsql', config('database.default'));
        $this->assertStringEndsWith('_testing', $connection['database']);
        $schema = 'sequence_test_'.bin2hex(random_bytes(8));
        $barrier = sys_get_temp_dir().'/'.$schema;
        DB::statement('CREATE SCHEMA '.$schema);
        $first = $second = null;
        try {
            DB::statement('CREATE TABLE '.$schema.'.certificate_sequences (year integer PRIMARY KEY, last_number integer NOT NULL, created_at timestamp, updated_at timestamp)');
            if ($existingYear) {
                DB::table($schema.'.certificate_sequences')->insert(['year' => 2026, 'last_number' => 10]);
            }
            $env = [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
                'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
                'DB_PASSWORD' => (string) $connection['password'], 'TEST_SEQUENCE_SCHEMA' => $schema,
            ];
            $first = new Process([PHP_BINARY, base_path('tests/helpers/reserve-certificate-number.php')], base_path(), $env + ['TEST_SEQUENCE_BARRIER' => $barrier, 'TEST_SEQUENCE_ROLLBACK' => $rollback ? '1' : '0']);
            $first->setTimeout(15)->start();
            $this->assertTrue($first->waitUntil(fn () => str_contains($first->getOutput(), 'reserved:')));

            $second = new Process([PHP_BINARY, base_path('tests/helpers/reserve-certificate-number.php')], base_path(), $env);
            $second->setTimeout(15)->start();
            $this->assertTrue($second->waitUntil(fn () => str_contains($second->getOutput(), 'started')));
            usleep(200000);
            $this->assertTrue($second->isRunning(), 'The second transaction must wait for the first lock.');
            $this->assertStringNotContainsString('reserved:', $second->getOutput());
            touch($barrier);
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $initial = $existingYear ? 10 : 0;
            $this->assertStringContainsString('reserved:'.($initial + 1), $first->getOutput());
            $expected = $initial + ($rollback ? 1 : 2);
            $this->assertStringContainsString('reserved:'.$expected, $second->getOutput());
            $this->assertSame($expected, DB::table($schema.'.certificate_sequences')->value('last_number'));
        } finally {
            $first?->stop();
            $second?->stop();
            if (is_file($barrier)) {
                unlink($barrier);
            }
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
