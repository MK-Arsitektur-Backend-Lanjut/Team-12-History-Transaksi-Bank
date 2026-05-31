<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatementRequest;
use App\Http\Resources\TransactionResource;
use App\Repositories\StatementRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementController extends Controller
{
    private StatementRepositoryInterface $repo;

    public function __construct(StatementRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function index(StatementRequest $request): JsonResponse
    {
        $data = $request->validated();

        $perPage = $data['per_page'] ?? 15;

        $start = $data['start_date'];
        $end = $data['end_date'];
        $accountId = (int) $data['account_id'];

        $paginator = $this->repo->paginateByAccountDate($accountId, $start, $end, $perPage);

        $summary = $this->repo->getSummaryTotals($accountId, $start, $end);

        return response()->json([
            'data' => TransactionResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    public function export(StatementRequest $request): StreamedResponse
    {
        $data = $request->validated();

        $start = $data['start_date'];
        $end = $data['end_date'];
        $accountId = (int) $data['account_id'];

        $fileName = sprintf('statement_%d_%s_%s.csv', $accountId, str_replace(':', '-', $start), str_replace(':', '-', $end));

        $response = new StreamedResponse(function () use ($accountId, $start, $end) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['transaction_date', 'type', 'amount', 'balance_after', 'description']);

            $this->repo->streamByAccountDate($accountId, $start, $end, 1000, function ($chunk) use ($handle) {
                foreach ($chunk as $row) {
                    fputcsv($handle, [$row['transaction_date'], $row['type'], $row['amount'], $row['balance_after'], $row['description']]);
                }
            });

            fclose($handle);
        });

        $disposition = 'attachment; filename="' . $fileName . '"';

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
}
