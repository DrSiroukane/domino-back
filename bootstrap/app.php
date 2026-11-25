<?php

use App\Exceptions\ApiException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors answer {message, code} (docs/CONVENTIONS.md §4). Validation
        // errors keep Laravel's {message, errors}.
        $exceptions->render(fn (ApiException $e) => response()->json(
            ['message' => $e->getMessage(), 'code' => $e->errorCode],
            $e->status,
        ));
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e->getStatusCode();
            [$message, $code] = match ($status) {
                403 => ['This action is not allowed.', 'forbidden'],
                404 => ['Not found.', 'not_found'],
                405 => ['This method is not allowed here.', 'method_not_allowed'],
                429 => ['Too many requests. Try again in a moment.', 'too_many_requests'],
                default => ['The request failed.', 'http_error'],
            };

            return response()->json(['message' => $message, 'code' => $code], $status, $e->getHeaders());
        });
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Unauthenticated.', 'code' => 'unauthenticated'], 401)
            : null);
    })->create();
