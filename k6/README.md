# k6 Stress Test — Modul History Transaksi Bank & Account Management

> Laporan hasil pengujian Account Management: [`documentation/account-management/K6_STRESS_TEST_REPORT.md`](../documentation/account-management/K6_STRESS_TEST_REPORT.md)

Stress test HTTP untuk modul Account Management dan modul History Transaksi Bank:

| Skenario | File | Fitur | Endpoint |
|----------|------|-------|----------|
| 01. Profil nasabah | `scenarios/01-profile.js` | API Profil Nasabah | `GET/PATCH /api/accounts/{id}` |
| 02. Status rekening | `scenarios/02-status.js` | Manajemen Status Rekening | `PATCH /api/accounts/{id}/status` |
| 03. Saldo atomik | `scenarios/03-balance-atomic.js` | Pembaruan Saldo Atomik | `POST /api/accounts/{id}/balance/adjust` |
| 04. Logging Transaksi | `scenarios/04-transaction-logging.js` | Pencatatan Transaksi Baru | `POST /api/transactions` |
| 05. Riwayat Mutasi | `scenarios/05-statement-query.js` | Pencarian Mutasi Rekening | `GET /api/statements` |
| 06. Ekspor Mutasi CSV | `scenarios/06-statement-export.js` | Stream Download Mutasi | `GET /api/statements/export` |

## Prasyarat

1. Stack Docker sudah berjalan:

```powershell
docker compose up -d
```

2. API dapat diakses:

```powershell
curl http://localhost:8000/api/accounts
```

3. k6 terinstall di Windows:

```powershell
winget install k6
k6 version
```

## Struktur folder

```
k6/
├── README.md
├── lib/
│   └── config.js          # BASE_URL, helper createAccount()
└── scenarios/
    ├── 01-profile.js
    ├── 02-status.js
    ├── 03-balance-atomic.js
    ├── 04-transaction-logging.js
    ├── 05-statement-query.js
    └── 06-statement-export.js
```

## Menjalankan skenario

Jalankan dari root project:

```powershell
# Skenario Modul Account Management
k6 run k6/scenarios/01-profile.js
k6 run k6/scenarios/02-status.js
k6 run k6/scenarios/03-balance-atomic.js

# Skenario Modul History Transaksi Bank
k6 run k6/scenarios/04-transaction-logging.js
k6 run k6/scenarios/05-statement-query.js
k6 run k6/scenarios/06-statement-export.js
```

Override parameters via environment variables (opsional):

```powershell
# Menjalankan dengan URL kustom
k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/04-transaction-logging.js

# Menjalankan dengan nominal debit atau saldo awal kustom
k6 run -e INITIAL_BALANCE=5000000 -e DEBIT_AMOUNT=50 k6/scenarios/04-transaction-logging.js
```

## Ringkasan tiap skenario

### 1. Profil nasabah (`01-profile.js`)
- **Setup**: Buat 1 akun test via API.
- **Beban**: Ramp 10 → 30 virtual users selama ~90 detik.
- **Tiap iterasi**: `GET` profil, lalu `PATCH` `customer_name` dan `phone`.
- **Threshold**: Error < 5%, p95 latency < 2 detik.

### 2. Status rekening (`02-status.js`)
- **Setup**: Buat 1 akun aktif.
- **Beban**: Ramp 10 → 20 virtual users.
- **Tiap iterasi**: `PATCH` status bergilir (`active` → `inactive` → `active`).
- **Tujuan**: Menguji stabilitas endpoint perubahan status.

### 3. Saldo atomik (`03-balance-atomic.js`)
- **Setup**: Buat 1 akun dengan saldo awal **10.000.000**.
- **Beban**: Ramp 10 → 50 virtual users menembak rekening yang sama.
- **Tiap iterasi**: `POST` debit Rp 10 via endpoint adjust balance.
- **Tujuan**: Menguji `lockForUpdate()` di level repository.

### 4. Logging Transaksi (`04-transaction-logging.js`)
- **Setup**: Buat 1 akun aktif dengan saldo awal **10.000.000**.
- **Beban**: Ramp 10 → 50 virtual users menembak ke endpoint transaksi utama.
- **Tiap iterasi**: `POST` transaksi debit Rp 10 ke `/api/transactions` dengan `reference_number` unik.
- **Tujuan**: Menguji `lockForUpdate()` di `TransactionService` dan performa insert data audit transaksi.

### 5. Riwayat Mutasi (`05-statement-query.js`)
- **Setup**: Mencari akun aktif yang ada di database (misal dari hasil seeder), atau membuat akun baru dan mengisinya dengan 50 transaksi dummy jika database kosong.
- **Beban**: Ramp 10 → 30 virtual users.
- **Tiap iterasi**: Mengirim request `GET /api/statements` dengan filter tanggal 3 tahun terakhir dan halaman acak (1 s.d. 3).
- **Tujuan**: Menguji performa pencarian database (index scan), pagination, dan kalkulasi ringkasan kredit/debit.

### 6. Ekspor Mutasi CSV (`06-statement-export.js`)
- **Setup**: Menggunakan akun aktif yang ada di database atau membuat baru dengan transaksi dummy.
- **Beban**: Ramp 5 → 20 virtual users.
- **Tiap iterasi**: Mengirim request `GET /api/statements/export` untuk men-stream data CSV dari seluruh riwayat transaksi.
- **Tujuan**: Menguji performa download data stream besar dan penggunaan memori server PHP-FPM di bawah beban unduhan konkuren.

## Cara membaca hasil k6

| Metrik | Arti |
|--------|------|
| `http_reqs` | Total request dan throughput (req/detik) |
| `http_req_duration` | Latency (avg, p95, max) |
| `http_req_failed` | Persentase request gagal |
| `checks` | Assertion yang lolos/gagal |
| `vus` | Jumlah virtual user aktif |

## Verifikasi saldo setelah Skenario 03 & 04

Untuk skenario 03 dan 04, Anda dapat memverifikasi integritas saldo menggunakan rumus berikut:

```
saldo_akhir = saldo_awal - (jumlah_debit_sukses × nominal_debit)
```

**Verifikasi via MySQL** (ganti `{id}` dengan ID akun yang dicetak saat teardown):

```powershell
docker compose exec db mysql -u laravel -plaravel modul_account_management -e "SELECT id, balance FROM accounts WHERE id={id};"
```

Jika saldo tidak sesuai dengan perhitungan di console teardown k6, ada indikasi lost update atau ketidakkonsistenan data.
