<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class TransactionMonitoringService
{
    /**
     * Alert thresholds (in milliseconds)
     */
    private const LATENCY_THRESHOLD_MS = 500;
    private const ERROR_RATE_THRESHOLD = 0.05; // 5%

    /**
     * Record transaction latency and check if alert is needed.
     */
    public function recordLatency(Transaction $transaction, int $latencyMs): void
    {
        $transaction->update([
            'latency_ms' => $latencyMs,
            'processing_status' => 'completed',
        ]);

        if ($latencyMs > self::LATENCY_THRESHOLD_MS) {
            $this->alertHighLatency($transaction, $latencyMs);
        }
    }

    /**
     * Record processing error.
     */
    public function recordError(Transaction $transaction, string $errorMessage): void
    {
        $transaction->update([
            'processing_status' => 'failed',
            'error_message' => $errorMessage,
        ]);

        $this->alertProcessingError($transaction, $errorMessage);
    }

    /**
     * Alert when latency exceeds threshold.
     */
    private function alertHighLatency(Transaction $transaction, int $latencyMs): void
    {
        Log::warning('High transaction latency detected', [
            'transaction_id' => $transaction->id,
            'reference_number' => $transaction->reference_number,
            'latency_ms' => $latencyMs,
            'threshold_ms' => self::LATENCY_THRESHOLD_MS,
            'account_id' => $transaction->account_id,
            'amount' => $transaction->amount,
        ]);

        // In production, trigger alert to monitoring system (e.g., Datadog, New Relic, Prometheus)
        // Example: Alert::notify('TransactionLatencyAlert', ['transaction' => $transaction]);
    }

    /**
     * Alert when processing fails.
     */
    private function alertProcessingError(Transaction $transaction, string $errorMessage): void
    {
        Log::error('Transaction processing failed', [
            'transaction_id' => $transaction->id,
            'reference_number' => $transaction->reference_number,
            'account_id' => $transaction->account_id,
            'error' => $errorMessage,
        ]);

        // In production, trigger critical alert
        // Example: Alert::notify('TransactionErrorAlert', ['transaction' => $transaction, 'error' => $errorMessage]);
    }

    /**
     * Get error rate for a given time window (in minutes).
     */
    public function getErrorRate(int $minutesWindow = 60): float
    {
        $start = now()->subMinutes($minutesWindow);

        $total = Transaction::where('created_at', '>=', $start)->count();
        if ($total === 0) {
            return 0.0;
        }

        $failed = Transaction::where('created_at', '>=', $start)
            ->where('processing_status', 'failed')
            ->count();

        return $failed / $total;
    }

    /**
     * Get average latency for a given time window (in minutes).
     */
    public function getAverageLatency(int $minutesWindow = 60): ?int
    {
        $start = now()->subMinutes($minutesWindow);

        return Transaction::where('created_at', '>=', $start)
            ->where('processing_status', 'completed')
            ->whereNotNull('latency_ms')
            ->avg('latency_ms');
    }

    /**
     * Check if error rate exceeds threshold and alert if necessary.
     */
    public function checkErrorRateThreshold(int $minutesWindow = 60): void
    {
        $errorRate = $this->getErrorRate($minutesWindow);

        if ($errorRate > self::ERROR_RATE_THRESHOLD) {
            Log::alert('High transaction error rate detected', [
                'error_rate' => round($errorRate * 100, 2) . '%',
                'threshold' => round(self::ERROR_RATE_THRESHOLD * 100, 2) . '%',
                'time_window_minutes' => $minutesWindow,
            ]);

            // In production: trigger critical alert
            // Example: Alert::notify('HighErrorRateAlert', ['error_rate' => $errorRate]);
        }
    }

    /**
     * Get latency percentiles for SLA monitoring.
     */
    public function getLatencyPercentiles(int $minutesWindow = 60): array
    {
        $start = now()->subMinutes($minutesWindow);

        $latencies = Transaction::where('created_at', '>=', $start)
            ->where('processing_status', 'completed')
            ->whereNotNull('latency_ms')
            ->pluck('latency_ms')
            ->toArray();

        if (empty($latencies)) {
            return [];
        }

        sort($latencies);
        $count = count($latencies);

        return [
            'p50' => $latencies[intval($count * 0.50)],
            'p95' => $latencies[intval($count * 0.95)],
            'p99' => $latencies[intval($count * 0.99)],
            'min' => min($latencies),
            'max' => max($latencies),
            'avg' => intval(array_sum($latencies) / $count),
        ];
    }
}
