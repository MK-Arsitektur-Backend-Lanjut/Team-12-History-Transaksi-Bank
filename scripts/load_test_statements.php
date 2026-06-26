<?php
/**
 * Load test untuk GET /api/statements — simulasi banyak user concurrent.
 *
 * Usage:
 *   php scripts/load_test_statements.php
 *   php scripts/load_test_statements.php --users=50 --requests=5
 *   php scripts/load_test_statements.php --users=20 --endpoint=export --account-from=3 --account-to=12
 */

require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Http;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$options = getopt('', [
    'base-url:',
    'users:',
    'requests:',
    'account-from:',
    'account-to:',
    'start-date:',
    'end-date:',
    'per-page:',
    'endpoint:',
    'warmup:',
]);

$baseUrl     = rtrim($options['base-url'] ?? 'http://127.0.0.1:8000', '/');
$users       = max(1, (int) ($options['users'] ?? 10));
$requests    = max(1, (int) ($options['requests'] ?? 3));
$accountFrom = (int) ($options['account-from'] ?? 3);
$accountTo   = (int) ($options['account-to'] ?? 12);
$startDate   = $options['start-date'] ?? '2024-01-01';
$endDate     = $options['end-date'] ?? date('Y-m-d');
$perPage     = (int) ($options['per-page'] ?? 15);
$endpoint    = $options['endpoint'] ?? 'index';
$warmup      = max(0, (int) ($options['warmup'] ?? 2));

if ($accountTo < $accountFrom) {
    fwrite(STDERR, "account-to must be >= account-from\n");
    exit(1);
}

function buildPath(string $endpoint, int $accountId, string $start, string $end, int $perPage): string
{
    $query = http_build_query([
        'account_id' => $accountId,
        'start_date' => $start,
        'end_date'   => $end,
        ...($endpoint === 'index' ? ['per_page' => $perPage] : []),
    ]);

    $path = $endpoint === 'export' ? '/api/statements/export' : '/api/statements';

    return $path . '?' . $query;
}

function runBatch(string $baseUrl, array $paths): array
{
    $batchStart = microtime(true);

    $responses = Http::pool(function ($pool) use ($baseUrl, $paths) {
        foreach ($paths as $i => $path) {
            $pool->as((string) $i)
                ->timeout(120)
                ->connectTimeout(10)
                ->get($baseUrl . $path);
        }
    });

    $results = [];
    foreach ($paths as $i => $path) {
        $key = (string) $i;
        $response = $responses[$key] ?? null;

        if ($response instanceof \Throwable) {
            $results[] = [
                'path'       => $path,
                'status'     => 0,
                'time_ms'    => round((microtime(true) - $batchStart) * 1000, 2),
                'size_bytes' => 0,
                'error'      => $response->getMessage(),
            ];
            continue;
        }

        $results[] = [
            'path'       => $path,
            'status'     => $response->status(),
            'time_ms'    => round((microtime(true) - $batchStart) * 1000, 2),
            'size_bytes' => strlen($response->body()),
            'error'      => $response->failed() ? 'HTTP ' . $response->status() : null,
        ];
    }

    return $results;
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0;
    }
    sort($values);
    $idx = (int) ceil(($p / 100) * count($values)) - 1;
    $idx = max(0, min($idx, count($values) - 1));

    return $values[$idx];
}

function summarize(array $results): array
{
    $times = array_column($results, 'time_ms');
    $ok = array_filter($results, fn ($r) => $r['status'] >= 200 && $r['status'] < 300 && $r['error'] === null);
    $failed = count($results) - count($ok);

    return [
        'total'       => count($results),
        'success'     => count($ok),
        'failed'      => $failed,
        'success_pct' => count($results) > 0 ? round(count($ok) / count($results) * 100, 2) : 0,
        'min_ms'      => $times !== [] ? round(min($times), 2) : 0,
        'max_ms'      => $times !== [] ? round(max($times), 2) : 0,
        'avg_ms'      => $times !== [] ? round(array_sum($times) / count($times), 2) : 0,
        'p50_ms'      => round(percentile($times, 50), 2),
        'p95_ms'      => round(percentile($times, 95), 2),
        'p99_ms'      => round(percentile($times, 99), 2),
    ];
}

echo "=== Statement Generator Load Test ===\n";
echo "Base URL   : {$baseUrl}\n";
echo "Endpoint   : {$endpoint}\n";
echo "Users      : {$users} concurrent\n";
echo "Requests   : {$requests} round(s) (total: " . ($users * $requests) . ")\n";
echo "Accounts   : {$accountFrom} - {$accountTo}\n";
echo "Date range : {$startDate} to {$endDate}\n";
echo "Warmup     : {$warmup} request(s)\n\n";

if ($warmup > 0) {
    $warmPaths = [];
    for ($w = 0; $w < $warmup; $w++) {
        $acct = $accountFrom + ($w % max(1, $accountTo - $accountFrom + 1));
        $warmPaths[] = buildPath($endpoint, $acct, $startDate, $endDate, $perPage);
    }
    runBatch($baseUrl, $warmPaths);
    echo "Warmup selesai.\n\n";
}

$allResults = [];
$batchStart = microtime(true);

for ($round = 0; $round < $requests; $round++) {
    $paths = [];
    for ($u = 0; $u < $users; $u++) {
        $accountId = $accountFrom + ($u % max(1, $accountTo - $accountFrom + 1));
        $paths[] = buildPath($endpoint, $accountId, $startDate, $endDate, $perPage);
    }

    $batchResults = runBatch($baseUrl, $paths);
    $allResults = array_merge($allResults, $batchResults);

    $roundSummary = summarize($batchResults);
    echo sprintf(
        "Round %d: %d concurrent -> avg %sms, p95 %sms, success %s%%\n",
        $round + 1,
        $users,
        $roundSummary['avg_ms'],
        $roundSummary['p95_ms'],
        $roundSummary['success_pct']
    );
}

$totalElapsed = microtime(true) - $batchStart;
$summary = summarize($allResults);

echo "\n=== HASIL KESELURUHAN ===\n";
echo "Total request     : {$summary['total']}\n";
echo "Berhasil          : {$summary['success']}\n";
echo "Gagal             : {$summary['failed']}\n";
echo "Success rate      : {$summary['success_pct']}%\n";
echo "Waktu total       : " . round($totalElapsed, 2) . " detik\n";
echo "Throughput        : " . round($summary['total'] / max($totalElapsed, 0.001), 2) . " req/s\n";
echo "Latency min       : {$summary['min_ms']} ms\n";
echo "Latency avg       : {$summary['avg_ms']} ms\n";
echo "Latency p50       : {$summary['p50_ms']} ms\n";
echo "Latency p95       : {$summary['p95_ms']} ms\n";
echo "Latency p99       : {$summary['p99_ms']} ms\n";
echo "Latency max       : {$summary['max_ms']} ms\n";

$statusCodes = [];
foreach ($allResults as $r) {
    $statusCodes[$r['status']] = ($statusCodes[$r['status']] ?? 0) + 1;
}
if ($statusCodes !== []) {
    echo "\nStatus codes:\n";
    foreach ($statusCodes as $code => $count) {
        echo "  HTTP {$code}: {$count}\n";
    }
}

$errors = array_filter($allResults, fn ($r) => $r['error'] !== null || $r['status'] < 200 || $r['status'] >= 300);
if ($errors !== []) {
    echo "\nSample errors (max 5):\n";
    foreach (array_slice($errors, 0, 5) as $err) {
        echo "  [{$err['status']}] {$err['path']} — " . ($err['error'] ?? 'non-2xx') . "\n";
    }
}

echo "\n=== INTERPRETASI CEPAT ===\n";
if ($summary['success_pct'] >= 99 && $summary['p95_ms'] < 500) {
    echo "Baik — mayoritas request cepat dan stabil di bawah 500ms (p95).\n";
} elseif ($summary['success_pct'] >= 95 && $summary['p95_ms'] < 2000) {
    echo "Cukup — masih layak, tapi perhatikan optimasi DB/cache jika user bertambah.\n";
} else {
    echo "Perlu perbaikan — latency tinggi atau banyak error; cek DB index, Redis cache, dan resource server.\n";
}

echo "\nSimpan hasil:\n";
echo "  php scripts/load_test_statements.php > storage/logs/load_test_" . date('Ymd_His') . ".txt\n";
