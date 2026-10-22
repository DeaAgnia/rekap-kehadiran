<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('app.internal_api_key');
        $providedKey = (string) $request->header('X-Internal-Api-Key');

        if ($configuredKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return response()->json(['message' => 'Akses API tidak diizinkan.'], 401);
        }

        return $next($request);
    }
}
