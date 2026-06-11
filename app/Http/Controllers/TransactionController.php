<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transactions', description: 'Transaction Logging API')]
class TransactionController extends Controller
{
    private TransactionService $service;

    public function __construct(TransactionService $service)
    {
        $this->service = $service;
    }

    #[OA\Post(
        path: '/api/transactions',
        summary: 'Create transaction log',
        description: 'Records a new transaction for an account with debit or credit operation. Automatically calculates and updates balance with transaction history tracking.',
        tags: ['Transactions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['account_id', 'type', 'amount'],
                properties: [
                    new OA\Property(property: 'account_id', type: 'integer', description: 'Account ID', example: 1),
                    new OA\Property(property: 'type', type: 'string', enum: ['debit', 'credit'], description: 'Transaction type', example: 'credit'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', description: 'Transaction amount', minimum: 0.01, example: 100000.00),
                    new OA\Property(property: 'reference_number', type: 'string', description: 'Idempotency key / external reference', example: '4f0c4ae0-7b16-48d7-9488-5893710bbda8')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Transaction created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'transaction', ref: '#/components/schemas/Transaction')
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error or insufficient balance',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'type' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $cacheKey = $this->getBalanceCacheKey($validated['account_id']);
        $lockKey = "{$cacheKey}:lock";

        return Cache::store('redis')->lock($lockKey, 10)->block(5, function () use ($validated, $cacheKey) {
            return DB::transaction(function () use ($validated, $cacheKey) {
                $lastBalance = $this->getCachedLastBalance($validated['account_id'], $cacheKey);

                if ($validated['type'] === 'debit' && $lastBalance < $validated['amount']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Saldo tidak cukup.'
                    ], 422);
                }

                $newBalance = $validated['type'] === 'debit'
                    ? $lastBalance - $validated['amount']
                    : $lastBalance + $validated['amount'];

                $transaction = Transaction::create([
                    'account_id' => $validated['account_id'],
                    'reference_number' => strtoupper(Str::uuid()),
                    'type' => $validated['type'],
                    'amount' => $validated['amount'],
                    'balance_after' => $newBalance,
                ]);

                Cache::store('redis')->put($cacheKey, $newBalance, now()->addMinutes(10));

                return response()->json([
                    'success' => true,
                    'transaction' => $transaction
                ]);
            });
        });
    }

    private function getCachedLastBalance(int $accountId, string $cacheKey): float
    {
        return Cache::store('redis')->remember($cacheKey, now()->addMinutes(10), function () use ($accountId) {
            $last = Transaction::where('account_id', $accountId)
                ->orderByDesc('id')
                ->first();

            return $last ? $last->balance_after : 0;
        });
    }

    private function getBalanceCacheKey(int $accountId): string
    {
        return "account_balance:{$accountId}";
    }
}
