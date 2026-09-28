<?php

use App\Models\Client;
use App\Models\GeneralAdminSignature;
use App\Models\PazSalvo;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(DB::connection()->getDatabaseName(), '_testing')) {
    throw new RuntimeException('Public E2E fixtures require a testing database.');
}
$user = User::where('email', 'admin@aaud.gob.pa')->firstOrFail();
$generalAdmin = User::where('email', 'admin.general@aaud.gob.pa')->firstOrFail();
$signature = GeneralAdminSignature::firstOrCreate(['is_active' => true], [
    'user_id' => $generalAdmin->id, 'created_by' => $user->id,
    'signature_path' => 'templates/assets/Firma.jpeg',
]);
$client = Client::firstOrCreate(['client_number' => '1234564787'], ['holder_name' => 'Cliente Público E2E', 'address' => 'DIRECCION PRIVADA E2E']);
$issuedAt = Carbon::parse('2026-09-15 10:30:00', 'America/Panama');
$expiredAt = Carbon::parse('2026-09-16 10:30:00', 'America/Panama');
$validUntil = Carbon::parse('2099-12-31 23:59:59', 'America/Panama');
$databaseTimezone = DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone;
$fixtures = [];
foreach (['valid', 'expired', 'cancelled', 'error', 'missing_pdf'] as $index => $state) {
    $token = sprintf('550e8400-e29b-41d4-a716-%012d', $index + 1);
    $folio = sprintf('CC-%06d-2026', 900001 + $index);
    $path = 'generated/paz-salvos/e2e/'.$folio.'.pdf';
    if ($state !== 'missing_pdf' && $state !== 'error') {
        Storage::disk(config('paz-salvo.disk'))->put($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
    }
    // Eloquent formats datetime attributes without their offset. Bind ISO timestamps
    // directly so a PostgreSQL session in UTC stores the same instant as one in Panama.
    DB::table('paz_salvos')->updateOrInsert(['verification_token' => $token], [
        'sequence_number' => 900001 + $index, 'sequence_year' => 2026, 'folio' => $folio,
        'client_id' => $client->id, 'generated_by' => $user->id, 'agency_id' => $user->agency_id,
        'general_admin_signature_id' => $signature->id, 'numero_factura' => '123456', 'total_balance' => 0,
        'issued_at' => $issuedAt->toIso8601String(),
        'expires_at' => ($state === 'expired' ? $expiredAt : $validUntil)->toIso8601String(),
        'status' => match ($state) {
            'cancelled' => PazSalvo::CANCELLED, 'error' => PazSalvo::ERROR, default => PazSalvo::GENERATED
        },
        'cancelled_at' => $state === 'cancelled' ? $expiredAt->toIso8601String() : null,
        'cancel_reason' => $state === 'cancelled' ? 'MOTIVO PRIVADO E2E' : null,
        'pdf_path' => $state === 'error' ? null : $path,
        'created_at' => $issuedAt->toIso8601String(), 'updated_at' => $issuedAt->toIso8601String(),
    ]);
    $document = PazSalvo::where('verification_token', $token)->firstOrFail();
    $fixtures[$state] = [
        'id' => $document->id, 'token' => $token, 'folio' => $folio,
        'date' => $issuedAt->format('d/m/Y'),
        'issued_at' => $document->issued_at->timezone('America/Panama')->toIso8601String(),
        'laravel_timezone' => config('app.timezone'), 'database_timezone' => $databaseTimezone,
    ];
}
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
