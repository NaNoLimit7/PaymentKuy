<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\TokenizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function __construct(private readonly TokenizationService $tokenization) {}

    /**
     * POST /api/v1/tokens
     * Tokenize mock payment data without storing raw PAN or CVV.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_uuid' => ['required_without:customer_id', 'string', 'uuid'],
            'customer_id' => ['required_without:customer_uuid', 'integer'],
            'token_type' => ['required', 'string', 'in:card,bank_account,ewallet'],
            'number' => ['required', 'string', 'min:4'],
            'brand' => ['nullable', 'string', 'max:50'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $customer = isset($validated['customer_uuid'])
            ? Customer::where('uuid', $validated['customer_uuid'])->firstOrFail()
            : Customer::findOrFail($validated['customer_id']);

        $token = $this->tokenization->tokenize($customer, $validated);

        return response()->json([
            'token' => $token->token,
            'token_type' => $token->token_type,
            'brand' => $token->brand,
            'last4' => $token->last4,
            'fingerprint' => $token->fingerprint,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at->toIso8601String(),
        ], 201);
    }
}
