# k6 Stress Test — Modul Account Management

> Laporan hasil pengujian: [`documentation/account-management/K6_STRESS_TEST_REPORT.md`](../documentation/account-management/K6_STRESS_TEST_REPORT.md)

Stress test HTTP untuk 4 area modul:

| Skenario | File | Fitur | Endpoint |
|----------|------|-------|----------|
| Profil nasabah | `scenarios/01-profile.js` | API Profil Nasabah | `GET/PATCH /api/accounts/{id}` |
| Status rekening | `scenarios/02-status.js` | Manajemen Status Rekening | `PATCH /api/accounts/{id}/status` |
| Saldo atomik | `scenarios/03-balance-atomic.js` | Pembaruan Saldo Atomik | `POST /api/accounts/{id}/balance/adjust` |
| Laporan 50k transaksi | `scenarios/04-statements.js` | Statement Generator | `GET /api/statements`, export CSV |

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
    └── 04-statements.js
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
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

k6 run k6/scenarios/01-profile.js
k6 run k6/scenarios/02-status.js
k6 run k6/scenarios/03-balance-atomic.js
k6 run k6/scenarios/04-statements.js
```

Override base URL (opsional):

```powershell
k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/03-balance-atomic.js
```

Override saldo awal / nominal debit (skenario 03):

```powershell
k6 run -e INITIAL_BALANCE=10000000 -e DEBIT_AMOUNT=10 k6/scenarios/03-balance-atomic.js
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

- Beban: **Core Banking profile** — peak **1000 VU**, hold 5 menit (~21 menit total)
- Threshold: error < 15%, p95 < 180 detik, checks > 85%

### 2. Status rekening (`02-status.js`)

- Beban: **Core Banking profile** — peak **1000 VU**, hold 5 menit (~21 menit total)
- Threshold: error < 15%, p95 < 180 detik, checks > 85%

### 3. Saldo atomik (`03-balance-atomic.js`) — paling kritis

- Beban: **Core Banking profile** — peak **1000 VU**, hold 5 menit, semua menembak rekening yang sama
- Saldo awal disarankan **500.000.000** untuk beban 1000 VU
- Threshold: error < 15%, checks > 85%, p95 < 180 detik
- Di akhir test, `teardown` mencetak saldo awal vs akhir ke console

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

Contoh interpretasi:

- `http_req_failed` tinggi → API error/timeout di bawah beban
- `p(95)` naik drastis di skenario 03 → normal karena antrean database lock
- `checks` turun → response tidak sesuai ekspektasi (status bukan 200, dll.)

## Verifikasi saldo setelah skenario 03

Rumus:

```
saldo_akhir = saldo_awal - (jumlah_debit_sukses × nominal_debit)
```

Contoh: saldo awal 10.000.000, 500 debit sukses × Rp 10 → saldo akhir 9.995.000.

**Via API** (ganti `{id}` dengan Account ID dari output teardown):

```powershell
curl http://localhost:8000/api/accounts/{id}
```

**Via MySQL:**

```powershell
docker compose exec db mysql -u laravel -plaravel modul_account_management -e "SELECT id, balance FROM accounts WHERE id={id};"
```

Jika saldo tidak sesuai perhitungan, ada indikasi race condition / lost update.

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| `connection refused` pada localhost:8000 | Pastikan `docker compose up -d` dan tunggu ~15 detik |
| `Failed to create account` di setup | Cek log Laravel: `docker compose logs app` |
| Banyak `422 Insufficient balance` | Perbesar `INITIAL_BALANCE` atau kecilkan `DEBIT_AMOUNT` |
| `04-statements` gagal di setup: transaksi < 50000 | Jalankan `TransactionLoadSeeder` dulu |
| `04-statements` timeout / 504 | Perkecil `STATEMENT_DAYS_BACK=1` atau naikkan `REQUEST_TIMEOUT=180s` |
| `winget install k6` gagal | Download manual dari https://grafana.com/docs/k6/latest/set-up/install-k6/ |
| Threshold gagal tapi checks tinggi | Latency threshold disesuaikan untuk Docker lokal; yang penting `http_req_failed` dan checks |

## Lampiran laporan tugas

Untuk setiap skenario, lampirkan:

1. Screenshot output terminal k6 (metrik utama)
2. Deskripsi skenario (VU, durasi, endpoint)
3. Untuk skenario 03: verifikasi saldo awal vs akhir + kesimpulan locking atomik
4. Untuk skenario 04: screenshot + catat `meta.total` di setup dan ukuran export CSV di teardown
