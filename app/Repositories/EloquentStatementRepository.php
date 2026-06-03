<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class EloquentStatementRepository implements StatementRepositoryInterface
{
    public function paginateByAccountDate(int $accountId, string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator
    {
        $dateCol = $this->detectDateColumn();
        
        // normalize to full-day range so queries using datetime columns include entire days
        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->select(['id', DB::raw($dateCol . ' as transaction_date'), 'type', 'amount', 'balance_after', 'description'])
            ->orderBy($dateCol, 'desc');

        return $query->paginate($perPage);
    }

    public function getSummaryTotals(int $accountId, string $startDate, string $endDate): array
    {
        $dateCol = $this->detectDateColumn();
        
        // normalize to full-day range
        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        // handle both 'credit' and 'kredit' values by normalizing via LOWER()
        $row = DB::table('transactions')
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->selectRaw("SUM(CASE WHEN LOWER(type) IN ('credit','kredit') THEN amount ELSE 0 END) as total_credit, SUM(CASE WHEN LOWER(type) = 'debit' THEN amount ELSE 0 END) as total_debit")
            ->first();

        return [
            'total_credit' => $row->total_credit ?? 0,
            'total_debit' => $row->total_debit ?? 0,
        ];
    }

    public function streamByAccountDate(int $accountId, string $startDate, string $endDate, int $chunkSize, callable $callback): void
    {
        $dateCol = $this->detectDateColumn();
        
        // normalize to full-day range
        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->select(['id', DB::raw($dateCol . ' as transaction_date'), 'type', 'amount', 'balance_after', 'description'])
            ->orderBy($dateCol, 'desc')
            ->chunkById($chunkSize, function ($rows) use ($callback) {
                $data = $rows->map(function ($r) {
                    $td = $r->transaction_date ?? null;
                    if ($td instanceof \Illuminate\Support\Carbon) {
                        $tds = $td->toDateTimeString();
                    } else {
                        $tds = $td ? Carbon::parse($td)->toDateTimeString() : null;
                    }

                    return [
                        'transaction_date' => $tds,
                        'type' => $r->type,
                        'amount' => $r->amount,
                        'balance_after' => $r->balance_after,
                        'description' => $r->description,
                    ];
                })->toArray();

                $callback($data);
            });
    }

    private function detectDateColumn(): string
    {
        if (Schema::hasColumn('transactions', 'transaction_date')) {
            return 'transaction_date';
        }

        if (Schema::hasColumn('transactions', 'transacted_at')) {
            return 'transacted_at';
        }

        return 'created_at';
    }
}
