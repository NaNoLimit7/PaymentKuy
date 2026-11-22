<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Api-Key');

        if (! $apiKey) {
            return response()->json([
                'message' => 'Missing X-Api-Key header',
            ], 401);
        }

        $hash = hash('sha256', $apiKey);
        $merchant = Merchant::where('api_key_hash', $hash)
            ->where('status', 'active')
            ->first();

        if (! $merchant) {
            return response()->json([
                'message' => 'Invalid or inactive merchant API key',
            ], 401);
        }

        $request->attributes->set('merchant', $merchant);

        return $next($request);
    }
}
