<?php

namespace App\Services;

use App\Events\TransactionCreated;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

class TransactionService
{
    private DatabaseManager $db;

    public function __construct(DatabaseManager $db)
    {
        $this->db = $db;
    }

    /**
     * Create a transaction atomically and return the created Transaction model.
     * Other modules should call this service to record money movements.
     * Tracks latency for monitoring purposes.
     *
     * @param array $payload keys: account_id, type ('debit'|'credit'), amount, description?, reference_number?, transaction_date?
     * @return Transaction
     */
    public function create(array $payload): Transaction
    {
        $startTime = microtime(true);
        $reference = $payload['reference_number'] ?? Str::upper(Str::uuid());

        $transaction = $this->db->transaction(function () use ($payload, $reference) {
            /** @var Account|null $account */
            $account = Account::query()
                ->whereKey($payload['account_id'])
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw new \RuntimeException('Account not found');
            }

            if ($account->status !== 'active') {
                throw new \RuntimeException('Account is not active');
            }

            $balanceBefore = (float) $account->balance;

            $amount = (float) $payload['amount'];

            if ($payload['type'] === 'debit' && $balanceBefore < $amount) {
                throw new \RuntimeException('Insufficient balance');
            }

            $newBalance = $payload['type'] === 'debit'
                ? $balanceBefore - $amount
                : $balanceBefore + $amount;

            $account->balance = round($newBalance, 2);
            $account->save();

            $tx = Transaction::create([
                'account_id' => $payload['account_id'],
                'reference_number' => $reference,
                'type' => $payload['type'],
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $newBalance,
                'description' => $payload['description'] ?? null,
                'transaction_date' => $payload['transaction_date'] ?? now(),
            ]);

            return $tx;
        });

        // Calculate latency in milliseconds
        $latencyMs = intval((microtime(true) - $startTime) * 1000);
        
        // Update transaction with latency
        $transaction->update(['latency_ms' => $latencyMs]);

        // Dispatch event after successful commit
        event(new TransactionCreated($transaction));

        return $transaction;
    }
}
