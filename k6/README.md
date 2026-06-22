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

### Skenario 01–03 — Core Banking stress profile (peak **1000 VU**, ~21 menit/skenario)

Profil beban: `0→250→500→750→1000` (2m tiap tahap), **hold 1000 VU 5 menit**, ramp-down bertahap ke 0.

```powershell
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

docker compose restart
Start-Sleep -Seconds 60
docker compose exec db mysql -u laravel -plaravel modul_account_management -e "TRUNCATE TABLE cache;"

k6 run -e REQUEST_TIMEOUT=300s k6/scenarios/01-profile.js
Write-Host "Tunggu 180 detik sebelum skenario 02..." -ForegroundColor Yellow
Start-Sleep -Seconds 180

k6 run -e REQUEST_TIMEOUT=300s k6/scenarios/02-status.js
Write-Host "Tunggu 180 detik sebelum skenario 03..." -ForegroundColor Yellow
Start-Sleep -Seconds 180

k6 run -e REQUEST_TIMEOUT=300s -e INITIAL_BALANCE=500000000 k6/scenarios/03-balance-atomic.js
```

> Skenario 03: saldo awal **500 juta** (`INITIAL_BALANCE=500000000`) untuk menampung debit massal 1000 VU.  
> **Perkiraan total:** ~70 menit (3 × 21 menit + jeda). Disarankan jalankan **satu skenario per sesi** jika laptop keberatan.

### Skenario 04 — laporan 50.000 transaksi (copy-paste lengkap)

**Pertama kali** (seed + test, seed butuh beberapa menit):

```powershell
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

docker compose up -d
Start-Sleep -Seconds 15

docker compose exec app php artisan db:seed --class=TransactionLoadSeeder

docker compose exec db mysql -u laravel -plaravel modul_account_management -e "SELECT account_id, COUNT(*) AS total FROM transactions GROUP BY account_id;"

docker compose exec db mysql -u laravel -plaravel modul_account_management -e "TRUNCATE TABLE cache;"

k6 run k6/scenarios/04-statements.js
```

**Kalau data 50k sudah ada** (skip seed, langsung test saja):

```powershell
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

docker compose exec db mysql -u laravel -plaravel modul_account_management -e "TRUNCATE TABLE cache;"

k6 run k6/scenarios/04-statements.js
```

> Skenario 04 **otomatis mencari** akun yang punya ≥50k transaksi (probe id 1–50).  
> Jika gagal, paksa akun dari query MySQL di atas, misalnya:  
> `k6 run -e STATEMENT_ACCOUNT_ID=2 k6/scenarios/04-statements.js`

### Manual per skenario (tanpa jeda otomatis)

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

**Jika `setup()` timeout** (API lambat setelah seed/load), buat akun manual dulu lalu pakai `ACCOUNT_ID`:

```powershell
# 1. Restart Docker & tunggu API normal
docker compose restart
Start-Sleep -Seconds 45

# 2. Buat akun test (PowerShell)
$body = '{"customer_name":"K6 Test","email":"k6-manual@stress.local","balance":10000000,"status":"active"}'
$r = Invoke-RestMethod -Uri http://localhost:8000/api/accounts -Method Post -Body $body -ContentType 'application/json' -TimeoutSec 120
$r.data.id   # catat ID, misalnya 107

# 3. Jalankan k6 dengan akun yang sudah ada (skip POST di setup)
k6 run -e ACCOUNT_ID=107 k6/scenarios/03-balance-atomic.js
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

### 4. Laporan rekening — 50.000 transaksi (`04-statements.js`)

**Prasyarat data:** akun harus sudah punya ≥ 50.000 baris transaksi di database.

```powershell
# Seed sekali (butuh beberapa menit)
docker compose exec app php artisan db:seed --class=TransactionLoadSeeder

# Verifikasi jumlah transaksi
docker compose exec db mysql -u laravel -plaravel modul_account_management -e "SELECT account_id, COUNT(*) AS total FROM transactions GROUP BY account_id;"

# Kosongkan cache summary (opsional, jika pernah timeout)
docker compose exec db mysql -u laravel -plaravel modul_account_management -e "TRUNCATE TABLE cache;"

# Jalankan stress test read (bukan menulis 50k via API)
k6 run k6/scenarios/04-statements.js
```

Override akun / rentang tanggal:

```powershell
k6 run -e STATEMENT_ACCOUNT_ID=1 -e STATEMENT_DAYS_BACK=2 k6/scenarios/04-statements.js
```

- Setup: probe `GET /api/statements` — gagal jika transaksi < 50.000
- Beban: ramp 3 → 5 → 8 VU, sustain **3 menit** (~6 menit total), read-only
- Tiap iterasi (bergilir):
  - Halaman 1, `per_page=15` (umum di UI)
  - Halaman acak 1–100, `per_page=15` (uji offset pagination)
  - Halaman 1, `per_page=100` (maksimal per halaman)
- Query yang dibebani: pagination + agregat `SUM` di `getSummaryTotals()` pada tabel besar
- Teardown: satu kali `GET /api/statements/export` (benchmark export CSV, tidak concurrent)
- Threshold: error < 5%, checks > 95%, p95 latency < 30 detik

**Pisahkan akun:** jangan pakai akun yang sama dengan skenario 01–03. Skenario 04 auto-detect akun yang punya 50k transaksi (biasanya dari seeder).

**Jeda antar skenario:** jalankan satu per satu; tunggu 1–2 menit atau `docker compose restart` antara skenario agar API tidak terus melambat.

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
