<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backfill command for transactions balance_before/balance_after
Artisan::command('backfill:transactions {--chunk=1000} {--apply}', function () {
    $chunk = (int) $this->option('chunk');
    $apply = (bool) $this->option('apply');

    $this->info('Backfill transactions (dry-run by default)');
    $this->info("Chunk size: {$chunk}");
    $this->info($apply ? 'Apply mode: ON' : 'Apply mode: DRY-RUN (no writes)');

    $total = 0;
    if (!\Illuminate\Support\Facades\Schema::hasTable('transactions')) {
        $this->error('Table `transactions` does not exist in the current DB connection. Aborting.');
        return;
    }

    // Dry-run: just count candidates
    $candidates = \DB::table('transactions')->whereNull('balance_before')->count();
    $this->info("Transactions with NULL balance_before: {$candidates}");

    if (! $apply) {
        $this->info('Dry-run complete: no updates performed. Use --apply to perform backfill.');
        return;
    }

    // APPLY mode: perform chunked per-account backfill to compute running balances.
    $this->info('Apply mode: starting backfill per account');

    $accountIds = \DB::table('transactions')->distinct()->orderBy('account_id')->pluck('account_id');
    foreach ($accountIds as $acct) {
        $this->line("Processing account {$acct}");
        $running = 0.0;

        // Process transactions ordered by transaction_date asc to compute running balance
        \DB::table('transactions')
            ->where('account_id', $acct)
            ->orderBy('transaction_date', 'asc')
            ->chunkById($chunk, function ($rows) use (&$running) {
                $updates = [];
                $ids = [];

                foreach ($rows as $row) {
                    $amt = (float) $row->amount;
                    $balanceBefore = $running;
                    $running += ($row->type === 'credit') ? $amt : -$amt;
                    $updates[$row->id] = ['before' => $balanceBefore, 'after' => $running];
                    $ids[] = $row->id;
                }

                if (count($ids) === 0) {
                    return;
                }

                // Build SQL CASE WHEN update to batch write updates for this chunk
                $beforeCases = '';
                $afterCases = '';
                foreach ($updates as $id => $vals) {
                    // Use explicit casting to ensure decimal literal formatting
                    $before = is_float($vals['before']) ? number_format($vals['before'], 2, '.', '') : $vals['before'];
                    $after = is_float($vals['after']) ? number_format($vals['after'], 2, '.', '') : $vals['after'];
                    $beforeCases .= " WHEN id = {$id} THEN {$before}";
                    $afterCases .= " WHEN id = {$id} THEN {$after}";
                }
                $idsSql = implode(',', $ids);

                $sql = "UPDATE transactions SET balance_before = CASE{$beforeCases} END, balance_after = CASE{$afterCases} END WHERE id IN ({$idsSql})";
                \DB::statement($sql);
            });
    }

    $this->info('Backfill apply completed.');

})->describe('Inspect transactions needing backfill and optionally apply updates (unsafe, default DRY-RUN)');

// Schedule the daily balances summary command
use Illuminate\Support\Facades\Schedule;
Schedule::command('app:generate-daily-summary')->dailyAt('00:05');

