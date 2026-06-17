<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\Paginator;

class EloquentStatementRepository implements StatementRepositoryInterface
{
    /**
     * Cache for detected date column to avoid repeated Schema checks.
     *
     * @var string|null
     */
    private static $cachedDateColumn = null;

    public function paginateByAccountDate(int $accountId, string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator
    {
        $dateCol = $this->detectDateColumn();
        
        // normalize to full-day range so queries using datetime columns include entire days
        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->select(['id', 'reference_number', $dateCol . ' as transaction_date', 'type', 'amount', 'balance_before', 'balance_after', 'description'])
            ->orderBy($dateCol, 'desc');

        // Resolve current page so cache keys include page number.
        $currentPage = Paginator::resolveCurrentPage() ?: 1;

        $cacheKey = sprintf('stmt:page:%d:%s:%s:page:%d:per:%d', $accountId, $startDate, $endDate, $currentPage, $perPage);

        try {
            $store = Cache::store('redis');

            return $store->remember($cacheKey, now()->addSeconds(30), function () use ($query, $perPage) {
                return $query->paginate($perPage);
            });
        } catch (\Throwable $e) {
            // Fallback to direct paginate when Redis/cache isn't available.
            return $query->paginate($perPage);
        }
    }

    public function getSummaryTotals(int $accountId, string $startDate, string $endDate): array
    {
        $dateCol = $this->detectDateColumn();
        
        // normalize to full-day range
        $startDate = Carbon::parse($startDate)->startOfDay()->toDateTimeString();
        $endDate = Carbon::parse($endDate)->endOfDay()->toDateTimeString();

        $cacheKey = sprintf('stmt:summary:%d:%s:%s', $accountId, $startDate, $endDate);

        // Prefer Redis store for summary caching to avoid drivers that don't support tags.
        try {
            $store = Cache::store('redis');
            $row = $store->remember($cacheKey, now()->addMinutes(5), function () use ($accountId, $dateCol, $startDate, $endDate) {
                return DB::table('transactions')
                    ->where('account_id', $accountId)
                    ->whereBetween($dateCol, [$startDate, $endDate])
                    ->selectRaw("SUM(CASE WHEN LOWER(type) IN ('credit','kredit') THEN amount ELSE 0 END) as total_credit, SUM(CASE WHEN LOWER(type) = 'debit' THEN amount ELSE 0 END) as total_debit")
                    ->first();
            });
        } catch (\Throwable $e) {
            // Fallback to direct query when Redis/cache isn't available or store isn't configured.
            $row = DB::table('transactions')
                ->where('account_id', $accountId)
                ->whereBetween($dateCol, [$startDate, $endDate])
                ->selectRaw("SUM(CASE WHEN LOWER(type) IN ('credit','kredit') THEN amount ELSE 0 END) as total_credit, SUM(CASE WHEN LOWER(type) = 'debit' THEN amount ELSE 0 END) as total_debit")
                ->first();
        }

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

        // Use chunkById for constant memory.
        Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->select(['id', 'reference_number', $dateCol . ' as transaction_date', 'type', 'amount', 'balance_before', 'balance_after', 'description'])
            ->chunkById($chunkSize, function ($rows) use ($callback) {
                $data = $rows->map(function ($r) {
                    $td = $r->transaction_date;
                    $tds = $td instanceof \Carbon\Carbon ? $td->toDateTimeString() : (string) $td;
                    return [
                        'reference_number' => $r->reference_number,
                        'transaction_date' => $tds,
                        'type' => $r->type,
                        'amount' => $r->amount,
                        'balance_before' => $r->balance_before,
                        'balance_after' => $r->balance_after,
                        'description' => $r->description,
                    ];
                })->toArray();

                $callback($data);
            });
    }

    private function detectDateColumn(): string
    {
        if (self::$cachedDateColumn !== null) {
            return self::$cachedDateColumn;
        }

        if (Schema::hasColumn('transactions', 'transaction_date')) {
            self::$cachedDateColumn = 'transaction_date';
            return self::$cachedDateColumn;
        }

        if (Schema::hasColumn('transactions', 'transacted_at')) {
            self::$cachedDateColumn = 'transacted_at';
            return self::$cachedDateColumn;
        }

        self::$cachedDateColumn = 'created_at';
        return self::$cachedDateColumn;
    }
}
