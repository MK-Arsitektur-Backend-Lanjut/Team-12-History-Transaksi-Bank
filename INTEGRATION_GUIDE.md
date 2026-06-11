# Integration Guide — Transaction Logging API

Dokumentasi ini menunjukkan cara modul lain (Statement Generator, Logging, Notifications, dll) mengintegrasikan dengan Account Management sebagai sumber kebenaran transaksi.

---

## 1. Memanggil API via HTTP (dari modul eksternal)

### Contoh: Payment Module membuat transaksi

```http
POST /api/transactions HTTP/1.1
Host: modul-account-management:8000
Content-Type: application/json

{
  "account_id": 1,
  "type": "debit",
  "amount": 50000.00,
  "description": "Payment for invoice INV-2026-001",
  "reference_number": "PAY-2026-06-03-001"
}
```

**Response 201 Created:**
```json
{
  "success": true,
  "transaction": {
    "id": 42,
    "account_id": 1,
    "reference_number": "PAY-2026-06-03-001",
    "type": "debit",
    "amount": 50000.00,
    "balance_before": 150000.00,
    "balance_after": 100000.00,
    "transaction_date": "2026-06-03T15:30:00Z",
    "description": "Payment for invoice INV-2026-001",
    "created_at": "2026-06-03T15:30:00Z",
    "updated_at": "2026-06-03T15:30:00Z"
  }
}
```

### Idempotency

Gunakan `reference_number` sebagai idempotency key. Jika Anda mengirim request yang sama dengan `reference_number` yang sama, sistem akan memproses hanya sekali (planned future enhancement).

Contoh:
- Request 1: `reference_number: "PAY-2026-06-03-001"` → Transaksi diciptakan.
- Request 2: `reference_number: "PAY-2026-06-03-001"` (ulang) → Sama, tidak duplikat.

---

## 2. Menggunakan Service secara internal (dari modul yang sama)

Jika modul Anda berada dalam aplikasi yang sama, gunakan dependency injection untuk memanggil `TransactionService`:

### Contoh: Transfer Module

```php
<?php

namespace App\Http\Controllers;

use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    private TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => 'required|integer|exists:accounts,id',
            'to_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|gt:0',
        ]);

        try {
            // Debit dari akun sumber
            $debit = $this->transactionService->create([
                'account_id' => $validated['from_account_id'],
                'type' => 'debit',
                'amount' => $validated['amount'],
                'description' => "Transfer to account {$validated['to_account_id']}",
                'reference_number' => "TRF-{$validated['from_account_id']}-{$validated['to_account_id']}-" . now()->timestamp,
            ]);

            // Credit ke akun tujuan
            $credit = $this->transactionService->create([
                'account_id' => $validated['to_account_id'],
                'type' => 'credit',
                'amount' => $validated['amount'],
                'description' => "Transfer from account {$validated['from_account_id']}",
                'reference_number' => "TRF-{$validated['to_account_id']}-{$validated['from_account_id']}-" . now()->timestamp,
            ]);

            return response()->json([
                'success' => true,
                'debit_transaction' => $debit,
                'credit_transaction' => $credit,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
```

---

## 3. Membaca Statement (Konsumer)

Statement Generator adalah **konsumer** yang membaca data transaksi yang telah diciptakan. Gunakan endpoint atau repository untuk mengambil statement.

### Endpoint Statement

```http
GET /api/statements?account_id=1&start_date=2026-06-01&end_date=2026-06-03 HTTP/1.1
Host: modul-account-management:8000
```

**Response:**
```json
{
  "data": [
    {
      "id": 2,
      "account_id": 1,
      "reference_number": "550E8400-E29B-41D4-A716-446655440000",
      "type": "credit",
      "amount": 100000.00,
      "balance_before": 0.00,
      "balance_after": 100000.00,
      "transaction_date": "2026-06-03T10:00:00Z",
      "description": "Initial deposit",
      "created_at": "2026-06-03T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 1,
    "last_page": 1
  },
  "summary": {
    "total_credit": 100000.00,
    "total_debit": 0.00
  }
}
```

### Export Statement ke CSV

```http
GET /api/statements/export?account_id=1&start_date=2026-06-01&end_date=2026-06-03 HTTP/1.1
Host: modul-account-management:8000
```

**Response:** File CSV dengan kolom: `reference_number, transaction_date, type, amount, balance_before, balance_after, description`

---

## 4. Event Listener (Notifikasi / Replika)

Ketika transaksi diciptakan, event `TransactionCreated` di-dispatch. Modul lain dapat mendengarkan event ini:

### Contoh: Notification Module

```php
<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use Illuminate\Support\Facades\Notification;

class SendTransactionNotification
{
    public function handle(TransactionCreated $event): void
    {
        $tx = $event->transaction;

        // Kirim notifikasi ke customer
        Notification::send($tx->account->customer, new TransactionNotification($tx));
    }
}
```

Daftarkan listener di `app/Providers/EventServiceProvider.php`:

```php
protected $listen = [
    TransactionCreated::class => [
        SendTransactionNotification::class,
        LogTransactionCreated::class,
    ],
];
```

---

## 5. Query Database Langsung (untuk read-only)

Jika Anda perlu membaca transaksi dengan SQL custom, struktur tabel adalah:

```sql
SELECT * FROM transactions
WHERE account_id = ? 
  AND transaction_date BETWEEN ? AND ?
ORDER BY transaction_date DESC;
```

**Kolom:**
- `id`: Primary key
- `account_id`: Foreign key ke `accounts.id`
- `reference_number`: Unique identifier
- `type`: 'debit' atau 'credit'
- `amount`: Nilai transaksi
- `balance_before`: Saldo sebelum transaksi
- `balance_after`: Saldo setelah transaksi
- `transaction_date`: Waktu transaksi
- `description`: Keterangan opsional
- `created_at`, `updated_at`: Timestamps

**Index yang ada:**
- `account_id` (untuk filter cepat)
- `transaction_date` (untuk range query)
- Composite: `(account_id, transaction_date)` (untuk performa optimal)

---

## 6. Error Handling

Kesalahan umum dan respon:

| Kode | Pesan | Solusi |
|------|-------|--------|
| 404 | "Account not found" | Pastikan `account_id` valid |
| 422 | "Account is not active" | Akun harus berstatus 'active' |
| 422 | "Insufficient balance" | Saldo tidak cukup untuk debit |
| 422 | "Validation error" | Cek required fields (account_id, type, amount) |
| 500 | "Internal server error" | Hubungi admin |

---

## 7. Checklist Integrasi

- [ ] Dokumentasikan `reference_number` di system Anda (untuk traceability)
- [ ] Implementasikan retry logic dengan exponential backoff untuk HTTP calls
- [ ] Monitor latency transaksi (target < 500ms per transaksi)
- [ ] Setup alert untuk error rate > 5%
- [ ] Test idempotency (kirim request 2x, pastikan hanya 1 transaksi terciptakan)
- [ ] Test concurrent transactions pada 1 akun (gunakan lock mechanism)
- [ ] Validasi `balance_before` dan `balance_after` di sisi Anda
- [ ] Reconcile transaksi harian dengan statement API

---

**Hubungi:** Backend Team (@mkabc) untuk pertanyaan teknis.
