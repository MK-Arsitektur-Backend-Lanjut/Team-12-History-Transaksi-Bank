<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Async listener for transaction notifications.
 * Implements ShouldQueue to process in background via queue worker.
 * This prevents slow notification operations from blocking the API response.
 */
class SendTransactionNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Queue name to use for this job.
     */
    public $queue = 'transactions';

    /**
     * Number of times to retry on failure.
     */
    public $tries = 3;

    /**
     * Seconds to wait before retrying.
     */
    public $backoff = [60, 300, 900]; // 1 min, 5 min, 15 min

    /**
     * Handle the event — send notification asynchronously.
     * This listener will be executed by the queue worker in the background.
     */
    public function handle(TransactionCreated $event): void
    {
        $tx = $event->transaction;

        // Example: Send email to customer (implement actual notification logic)
        // Notification::send($tx->account->customer, new TransactionNotification($tx));

        // Example: Publish to external system
        // Queue::push(new PublishTransactionEvent($tx));

        // For now, just log
        \Illuminate\Support\Facades\Log::debug('Transaction notification queued', [
            'transaction_id' => $tx->id,
            'reference_number' => $tx->reference_number,
            'queue' => $this->queue,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error('Transaction notification failed', [
            'exception' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
