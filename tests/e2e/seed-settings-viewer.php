<?php

use App\Models\Agency;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing')) {
    throw new RuntimeException('E2E fixtures may only be created in testing.');
}

$agency = Agency::where('code', 'PH-MULTIPLAZA')->firstOrFail();
$role = Role::firstOrCreate(['name' => 'settings_viewer_e2e', 'guard_name' => 'web']);
$role->syncPermissions(['consultar paz y salvo', 'settings.view', 'settings.agencies.view', 'settings.roles.view']);
$user = User::firstOrCreate(['email' => 'settings-viewer-e2e@aaud.gob.pa'], [
    'name' => 'Settings Viewer E2E',
    'password' => Hash::make(getenv('E2E_PASSWORD') ?: 'e2e-test-password'),
    'agency_id' => $agency->id,
    'is_active' => true,
    'email_verified_at' => now(),
    'password_changed_at' => now(),
]);
$user->assignRole($role);
