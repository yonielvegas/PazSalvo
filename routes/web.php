<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ForcedPasswordChangeController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\PazSalvoController;
use App\Http\Controllers\PazSalvoHistoryController;
use App\Http\Controllers\RolePermissionController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('paz-salvo.index')
        : redirect()->route('login');
})->name('institutional.access');
Route::get('/acceso-institucional', fn () => redirect()->route('institutional.access'));
Route::get('/healthz', HealthCheckController::class)->middleware('internal.network')->name('healthz');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'single.session', 'idle.timeout', 'internal.network'])->group(function () {
    Route::get('/cambiar-contrasena-obligatoria', [ForcedPasswordChangeController::class, 'edit'])->name('password.force-change');
    Route::put('/cambiar-contrasena-obligatoria', [ForcedPasswordChangeController::class, 'update'])->name('password.force-change.update');
});

Route::middleware(['auth', 'single.session', 'idle.timeout', 'internal.network', 'password.changed'])->group(function () {
    Route::get('/paz-salvos/consultar', [PazSalvoController::class, 'index'])->middleware('permission:consultar paz y salvo')->name('paz-salvo.index');
    Route::post('/paz-salvos/consultar', [PazSalvoController::class, 'consult'])->middleware(['permission:consultar paz y salvo', 'throttle:20,1'])->name('paz-salvo.consult');
    Route::post('/paz-salvos/generar', [PazSalvoController::class, 'generate'])->middleware(['permission:generar paz y salvo', 'throttle:10,1'])->name('paz-salvo.generate');
    Route::get('/paz-salvos', [PazSalvoHistoryController::class, 'index'])->middleware('permission:ver historial')->name('paz-salvos.index');
    Route::get('/paz-salvos/{pazSalvo}', [PazSalvoHistoryController::class, 'show'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvos.show');
    Route::get('/paz-salvos/{pazSalvo}/pdf', [PazSalvoController::class, 'showPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.pdf');
    Route::get('/paz-salvos/{pazSalvo}/download', [PazSalvoController::class, 'downloadPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.download');
    Route::patch('/paz-salvos/{pazSalvo}/cancelar', [PazSalvoHistoryController::class, 'cancel'])->middleware('permission:anular paz y salvo')->name('paz-salvos.cancel');
    Route::resource('/admin/users', AdminUserController::class)->except(['create', 'edit', 'show'])->middleware('permission:administrar usuarios');
    Route::patch('/admin/users/{user}/toggle', [AdminUserController::class, 'toggle'])->middleware('permission:administrar usuarios')->name('admin.users.toggle');
    Route::patch('/admin/users/{user}/unlock-login-attempts', [AdminUserController::class, 'unlockLoginAttempts'])->middleware('permission:administrar usuarios')->name('admin.users.unlock-login-attempts');
    Route::patch('/admin/users/{user}/release-session', [AdminUserController::class, 'releaseActiveSession'])->middleware('permission:administrar usuarios')->name('admin.users.release-session');
    Route::patch('/admin/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('permission:administrar usuarios')->name('admin.users.reset-password');
    Route::get('/admin/users/{user}/signature', [AdminUserController::class, 'signature'])->middleware('permission:administrar usuarios')->name('admin.users.signature');

    Route::get('/admin/roles', [RolePermissionController::class, 'index'])->middleware('permission:administrar roles')->name('admin.roles.index');
    Route::put('/admin/roles/{role}/permissions', [RolePermissionController::class, 'update'])->middleware('permission:administrar roles')->name('admin.roles.permissions.update');
});
