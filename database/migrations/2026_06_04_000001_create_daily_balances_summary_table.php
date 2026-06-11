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
        Schema::create('daily_balances_summary', function (Blueprint $table) {
            $table->id();
            // Since partitioned tables cannot have foreign keys, this summary table
            // referencing partitioned tables is best left as a standard index to avoid constraint issues.
            $table->unsignedBigInteger('account_id')->index();
            $table->date('summary_date');
            $table->decimal('total_credit', 18, 2)->default(0.00);
            $table->decimal('total_debit', 18, 2)->default(0.00);
            $table->decimal('closing_balance', 18, 2)->default(0.00);
            $table->timestamps();

            // Set up a composite unique key to make updates idempotent
            $table->unique(['account_id', 'summary_date'], 'account_summary_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_balances_summary');
    }
};
