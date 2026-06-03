<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Transactions', description: 'Transaction Logging API')]
class TransactionController extends Controller
{
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
            'type' => 'required|in:debit,kredit',
            'amount' => 'required|numeric|min:1',
        ]);

        return DB::transaction(function () use ($validated) {
            // Ambil saldo terakhir
            $last = Transaction::where('account_id', $validated['account_id'])
                ->orderByDesc('id')->first();
            $lastBalance = $last ? $last->balance_after : 0;

            // Validasi saldo cukup jika debit
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

            return response()->json([
                'success' => true,
                'transaction' => $transaction
            ]);
        });
    }
}
