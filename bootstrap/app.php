<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind nginx on the same host; trust the local proxy headers.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
            'writable' => \App\Http\Middleware\BlockViewerWrites::class,
            'module' => \App\Http\Middleware\EnsureModule::class,
            'logs.visible' => \App\Http\Middleware\EnsureLogsHubVisible::class,
        ]);
        $middleware->append(\App\Http\Middleware\PreviewHeaders::class);
        // Compliance hub preview-link sessions: /logs + logout only (no effect on normal sessions).
        $middleware->web(append: [\App\Http\Middleware\LogsPreviewScope::class]);
        // ...and it runs before the sign-in check, so a hidden /logs is a 404 even for guests.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\LogsPreviewScope::class,
        );
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Compliance rules that block an action: show the reasons in plain English.
        $exceptions->render(function (\App\Exceptions\GateFailed $e, \Illuminate\Http\Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'reasons' => $e->reasons], $e->status);
            }
            if ($e->status === 409) {
                return response()->view('errors.locked', ['reasons' => $e->reasons], 409);
            }

            return back()->withInput()->withErrors(['gate' => $e->reasons]);
        });
        $exceptions->render(function (\App\Exceptions\RecordDeletionForbidden $e, \Illuminate\Http\Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 403)
                : back()->withErrors(['gate' => [$e->getMessage()]]);
        });
    })->create();
