<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface StatementRepositoryInterface
{
    public function paginateByAccountDate(int $accountId, string $startDate, string $endDate, int $perPage = 15): LengthAwarePaginator;

    /**
     * Return ['total_credit' => x, 'total_debit' => y]
     */
    public function getSummaryTotals(int $accountId, string $startDate, string $endDate): array;

    /**
     * Stream records by chunk for CSV export. Callback receives a chunk (array of arrays).
     * Should not return large arrays.
     */
    public function streamByAccountDate(int $accountId, string $startDate, string $endDate, int $chunkSize, callable $callback): void;
}
