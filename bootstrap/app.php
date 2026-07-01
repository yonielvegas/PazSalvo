<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['permission' => PermissionMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (\Throwable $exception, Request $request) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : null;

            if ($status !== 403 || ! $request->isMethod('GET') || (! $request->header('X-Inertia') && $request->expectsJson())) {
                return null;
            }

            return Inertia::render('error', [
                'status' => 403,
                'title' => 'Acceso no autorizado',
                'message' => 'No tienes permisos para acceder a esta seccion o realizar esta accion.',
                'fallback' => '/paz-salvos/consultar',
            ])->toResponse($request)->setStatusCode(403);
        });
    })->create();
