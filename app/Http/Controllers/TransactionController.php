<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use Illuminate\Http\Request;
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
                    new OA\Property(property: 'description', type: 'string', nullable: true, description: 'Optional description', example: 'Payment for invoice'),
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
            'account_id' => 'required|integer',
            'type' => 'required|in:debit,kredit',
            'amount' => 'required|numeric|min:1',
        ]);

        try {
            $transaction = $this->service->create($validated);

            return response()->json([
                'success' => true,
                'transaction' => $transaction,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
