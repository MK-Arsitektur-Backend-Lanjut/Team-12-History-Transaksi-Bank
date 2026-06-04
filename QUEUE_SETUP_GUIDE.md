# Queue & Async Processing Setup Guide

Dokumentasi ini menjelaskan cara setup dan menjalankan queue workers untuk async processing transaksi.

## 1. Konfigurasi Queue Driver

File: `.env`

```env
# Use database driver for reliable queue processing
QUEUE_CONNECTION=database

# Or use Redis for better performance:
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1
# REDIS_PASSWORD=null
# REDIS_PORT=6379
```

## 2. Setup Database Queue Table

Jika menggunakan `database` driver, buat tabel jobs:

```bash
php artisan queue:table
php artisan queue:failed-jobs-table
php artisan migrate
```

## 3. Event Listener Configuration

File: `app/Providers/EventServiceProvider.php`

Listener yang daftarkan:
- **LogTransactionCreated** (sync): Menulis ke log immediately
- **SendTransactionNotification** (async/queued): Mengirim notifikasi di background
- **ReplicateTransactionToLedger** (async/queued): Replicate ke ledger di background

Kapan async listener dijalankan?
- Event di-dispatch setelah transaction commit.
- Listener dengan `implements ShouldQueue` otomatis dipush ke queue.
- Queue worker memproses job di background.

## 4. Start Queue Worker

### Development (foreground)

```bash
php artisan queue:work --queue=transactions --verbose
```

Options:
- `--queue=transactions` — Process hanya dari queue `transactions` (tempat listener push)
- `--verbose` — Show detailed output
- `--timeout=30` — Maximum execution time per job (seconds)
- `--sleep=3` — Sleep between polling (seconds)

### Production (background with supervision)

```bash
# Using supervisord (recommended)
# Create /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/artisan queue:work database --queue=transactions --tries=3
autostart=true
autorestart=true
stderr_logfile=/var/log/laravel-worker.err.log
stdout_logfile=/var/log/laravel-worker.out.log
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile_maxbytes=50MB

# Then:
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
sudo supervisorctl status laravel-worker:*
```

### Docker (with docker-compose)

```yaml
# docker-compose.yml
services:
  app:
    # ... existing config

  queue-worker:
    image: php:8.2-cli
    volumes:
      - .:/app
    working_dir: /app
    command: php artisan queue:work database --queue=transactions --verbose
    depends_on:
      - db
    environment:
      - DB_CONNECTION=mysql
      - DB_HOST=db
      - DB_PORT=3306
      - DB_DATABASE=modul_account_management
      - DB_USERNAME=root
      - DB_PASSWORD=password
```

```bash
docker-compose up -d queue-worker
docker-compose logs -f queue-worker
```

## 5. Monitoring Queue Health

### Check pending jobs

```bash
# View jobs in queue
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Retry specific job id
php artisan queue:retry <job-id>
```

### Database-backed monitoring

```sql
-- View jobs in queue (if using database driver)
SELECT * FROM jobs WHERE queue = 'transactions' ORDER BY created_at DESC;

-- View failed jobs
SELECT * FROM failed_jobs WHERE queue = 'transactions' ORDER BY created_at DESC;

-- Count pending jobs
SELECT COUNT(*) FROM jobs WHERE queue = 'transactions';
```

### Artisan commands

```bash
# Purge failed jobs older than 7 days
php artisan queue:prune-failed --hours=168

# Purge all failed jobs
php artisan queue:flush

# Monitor queue with live stats
php artisan queue:monitor --refresh=5
```

## 6. Latency & Alert Monitoring

Metrics dicatat di kolom `transactions.latency_ms` dan `transactions.processing_status`.

### Monitoring Console Command (optional)

Buat file `app/Console/Commands/MonitorTransactions.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\TransactionMonitoringService;
use Illuminate\Console\Command;

class MonitorTransactions extends Command
{
    protected $signature = 'transactions:monitor {--window=60 : Time window in minutes}';

    public function handle(TransactionMonitoringService $monitoring)
    {
        $window = $this->option('window');

        $avgLatency = $monitoring->getAverageLatency($window);
        $errorRate = $monitoring->getErrorRateThreshold($window);
        $percentiles = $monitoring->getLatencyPercentiles($window);

        $this->info("Transaction Monitoring (Last {$window} minutes)");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Average Latency', $avgLatency . 'ms'],
                ['Error Rate', round($errorRate * 100, 2) . '%'],
                ['P50 Latency', $percentiles['p50'] . 'ms'],
                ['P95 Latency', $percentiles['p95'] . 'ms'],
                ['P99 Latency', $percentiles['p99'] . 'ms'],
            ]
        );

        $monitoring->checkErrorRateThreshold($window);
    }
}
```

Run:
```bash
php artisan transactions:monitor --window=60
```

## 7. Retry & Error Handling

### Job Failure Behavior

Listener `SendTransactionNotification` memiliki:
- `$tries = 3` — Retry sampai 3x
- `$backoff = [60, 300, 900]` — Tunggu 1min, 5min, 15min before retry
- Jika semua retry fail, job dipindah ke `failed_jobs`

### Handle Failed Jobs

```bash
# List failed jobs
php artisan queue:failed

# Retry specific job
php artisan queue:retry <job-id>

# Retry all failed jobs
php artisan queue:retry all

# Forget failed job
php artisan queue:forget <job-id>
```

## 8. Performance Tuning

### Optimize queue worker

```bash
# Run with 4 workers (processes) for parallel processing
php artisan queue:work database --queue=transactions --daemon --processes=4

# Or use supervisor with `numprocs=4` (as shown in production section)
```

### Database index for jobs table

```sql
CREATE INDEX idx_queue_on_queue_connection_status ON jobs(queue, connection, status);
CREATE INDEX idx_failed_jobs_on_queue ON failed_jobs(queue);
```

### Monitor memory usage

```bash
# Run worker with max memory limit (useful in long-running process)
php artisan queue:work database --queue=transactions --max-memory=512
```

## 9. Integration with Monitoring Systems

### Example: Datadog Integration

```php
// In LogTransactionCreated listener
use DataDog\PlatformDog;

class LogTransactionCreated {
    public function handle(TransactionCreated $event) {
        $tx = $event->transaction;
        
        // Send custom metric to Datadog
        \DataDog\PlatformDog::metrics()->distribution(
            'transaction.latency',
            $tx->latency_ms,
            ['account_id' => $tx->account_id, 'type' => $tx->type]
        );
    }
}
```

### Example: Prometheus Integration

```php
// Use Prometheus client library
$counter = \Prometheus\CollectorRegistry::getDefault()
    ->getOrRegisterCounter(
        'transactions',
        'total_created',
        'Total transactions created',
        ['type']
    );

$counter->inc(['type' => $tx->type]);
```

## 10. Troubleshooting

### Workers not processing jobs

```bash
# Check if queue worker is running
ps aux | grep 'queue:work'

# Start worker in foreground to see errors
php artisan queue:work --queue=transactions --verbose

# Check Laravel logs
tail -f storage/logs/laravel.log
```

### Jobs stuck in queue

```bash
# Check database jobs table
SELECT * FROM jobs WHERE queue = 'transactions';

# Manually delete stuck job
DELETE FROM jobs WHERE id = <job-id>;
```

### Timeout issues

```bash
# If job takes long, increase timeout
php artisan queue:work --timeout=300 # 5 minutes

# Or make listener implement ShouldBeEncrypted, ShouldQueue
public $timeout = 300; // seconds
```

## 11. Production Checklist

- [ ] Queue driver configured (database or Redis)
- [ ] `jobs` and `failed_jobs` tables created
- [ ] Queue worker running under supervisor
- [ ] Queue worker monitoring in place
- [ ] Failed job retry policy configured
- [ ] Database indexes on `jobs` and `failed_jobs` tables
- [ ] Monitoring/alerting setup (Datadog, New Relic, etc.)
- [ ] Logs rotation configured
- [ ] Dead letter queue (DLQ) strategy defined
- [ ] Load test with concurrent requests to verify queue throughput

---

**Referensi:** [Laravel Queue Documentation](https://laravel.com/docs/queues)
