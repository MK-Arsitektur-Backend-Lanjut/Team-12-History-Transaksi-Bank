<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;
use Illuminate\Support\Str;

class TransactionsTableSeeder extends Seeder
{
    /**
     * Run the seeder.
     *
     * This seeder generates a large amount of transactions (default 50,000)
     * using batch inserts to stay performant on big datasets. It also
     * distributes transactions across multiple account IDs so queries by
     * `account_id` can be exercised. The `transactions` table already has
     * indexes on `account_id` and `transaction_date` to support these queries.
     */
    public function run(): void
    {
        // Number of rows to insert (50k by default, can be overridden by env)
        $total = (int) env('SEED_TRANSACTIONS_TOTAL', 50000);
        $batchSize = 1000; // insert in batches to reduce memory/overhead
        $accounts = (int) env('SEED_ACCOUNTS_COUNT', 100);

        // delete existing rows to make seeder idempotent
        \DB::table('transactions')->delete();

        $now = now();
        $rows = [];

        for ($i = 1; $i <= $total; $i++) {
            $amount = rand(1000, 100000) / 100; // 10.00 - 1000.00
            $type = ($i % 2) ? 'credit' : 'debit';

            // distribute across multiple account ids
            $accountId = 1 + ($i % $accounts);

            $transactionDate = $now->copy()->subSeconds($total - $i);

            $rows[] = [
                'account_id' => $accountId,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => null,
                'transaction_date' => $transactionDate->toDateTimeString(),
                'description' => 'Seed transaction ' . $i,
                'created_at' => $transactionDate->toDateTimeString(),
                'updated_at' => $transactionDate->toDateTimeString(),
            ];

            if (count($rows) >= $batchSize) {
                \DB::table('transactions')->insert($rows);
                $rows = [];
            }
        }

        if (count($rows) > 0) {
            \DB::table('transactions')->insert($rows);
        }

        // Optionally compute and populate balance_after in chunks to avoid
        // N+1 updates per row: we'll process per account ordered by id.
        $accountIds = \DB::table('transactions')->distinct()->pluck('account_id');

        foreach ($accountIds as $acct) {
            $balance = 0;
            \DB::table('transactions')
                ->where('account_id', $acct)
                ->orderBy('id')
                ->chunk(1000, function ($chunk) use (&$balance) {
                    $updates = [];
                    foreach ($chunk as $row) {
                        $amt = (float) $row->amount;
                        $balance += ($row->type === 'credit') ? $amt : -$amt;
                        $updates[] = ['id' => $row->id, 'balance_after' => $balance];
                    }

                    // perform bulk updates using CASE WHEN for efficiency
                    $cases = [];
                    $ids = [];
                    foreach ($updates as $u) {
                        $cases[] = "WHEN id = {$u['id']} THEN {$u['balance_after']}";
                        $ids[] = $u['id'];
                    }

                    if (count($ids)) {
                        $casesSql = implode(' ', $cases);
                        $idsSql = implode(',', $ids);
                        \DB::statement("UPDATE transactions SET balance_after = CASE {$casesSql} END WHERE id IN ({$idsSql})");
                    }
                });
        }
    }
}
