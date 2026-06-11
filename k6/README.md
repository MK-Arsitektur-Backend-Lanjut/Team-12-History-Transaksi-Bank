# k6 Stress Test — Modul Account Management

> Laporan hasil pengujian: [`documentation/account-management/K6_STRESS_TEST_REPORT.md`](../documentation/account-management/K6_STRESS_TEST_REPORT.md)

Stress test HTTP untuk 3 fitur inti modul:

| Skenario | File | Fitur | Endpoint |
|----------|------|-------|----------|
| Profil nasabah | `scenarios/01-profile.js` | API Profil Nasabah | `GET/PATCH /api/accounts/{id}` |
| Status rekening | `scenarios/02-status.js` | Manajemen Status Rekening | `PATCH /api/accounts/{id}/status` |
| Saldo atomik | `scenarios/03-balance-atomic.js` | Pembaruan Saldo Atomik | `POST /api/accounts/{id}/balance/adjust` |

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
    └── 03-balance-atomic.js
```

## Menjalankan skenario

Jalankan dari root project:

```powershell
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

k6 run k6/scenarios/01-profile.js
k6 run k6/scenarios/02-status.js
k6 run k6/scenarios/03-balance-atomic.js
```

Override base URL (opsional):

```powershell
k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/03-balance-atomic.js
```

Override saldo awal / nominal debit (skenario 03):

```powershell
k6 run -e INITIAL_BALANCE=10000000 -e DEBIT_AMOUNT=10 k6/scenarios/03-balance-atomic.js
```

## Ringkasan tiap skenario

### 1. Profil nasabah (`01-profile.js`)

- Setup: buat 1 akun test via API
- Beban: ramp 10 → 30 virtual users selama ~90 detik
- Tiap iterasi: `GET` profil, lalu `PATCH` `customer_name` dan `phone`
- Threshold: error < 5%, p95 latency < 2 detik

### 2. Status rekening (`02-status.js`)

- Setup: buat 1 akun aktif
- Beban: ramp 10 → 20 virtual users
- Tiap iterasi: `PATCH` status bergilir (`active` → `inactive` → `active`)
- Menguji stabilitas endpoint status di bawah beban

### 3. Saldo atomik (`03-balance-atomic.js`) — paling kritis

- Setup: buat 1 akun dengan saldo awal **10.000.000**
- Beban: ramp 10 → 50 virtual users, **semua menembak rekening yang sama**
- Tiap iterasi: `POST` debit Rp 10
- Menguji `lockForUpdate()` di `EloquentAccountRepository::adjustBalanceAtomically`
- Di akhir test, `teardown` mencetak saldo awal vs akhir ke console

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
| `winget install k6` gagal | Download manual dari https://grafana.com/docs/k6/latest/set-up/install-k6/ |
| Threshold gagal tapi checks tinggi | Naikkan threshold sementara untuk eksplorasi, lalu catat di laporan |

## Lampiran laporan tugas

Untuk setiap skenario, lampirkan:

1. Screenshot output terminal k6 (metrik utama)
2. Deskripsi skenario (VU, durasi, endpoint)
3. Untuk skenario 03: verifikasi saldo awal vs akhir + kesimpulan locking atomik
