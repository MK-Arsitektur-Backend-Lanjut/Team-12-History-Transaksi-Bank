<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Example listener that could replicate transaction to a read-model or ledger.
 * Implement ShouldQueue to run asynchronously in the background.
 */
class ReplicateTransactionToLedger implements ShouldQueue
{
    /**
     * Handle the event — write transaction to a separate ledger system (example).
     * This could be a distributed event ledger, message queue, or read-model database.
     */
    public function handle(TransactionCreated $event): void
    {
        $tx = $event->transaction;

        // Example: Publish to message queue (Kafka, RabbitMQ, etc.)
        // Queue::push(new PublishTransactionEvent($tx));

        // Example: Write to ledger database
        // Ledger::create([
        //     'transaction_id' => $tx->id,
        //     'reference_number' => $tx->reference_number,
        //     'account_id' => $tx->account_id,
        //     'type' => $tx->type,
        //     'amount' => $tx->amount,
        //     'ledger_timestamp' => now(),
        // ]);

        // For now, just log
        \Illuminate\Support\Facades\Log::debug('Transaction replicated to ledger', [
            'transaction_id' => $tx->id,
            'reference_number' => $tx->reference_number,
        ]);
    }
}
