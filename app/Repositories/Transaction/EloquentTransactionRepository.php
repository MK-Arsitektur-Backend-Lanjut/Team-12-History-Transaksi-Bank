<?php

namespace App\Repositories\Transaction;

use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * {@inheritdoc}
     */
    public function findById(int $id): ?Transaction
    {
        return Transaction::query()->find($id);
    }

    /**
     * {@inheritdoc}
     */
    public function findByReferenceNumber(string $referenceNumber): ?Transaction
    {
        return Transaction::query()
            ->where('reference_number', $referenceNumber)
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function paginateByAccount(int $accountId, int $perPage = 15): LengthAwarePaginator
    {
        return Transaction::query()
            ->where('account_id', $accountId)
            ->orderByDesc('transaction_date')
            ->paginate($perPage);
    }

    /**
     * {@inheritdoc}
     */
    public function getLastTransaction(int $accountId): ?Transaction
    {
        return Transaction::query()
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * {@inheritdoc}
     */
    public function update(Transaction $transaction, array $data): Transaction
    {
        $transaction->update($data);
        return $transaction->refresh();
    }
}
