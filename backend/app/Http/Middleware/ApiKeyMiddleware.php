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
                'message' => 'API KEY não configurada no servidor',
            ], 500);
        }

        if (!$provided) {
            return response()->json([
                'message' => 'Cabeçalho X-API-Key ausente',
            ], 401);
        }

        if (!hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'API KEY fornecida é inválida',
            ], 403);
        }

        return $next($request);
    }
}
