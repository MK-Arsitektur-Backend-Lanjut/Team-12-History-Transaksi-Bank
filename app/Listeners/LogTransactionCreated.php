<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Services\TransactionMonitoringService;
use Illuminate\Support\Facades\Log;

class LogTransactionCreated
{
    /**
     * Handle the event — write transaction creation to application log.
     * This is a sample listener to show how consumers can subscribe to transaction events.
     * Other modules can implement similar listeners for notification, replication, or publishing to message queues.
     */
    public function handle(TransactionCreated $event): void
    {
        $tx = $event->transaction;
        $monitoring = app(TransactionMonitoringService::class);

        Log::info('Transaction created', [
            'transaction_id' => $tx->id,
            'reference_number' => $tx->reference_number,
            'account_id' => $tx->account_id,
            'type' => $tx->type,
            'amount' => $tx->amount,
            'balance_before' => $tx->balance_before,
            'balance_after' => $tx->balance_after,
            'transaction_date' => $tx->transaction_date,
            'latency_ms' => $tx->latency_ms,
            'created_at' => $tx->created_at,
        ]);

        // Record monitoring metrics
        if ($tx->latency_ms) {
            $monitoring->recordLatency($tx, $tx->latency_ms);
        }
    }
}
