<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection($this->getConnection())->getDriverName();

        if ($driver === 'mysql') {
            // MySQL Partitioning requires dropping the foreign key constraint
            // and altering the primary key to include the partitioning column (transaction_date).
            
            // 1. Drop foreign key constraint
            DB::statement('ALTER TABLE transactions DROP FOREIGN KEY transactions_account_id_foreign');
            
            // 2. Remove AUTO_INCREMENT temporarily to allow changing the Primary Key
            DB::statement('ALTER TABLE transactions MODIFY id BIGINT UNSIGNED NOT NULL');
            
            // 3. Drop current Primary Key
            DB::statement('ALTER TABLE transactions DROP PRIMARY KEY');
            
            // 4. Drop unique key on reference_number (needs to include partition column)
            DB::statement('ALTER TABLE transactions DROP INDEX transactions_reference_number_unique');
            
            // 5. Add composite Primary Key (id, transaction_date)
            DB::statement('ALTER TABLE transactions ADD PRIMARY KEY (id, transaction_date)');
            
            // 6. Restore AUTO_INCREMENT
            DB::statement('ALTER TABLE transactions MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
            
            // 7. Add composite unique constraint for reference_number
            DB::statement('ALTER TABLE transactions ADD UNIQUE KEY transactions_reference_number_unique (reference_number, transaction_date)');
            
            // 8. Apply range columns partitioning by transaction_date
            DB::statement("
                ALTER TABLE transactions PARTITION BY RANGE COLUMNS(transaction_date) (
                    PARTITION p2025 VALUES LESS THAN ('2026-01-01 00:00:00'),
                    PARTITION p2026_h1 VALUES LESS THAN ('2026-07-01 00:00:00'),
                    PARTITION p2026_h2 VALUES LESS THAN ('2027-01-01 00:00:00'),
                    PARTITION pmax VALUES LESS THAN MAXVALUE
                )
            ");
        } else {
            // SQLite (testing environment) fallback:
            // Since SQLite does not support partitioning or dropping PK/FK directly like MySQL,
            // we will create an index on the transaction_date for optimization.
            Schema::table('transactions', function ($table) {
                // Ensure index exists (it was already created in canonical migration,
                // but we add this as a safe fallback path)
                if (!Schema::hasIndex('transactions', 'transactions_transaction_date_index')) {
                    $table->index('transaction_date');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection($this->getConnection())->getDriverName();

        if ($driver === 'mysql') {
            // 1. Remove partitioning
            DB::statement('ALTER TABLE transactions REMOVE PARTITIONING');
            
            // 2. Remove AUTO_INCREMENT temporarily
            DB::statement('ALTER TABLE transactions MODIFY id BIGINT UNSIGNED NOT NULL');
            
            // 3. Drop composite Primary Key
            DB::statement('ALTER TABLE transactions DROP PRIMARY KEY');
            
            // 4. Drop composite unique key
            DB::statement('ALTER TABLE transactions DROP INDEX transactions_reference_number_unique');
            
            // 5. Restore original Primary Key
            DB::statement('ALTER TABLE transactions ADD PRIMARY KEY (id)');
            
            // 6. Restore AUTO_INCREMENT
            DB::statement('ALTER TABLE transactions MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
            
            // 7. Restore original unique constraint on reference_number
            DB::statement('ALTER TABLE transactions ADD UNIQUE KEY transactions_reference_number_unique (reference_number)');
            
            // 8. Restore original foreign key constraint
            DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_account_id_foreign FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE');
        }
    }
};
