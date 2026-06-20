<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            // detect which date column exists (matches EloquentStatementRepository logic)
            $dateCol = Schema::hasColumn('transactions', 'transaction_date')
                ? 'transaction_date'
                : (Schema::hasColumn('transactions', 'transacted_at') ? 'transacted_at' : 'created_at');

            // composite index for account + date (used by statements and aggregates)
            $table->index(['account_id', $dateCol], 'ix_transactions_account_date');

            // composite index for account + type + date (useful when filtering by type)
            $table->index(['account_id', 'type', $dateCol], 'ix_transactions_account_type_date');

            // composite index to speed up last-transaction lookups per account (account_id + id)
            $table->index(['account_id', 'id'], 'ix_transactions_account_id_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('ix_transactions_account_date');
            $table->dropIndex('ix_transactions_account_type_date');
            $table->dropIndex('ix_transactions_account_id_id');
        });
    }
};
