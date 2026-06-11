<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\DailyBalancesSummary;
use Illuminate\Support\Carbon;

class GenerateDailySummary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-daily-summary {--date= : The date to summarize (YYYY-MM-DD), defaults to yesterday}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate credit/debit totals and closing balances per account for a specific date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dateOption = $this->option('date');
        $date = $dateOption ? Carbon::parse($dateOption)->toDateString() : Carbon::yesterday()->toDateString();

        $this->info("Starting daily summary aggregation for date: {$date}");

        $start = Carbon::parse($date)->startOfDay()->toDateTimeString();
        $end = Carbon::parse($date)->endOfDay()->toDateTimeString();

        // 1. Get all distinct account IDs that had transactions on this date
        $accountIds = Transaction::query()
            ->whereBetween('transaction_date', [$start, $end])
            ->distinct()
            ->pluck('account_id');

        if ($accountIds->isEmpty()) {
            $this->warn("No transactions found on {$date}. No summaries created.");
            return Command::SUCCESS;
        }

        $this->info("Found " . $accountIds->count() . " active accounts on this date.");

        // 2. Aggregate metrics for each account
        foreach ($accountIds as $accountId) {
            // Aggregate total credit
            $totalCredit = (float) Transaction::query()
                ->where('account_id', $accountId)
                ->whereBetween('transaction_date', [$start, $end])
                ->where('type', 'credit')
                ->sum('amount');

            // Aggregate total debit
            $totalDebit = (float) Transaction::query()
                ->where('account_id', $accountId)
                ->whereBetween('transaction_date', [$start, $end])
                ->where('type', 'debit')
                ->sum('amount');

            // Find closing balance (latest balance_after on that day)
            $latestTransaction = Transaction::query()
                ->where('account_id', $accountId)
                ->whereBetween('transaction_date', [$start, $end])
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            $closingBalance = $latestTransaction ? (float) $latestTransaction->balance_after : 0.00;

            // 3. Upsert the record (make it idempotent)
            DailyBalancesSummary::updateOrCreate(
                [
                    'account_id' => $accountId,
                    'summary_date' => $date,
                ],
                [
                    'total_credit' => $totalCredit,
                    'total_debit' => $totalDebit,
                    'closing_balance' => $closingBalance,
                ]
            );

            $this->line("Processed account ID {$accountId}: Credit: {$totalCredit}, Debit: {$totalDebit}, Closing: {$closingBalance}");
        }

        $this->info("Successfully generated daily balance summaries.");
        return Command::SUCCESS;
    }
}
