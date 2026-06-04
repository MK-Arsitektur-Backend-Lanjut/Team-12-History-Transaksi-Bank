<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy duplicate migration retained for history.
        // The canonical transactions schema is created in 2026_04_15_230000_create_transactions_table.php.
    }

    public function down(): void
    {
        // no-op
    }
};
