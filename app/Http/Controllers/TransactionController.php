<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Account\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transactions', description: 'Transaction Logging API')]
class TransactionController extends Controller
{
    private AccountService $accountService;

    public function __construct(AccountService $accountService)
    {
        $this->accountService = $accountService;
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
                    new OA\Property(property: 'type', type: 'string', enum: ['debit', 'kredit'], description: 'Transaction type', example: 'kredit'),
                    new OA\Property(property: 'amount', type: 'number', format: 'float', description: 'Transaction amount', minimum: 0.01, example: 100000.00)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
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
            'account_id' => 'required|integer',
            'type' => 'required|in:debit,credit,kredit',
            'amount' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($validated) {
            // Normalize type (accept 'kredit')
            $type = strtolower($validated['type']);
            if ($type === 'kredit') {
                $type = 'credit';
            }

            // Find account and ensure it exists
            $account = $this->accountService->find((int) $validated['account_id']);
            if (! $account) {
                return response()->json(['message' => 'Account not found.'], 404);
            }

            // Use AccountService to perform atomic balance update
            $updatedAccount = $this->accountService->adjustBalance($account, $type, (float) $validated['amount']);

            // Derive balance_before from updated balance and operation
            $after = (float) $updatedAccount->balance;
            $amount = (float) $validated['amount'];
            $before = $type === 'credit' ? $after - $amount : $after + $amount;

            $transaction = Transaction::create([
                'account_id' => $updatedAccount->id,
                'reference_number' => strtoupper((string) Str::uuid()),
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'transacted_at' => now(),
            ]);

            // Invalidate cached statement summaries for this account (Redis key pattern).
            try {
                $pattern = sprintf('stmt:summary:%d:*', $updatedAccount->id);
                $redis = Redis::connection();
                $keys = $redis->keys($pattern);
                foreach ($keys as $k) {
                    $redis->del($k);
                }
            } catch (\Throwable $e) {
                // Ignore if Redis isn't available or deletion fails.
            }

            return response()->json([
                'success' => true,
                'transaction' => $transaction
            ]);
        });
    }
}
