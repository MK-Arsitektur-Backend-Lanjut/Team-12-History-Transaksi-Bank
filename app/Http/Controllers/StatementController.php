<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatementRequest;
use App\Http\Resources\TransactionResource;
use App\Repositories\StatementRepositoryInterface;
use App\Http\Resources\AccountResource;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Statements', description: 'Statement Generator API')]
class StatementController extends Controller
{
    private StatementRepositoryInterface $repo;
    private AccountService $accountService;

    public function __construct(StatementRepositoryInterface $repo, AccountService $accountService)
    {
        $this->repo = $repo;
        $this->accountService = $accountService;
    }

    #[OA\Get(
        path: '/api/statements',
        summary: 'Get account statement',
        description: 'Retrieves transaction history for a specific account within a date range with pagination support. Includes summary totals for debit and credit transactions.',
        tags: ['Statements'],
        parameters: [
            new OA\Parameter(name: 'account_id', description: 'Account ID to retrieve statements for', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'start_date', description: 'Start date for statement period (YYYY-MM-DD)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', description: 'End date for statement period (YYYY-MM-DD)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', description: 'Items per page (default: 15, max: 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Statement retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Transaction')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 15),
                                new OA\Property(property: 'total', type: 'integer', example: 50),
                                new OA\Property(property: 'last_page', type: 'integer', example: 4)
                            ]
                        ),
                        new OA\Property(
                            property: 'summary',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_credit', type: 'number', format: 'float', example: 150000.00),
                                new OA\Property(property: 'total_debit', type: 'number', format: 'float', example: 50000.00)
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error - invalid date range or missing parameters',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function index(StatementRequest $request): JsonResponse
    {
        $data = $request->validated();

        $perPage = $data['per_page'] ?? 15;

        $start = $data['start_date'];
        $end = $data['end_date'];
        $accountId = (int) $data['account_id'];

        $paginator = $this->repo->paginateByAccountDate($accountId, $start, $end, $perPage);

        $summary = $this->repo->getSummaryTotals($accountId, $start, $end);

        // fetch account profile via AccountService (uses repository pattern)
        $account = $this->accountService->find($accountId);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        return response()->json([
            'account' => new AccountResource($account),
            'summary' => $summary,
            'transactions' => TransactionResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/statements/export',
        summary: 'Export account statement as CSV',
        description: 'Generates and streams a CSV file containing transaction history for a specific account within a date range. Ideal for record-keeping and external reporting with efficient streaming.',
        tags: ['Statements'],
        parameters: [
            new OA\Parameter(name: 'account_id', description: 'Account ID to export statements for', in: 'query', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'start_date', description: 'Export start date (YYYY-MM-DD)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', description: 'Export end date (YYYY-MM-DD)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'CSV file exported successfully',
                content: new OA\MediaType(
                    mediaType: 'text/csv',
                    schema: new OA\Schema(
                        type: 'string',
                        format: 'binary',
                        description: 'CSV file with columns: transaction_date, type, amount, balance_after, description'
                    )
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation error - invalid date range or missing parameters',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')
            ),
        ]
    )]
    public function export(StatementRequest $request): StreamedResponse
    {
        $data = $request->validated();

        $start = $data['start_date'];
        $end = $data['end_date'];
        $accountId = (int) $data['account_id'];

        // try to include account_number in filename for clarity
        $account = $this->accountService->find($accountId);
        $accountName = $account ? $account->account_number : (string) $accountId;

        $fileName = sprintf('statement_%s_%s_%s.csv', $accountName, str_replace(':', '-', $start), str_replace(':', '-', $end));

        $response = new StreamedResponse(function () use ($accountId, $start, $end) {
            $handle = fopen('php://output', 'w');

            // account header
            $acct = $this->accountService->find($accountId);
            if ($acct) {
                fputcsv($handle, ['Account Number', $acct->account_number]);
                fputcsv($handle, ['Customer Name', $acct->customer_name]);
                fputcsv($handle, ['Email', $acct->email]);
                fputcsv($handle, []);
            }

            // column headers
            fputcsv($handle, ['transaction_date', 'type', 'amount', 'balance_after', 'description']);

            // stream rows and format numbers
            $totalCredit = 0;
            $totalDebit = 0;

            $this->repo->streamByAccountDate($accountId, $start, $end, 1000, function ($chunk) use ($handle, &$totalCredit, &$totalDebit) {
                foreach ($chunk as $row) {
                    $amount = is_null($row['amount']) ? '' : number_format((float)$row['amount'], 2, '.', '');
                    $balance = is_null($row['balance_after']) ? '' : number_format((float)$row['balance_after'], 2, '.', '');

                    // accumulate totals by type
                    $t = strtolower($row['type'] ?? '');
                    if (in_array($t, ['credit', 'kredit'])) {
                        $totalCredit += (float) $row['amount'];
                    } elseif ($t === 'debit') {
                        $totalDebit += (float) $row['amount'];
                    }

                    fputcsv($handle, [$row['transaction_date'], $row['type'], $amount, $balance, $row['description']]);
                }
            });

            // blank line then summary
            fputcsv($handle, []);
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['total_credit', number_format($totalCredit, 2, '.', '')]);
            fputcsv($handle, ['total_debit', number_format($totalDebit, 2, '.', '')]);

            fclose($handle);
        });

        $disposition = 'attachment; filename="' . $fileName . '"';

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
