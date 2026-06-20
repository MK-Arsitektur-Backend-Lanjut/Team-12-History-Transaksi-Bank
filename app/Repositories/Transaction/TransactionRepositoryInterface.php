<?php

namespace App\Repositories\Transaction;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TransactionRepositoryInterface
{
    /**
     * Create a new transaction record.
     *
     * @param array $data
     * @return Transaction
     */
    public function create(array $data): Transaction;

    /**
     * Find a transaction by its ID.
     *
     * @param int $id
     * @return Transaction|null
     */
    public function findById(int $id): ?Transaction;

    /**
     * Find a transaction by its reference number.
     *
     * @param string $referenceNumber
     * @return Transaction|null
     */
    public function findByReferenceNumber(string $referenceNumber): ?Transaction;

    /**
     * Get paginated transactions for a given account.
     *
     * @param int $accountId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginateByAccount(int $accountId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Get the last transaction for an account (to determine latest balance).
     *
     * @param int $accountId
     * @return Transaction|null
     */
    public function getLastTransaction(int $accountId): ?Transaction;

    /**
     * Update a transaction record.
     *
     * @param Transaction $transaction
     * @param array $data
     * @return Transaction
     */
    public function update(Transaction $transaction, array $data): Transaction;
}
