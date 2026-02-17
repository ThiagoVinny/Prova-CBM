<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-API-Key');
        $expected = config('app.api_key');

        if (!$expected) {
            return response()->json([
                'message' => 'API key not configured',
            ], 500);
        }

        if (!$provided) {
            return response()->json([
                'message' => 'Missing X-API-Key header',
            ], 401);
        }

        if (!hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Invalid API key',
            ], 403);
        }

        return $next($request);
    }
}
