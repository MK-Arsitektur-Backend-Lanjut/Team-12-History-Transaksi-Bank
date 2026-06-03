<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentStatementRepository implements StatementRepositoryInterface
{
    public function paginateByAccountDate(int $accountId, string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator
    {
        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(['id', 'transaction_date', 'type', 'amount', 'balance_after', 'description'])
            ->orderBy('transaction_date', 'desc');

        return $query->paginate($perPage);
    }

    public function getSummaryTotals(int $accountId, string $startDate, string $endDate): array
    {
        $row = DB::table('transactions')
            ->where('account_id', $accountId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) as total_credit, SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) as total_debit")
            ->first();

        return [
            'total_credit' => $row->total_credit ?? 0,
            'total_debit' => $row->total_debit ?? 0,
        ];
    }

    public function streamByAccountDate(int $accountId, string $startDate, string $endDate, int $chunkSize, callable $callback): void
    {
        Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->select(['id','transaction_date', 'type', 'amount', 'balance_after', 'description'])
            ->orderBy('transaction_date', 'desc')
            ->chunkById($chunkSize, function ($rows) use ($callback) {
                $data = $rows->map(function ($r) {
                    return [
                        'transaction_date' => $r->transaction_date->toDateTimeString(),
                        'type' => $r->type,
                        'amount' => $r->amount,
                        'balance_after' => $r->balance_after,
                        'description' => $r->description,
                    ];
                })->toArray();

                $callback($data);
            });
    }
}
