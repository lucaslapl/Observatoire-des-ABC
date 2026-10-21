<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Derrière un reverse proxy TLS (nginx, Plesk…) : TRUST_PROXY=1 pour
        // que request()->ip() lise l'IP réelle dans X-Forwarded-For.
        if (filter_var(env('TRUST_PROXY', false), FILTER_VALIDATE_BOOLEAN)) {
            $middleware->trustProxies(at: '*');
        }

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Invités → /login ; utilisateurs connectés visitant une page
        // « guest » (login, register…) → /admin ou /profil selon le rôle.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => (
            $request->user()?->hasRole('admin') || $request->user()?->hasRole('moderateur')
                ? '/admin'
                : '/profil'
        ));

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Pages d'erreur personnalisées (Inertia Error.vue) pour les visites
        // directes. Les navigations Inertia (XHR) rendent Error.vue côté
        // client ; l'API reste en JSON. Le 503 (maintenance) passe par la vue
        // Blade errors/503 : les assets Vite ne sont pas garantis disponibles.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            if ($e instanceof AuthenticationException || $e instanceof ValidationException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if (! in_array($status, [403, 404, 419, 429, 500], true)) {
                return null;
            }

            if ($status === 500 && config('app.debug')) {
                return null;
            }

            return Inertia::render('Error', [
                'status' => $status,
                'url' => $request->url(),
            ])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
