<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (
                ! $request->is('api/*')
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
            ) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404) {
                return response()->json([
                    'message' => $request->routeIs('public.appointment.lookup')
                        ? 'We could not find that request. Check the shop code and confirmation code, then try again.'
                        : 'The requested resource was not found.',
                ], 404);
            }

            $status = $exception instanceof HttpExceptionInterface
                ? $exception->getStatusCode()
                : 500;

            if ($status >= 500) {
                return response()->json([
                    'message' => 'Something went wrong while processing your request. Please try again later.',
                ], 500);
            }

            return null;
        });
    })->create();
