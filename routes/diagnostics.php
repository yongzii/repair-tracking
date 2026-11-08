<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/_diag/runtime', function (Request $request) {
    $requestStartedAt = hrtime(true);
    $expectedToken = config('diagnostics.token');
    $providedToken = $request->bearerToken();

    if (
        ! is_string($expectedToken)
        || strlen($expectedToken) < 64
        || ! is_string($providedToken)
        || ! hash_equals($expectedToken, $providedToken)
    ) {
        return response()->json(['message' => 'Not Found'], 404)
            ->header('Cache-Control', 'no-store, private');
    }

    return response()->json([
        'status' => 'ok',
        'runtime_ms' => round((hrtime(true) - $requestStartedAt) / 1_000_000, 2),
    ])->header('Cache-Control', 'no-store, private');
});
