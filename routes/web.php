<?php

use App\Http\Controllers\PazSalvoController;
use App\Http\Controllers\PazSalvoHistoryController;
use App\Http\Controllers\PublicCertificateVerificationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/verificar/{token}', [PublicCertificateVerificationController::class, 'show'])->middleware('throttle:60,1')->name('public.certificates.verify');
Route::get('/verificar/{token}/pdf', [PublicCertificateVerificationController::class, 'pdf'])->middleware('throttle:30,1')->name('public.certificates.pdf');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/paz-salvos/consultar');
    Route::get('/paz-salvos/consultar', [PazSalvoController::class, 'index'])->middleware('permission:consultar paz y salvo')->name('paz-salvo.index');
    Route::post('/paz-salvos/consultar', [PazSalvoController::class, 'consult'])->middleware(['permission:consultar paz y salvo', 'throttle:20,1'])->name('paz-salvo.consult');
    Route::post('/paz-salvos/generar', [PazSalvoController::class, 'generate'])->middleware(['permission:generar paz y salvo', 'throttle:10,1'])->name('paz-salvo.generate');
    Route::get('/paz-salvos', [PazSalvoHistoryController::class, 'index'])->middleware('permission:ver historial')->name('paz-salvos.index');
    Route::get('/paz-salvos/{pazSalvo}', [PazSalvoHistoryController::class, 'show'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvos.show');
    Route::get('/paz-salvos/{pazSalvo}/pdf', [PazSalvoController::class, 'showPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.pdf');
    Route::get('/paz-salvos/{pazSalvo}/download', [PazSalvoController::class, 'downloadPdf'])->middleware('permission:ver detalle paz y salvo')->name('paz-salvo.download');
    Route::patch('/paz-salvos/{pazSalvo}/cancelar', [PazSalvoHistoryController::class, 'cancel'])->middleware('permission:anular paz y salvo')->name('paz-salvos.cancel');
    Route::get('/perfil/password', fn () => Inertia::render('profile/password'))->name('profile.password');
});
