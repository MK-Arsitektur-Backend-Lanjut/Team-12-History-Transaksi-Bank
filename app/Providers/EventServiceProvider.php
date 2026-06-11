<?php

namespace App\Providers;

use App\Events\TransactionCreated;
use App\Listeners\LogTransactionCreated;
use App\Listeners\ReplicateTransactionToLedger;
use App\Listeners\SendTransactionNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     * 
     * LogTransactionCreated: Synchronous, logs transaction details to file/syslog immediately.
     * SendTransactionNotification: Async via queue (ShouldQueue), sends notifications in background.
     * ReplicateTransactionToLedger: Async via queue, replicates to ledger/event log.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        TransactionCreated::class => [
            LogTransactionCreated::class,           // Sync: immediate logging
            SendTransactionNotification::class,     // Async: queued notification
            ReplicateTransactionToLedger::class,    // Async: queued replication
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
