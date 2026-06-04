<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

        // Use cursor() with deterministic ordering to stream rows in constant memory.
        // Order by date desc, then id desc for tie-breaker. This avoids mixing orderBy + chunkById.
        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween($dateCol, [$startDate, $endDate])
            ->select(['id', DB::raw($dateCol . ' as transaction_date'), 'type', 'amount', 'balance_after', 'description'])
            ->orderBy($dateCol, 'desc')
            ->orderBy('id', 'desc');

        $buffer = [];
        $count = 0;

        foreach ($query->cursor() as $r) {
            $td = $r->transaction_date ?? null;
            if ($td instanceof \Illuminate\Support\Carbon) {
                $tds = $td->toDateTimeString();
            } else {
                $tds = $td ? Carbon::parse($td)->toDateTimeString() : null;
            }

            $buffer[] = [
                'transaction_date' => $tds,
                'type' => $r->type,
                'amount' => $r->amount,
                'balance_after' => $r->balance_after,
                'description' => $r->description,
            ];

            $count++;

            if ($count >= $chunkSize) {
                $callback($buffer);
                // reset
                $buffer = [];
                $count = 0;
            }
        }

        // flush remaining
        if (count($buffer) > 0) {
            $callback($buffer);
        }
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
