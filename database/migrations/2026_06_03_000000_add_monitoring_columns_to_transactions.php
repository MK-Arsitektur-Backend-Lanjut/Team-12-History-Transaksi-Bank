<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add monitoring columns for tracking transaction latency and error metrics.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Latency tracking: time from API call to transaction committed (in milliseconds)
            $table->unsignedInteger('latency_ms')->nullable()->after('updated_at');
            
            // Processing status for monitoring purposes
            $table->string('processing_status')->default('completed')->after('latency_ms'); // completed, pending, failed
            
            // Error message if processing failed
            $table->text('error_message')->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['latency_ms', 'processing_status', 'error_message']);
        });
    }
};
