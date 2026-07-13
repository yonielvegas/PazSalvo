<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\PazSalvoController;
use App\Http\Controllers\PazSalvoHistoryController;
use App\Http\Controllers\PublicCertificateVerificationController;
use App\Http\Controllers\RolePermissionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [PublicCertificateVerificationController::class, 'home'])->name('public.home');
Route::get('/acceso-institucional', function () {
    return auth()->check()
        ? redirect()->route('paz-salvo.index')
        : redirect()->route('login');
})->name('institutional.access');
Route::get('/validar-paz-salvo', [PublicCertificateVerificationController::class, 'manualForm'])->middleware('throttle:60,1')->name('public.paz-salvo.validate');
Route::post('/validar-paz-salvo', [PublicCertificateVerificationController::class, 'manualVerify'])->middleware('throttle:public-paz-salvo-validation')->name('public.paz-salvo.validate.submit');
Route::get('/verificar/{token}', [PublicCertificateVerificationController::class, 'show'])->middleware('throttle:public-certificate-qr')->name('public.certificates.verify');
Route::get('/verificar/{token}/pdf', [PublicCertificateVerificationController::class, 'pdf'])->middleware('throttle:public-certificate-pdf')->name('public.certificates.pdf');

Route::middleware(['auth', 'idle.timeout', 'internal.network'])->group(function () {
    Route::get('/paz-salvos/consultar', [PazSalvoController::class, 'index'])->middleware('permission:consultar paz y salvo')->name('paz-salvo.index');
    Route::post('/paz-salvos/consultar', [PazSalvoController::class, 'consult'])->middleware(['permission:consultar paz y salvo', 'throttle:20,1'])->name('paz-salvo.consult');
    Route::post('/paz-salvos/generar', [PazSalvoController::class, 'generate'])->middleware(['permission:generar paz y salvo', 'throttle:10,1'])->name('paz-salvo.generate');
    Route::get('/paz-salvos', [PazSalvoHistoryController::class, 'index'])->middleware('permission:ver historial')->name('paz-salvos.index');
    Route::get('/paz-salvos/{pazSalvo}', [PazSalvoHistoryController::class, 'show'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvos.show');
    Route::get('/paz-salvos/{pazSalvo}/pdf', [PazSalvoController::class, 'showPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.pdf');
    Route::get('/paz-salvos/{pazSalvo}/download', [PazSalvoController::class, 'downloadPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.download');
    Route::patch('/paz-salvos/{pazSalvo}/cancelar', [PazSalvoHistoryController::class, 'cancel'])->middleware('permission:anular paz y salvo')->name('paz-salvos.cancel');
    Route::get('/perfil/password', fn () => Inertia::render('profile/password'))->name('profile.password');

    Route::resource('/admin/users', AdminUserController::class)->except(['create', 'edit', 'show'])->middleware('permission:administrar usuarios');
    Route::patch('/admin/users/{user}/toggle', [AdminUserController::class, 'toggle'])->middleware('permission:administrar usuarios')->name('admin.users.toggle');
    Route::patch('/admin/users/{user}/unlock-login-attempts', [AdminUserController::class, 'unlockLoginAttempts'])->middleware('permission:administrar usuarios')->name('admin.users.unlock-login-attempts');
    Route::patch('/admin/users/{user}/release-session', [AdminUserController::class, 'releaseActiveSession'])->middleware('permission:administrar usuarios')->name('admin.users.release-session');
    Route::get('/admin/users/{user}/signature', [AdminUserController::class, 'signature'])->middleware('permission:administrar usuarios')->name('admin.users.signature');

    Route::get('/admin/roles', [RolePermissionController::class, 'index'])->middleware('permission:administrar roles')->name('admin.roles.index');
    Route::put('/admin/roles/{role}/permissions', [RolePermissionController::class, 'update'])->middleware('permission:administrar roles')->name('admin.roles.permissions.update');
});
