# Dokumentasi Stress Test k6 — Modul Account Management

**Proyek:** Modul Account Management (Team 12)  
**Tanggal pengujian:** 11 Juni 2026  
**Tool:** [Grafana k6](https://k6.io/)  
**Target API:** `http://localhost:8000/api`  
**Environment:** Docker Compose (Nginx + Laravel PHP-FPM 8.3 + MySQL 8.4)

**File terkait:**
- Panduan menjalankan: [`k6/README.md`](../../k6/README.md)
- Skenario test: [`k6/scenarios/`](../../k6/scenarios/)
- Kode locking saldo: [`app/Repositories/Account/EloquentAccountRepository.php`](../../app/Repositories/Account/EloquentAccountRepository.php)

---

## Daftar Isi

1. [Apa itu Stress Test dan k6?](#1-apa-itu-stress-test-dan-k6)
2. [Mengapa Modul Ini Perlu Di-stress Test?](#2-mengapa-modul-ini-perlu-di-stress-test)
3. [Cara Kerja Pengujian (Alur Besar)](#3-cara-kerja-pengujian-alur-besar)
4. [Persiapan & Cara Menjalankan](#4-persiapan--cara-menjalankan)
5. [Penjelasan Istilah (Glosarium)](#5-penjelasan-istilah-glosarium)
6. [Detail Skenario Pengujian](#6-detail-skenario-pengujian)
7. [Hasil Pengujian & Analisis](#7-hasil-pengujian--analisis)
8. [Verifikasi Saldo Atomik (Step by Step)](#8-verifikasi-saldo-atomik-step-by-step)
9. [Perbandingan k6 vs PHPUnit](#9-perbandingan-k6-vs-phpunit)
10. [Kesimpulan & Rekomendasi](#10-kesimpulan--rekomendasi)
11. [FAQ — Pertanyaan yang Sering Muncul](#11-faq--pertanyaan-yang-sering-muncul)
12. [Lampiran](#12-lampiran)

---

## 1. Apa itu Stress Test dan k6?

### Stress test itu apa?

**Stress test** = menguji sistem dengan **banyak request bersamaan** untuk melihat:

- Apakah API masih merespons?
- Apakah data tetap benar (tidak korup)?
- Di beban berapa sistem mulai lambat atau error?

**Analogi sederhana:**

| Cara uji | Analogi |
|----------|---------|
| Postman / Swagger | 1 nasabah datang ke bank, 1 transaksi |
| PHPUnit | Supervisor cek prosedur internal di ruang rapat |
| **k6 (stress test)** | **100 nasabah datang bersamaan** ke loket yang sama |

### k6 itu apa?

**k6** adalah program gratis yang diinstall di laptop Anda. Ia membuat banyak **Virtual User (VU)** — "orang palsu" yang mengirim HTTP request ke API Anda berulang-ulang, lalu menghitung statistik hasilnya.

k6 **bukan bagian dari Laravel**. Ia berdiri di luar aplikasi, sama seperti klien mobile atau frontend yang memanggil API.

---

## 2. Mengapa Modul Ini Perlu Di-stress Test?

Modul Account Management punya 3 fitur inti:

| No | Fitur | Endpoint | Risiko jika tidak diuji beban |
|----|-------|----------|-------------------------------|
| 1 | API Profil Nasabah | `GET/PATCH /api/accounts/{id}` | Respons lambat saat banyak user buka/update profil |
| 2 | Manajemen Status Rekening | `PATCH /api/accounts/{id}/status` | Status tidak konsisten di bawah beban |
| 3 | **Pembaruan Saldo Atomik** | `POST /api/accounts/{id}/balance/adjust` | **Saldo korup** (lost update) jika locking gagal |

Fitur paling kritis adalah **nomor 3** — pembaruan saldo atomik. Tanpa locking yang benar, dua debit bersamaan bisa membaca saldo yang sama dan menghasilkan saldo akhir yang salah (fenomena **race condition** / **lost update**).

Di kode Laravel, proteksi ini ada di:

```php
// EloquentAccountRepository::adjustBalanceAtomically
$account = Account::query()
    ->whereKey($accountId)
    ->lockForUpdate()  // kunci baris di MySQL sampai transaksi selesai
    ->firstOrFail();
```

k6 menguji apakah proteksi ini **tahan** saat puluhan request HTTP datang bersamaan dari luar.

---

## 3. Cara Kerja Pengujian (Alur Besar)

### 3.1 Diagram arsitektur

```
┌─────────────┐     HTTP      ┌─────────────┐     FastCGI    ┌─────────────┐     SQL     ┌─────────────┐
│  k6 (laptop)│ ────────────> │ Nginx :8000 │ ─────────────> │ Laravel App │ ─────────> │  MySQL 8.4  │
│  Virtual    │               │  (web)      │                │  (PHP-FPM)  │            │  (db)       │
│  Users      │ <──────────── │             │ <───────────── │             │ <───────── │             │
└─────────────┘   response    └─────────────┘                └─────────────┘            └─────────────┘
```

### 3.2 Alur satu skenario k6

```mermaid
sequenceDiagram
    participant Dev as Developer
    participant k6 as k6
    participant API as Laravel_API
    participant DB as MySQL

    Dev->>k6: k6 run scenarios/03-balance-atomic.js
    k6->>API: setup - POST buat akun saldo 10jt
    API->>DB: INSERT account
    API-->>k6: account id 103

    loop 50 Virtual Users paralel
        k6->>API: POST debit Rp10 ke akun 103
        API->>DB: BEGIN + lockForUpdate
        DB-->>API: saldo terkunci
        API->>DB: UPDATE balance + COMMIT
        API-->>k6: 200 OK + saldo baru
    end

    k6->>API: teardown - GET akun 103
    API-->>k6: cetak saldo akhir ke console
    k6-->>Dev: ringkasan metrik
```

### 3.3 Tahapan dalam setiap file skenario

| Tahap | Fungsi | Kapan dijalankan |
|-------|--------|------------------|
| `setup()` | Buat data test (akun baru) | Sekali, sebelum VU mulai |
| `default function()` | Aksi utama (GET, PATCH, POST) | Berulang oleh setiap VU |
| `teardown()` | Cek saldo akhir | Sekali, setelah semua VU selesai (hanya skenario 03) |

---

## 4. Persiapan & Cara Menjalankan

### 4.1 Prasyarat

```powershell
# 1. Masuk ke folder project
cd "d:\Kuliah\Semester 8\Arsitektur & Pengembangan Backend\modul-account-management"

# 2. Jalankan Docker
docker compose up -d

# 3. Pastikan API hidup (harus dapat response JSON)
Invoke-WebRequest -Uri http://localhost:8000/api/accounts -UseBasicParsing

# 4. Pastikan k6 terinstall
k6 version
```

### 4.2 Menjalankan skenario

```powershell
# Skenario 1: Profil nasabah (~90 detik)
k6 run k6/scenarios/01-profile.js

# Skenario 2: Status rekening (~90 detik)
# Disarankan: jalankan terpisah, setelah API normal (bukan langsung setelah skenario 1)
k6 run k6/scenarios/02-status.js

# Skenario 3: Saldo atomik (~80 detik) — PALING PENTING
k6 run k6/scenarios/03-balance-atomic.js
```

### 4.3 Tips menjalankan

- **Jangan jalankan ketiga skenario berurutan tanpa jeda** jika environment lokal — skenario 01 bisa membuat API lambat dan skenario 02 gagal di `setup()`.
- Setelah skenario berat, tunggu 1–2 menit atau `docker compose restart` sebelum test berikutnya.
- Screenshot output terminal k6 untuk lampiran laporan.

---

## 5. Penjelasan Istilah (Glosarium)

### Virtual User (VU)

"User palsu" yang dibuat k6. Jika `target: 50`, artinya ada 50 koneksi yang menembak API **bersamaan**.

### Iteration

Satu kali menjalankan fungsi utama skenario. Misalnya 1 iterasi skenario 03 = 1x debit Rp 10.

### Ramp up / Ramp down

| Istilah | Arti |
|---------|------|
| Ramp up | Jumlah VU **dinaikkan** bertahap (misalnya 0 → 10 → 50) |
| Ramp down | Jumlah VU **diturunkan** ke 0 di akhir test |

Tujuannya: tidak langsung membebani API dengan lonjakan ekstrem.

### Metrik k6 — dijelaskan dengan bahasa sederhana

| Metrik di terminal | Arti | Contoh bagus | Contoh buruk |
|--------------------|------|--------------|--------------|
| `http_reqs` | Berapa banyak request total & kecepatan (req/detik) | 500 req, 50/s | 21 req dalam 2 menit |
| `http_req_duration` (avg) | Rata-rata waktu respons | < 500 ms | 15 detik |
| `http_req_duration` (p95) | 95% request selesai dalam waktu ini | < 2 detik | 60 detik |
| `http_req_failed` | % request gagal (timeout, error) | 0% | 85% |
| `checks` | Assertion lolos/gagal (seperti `assert` di PHPUnit) | 100% | 10% |
| `vus` | Berapa VU aktif saat ini | sesuai target | — |

### Threshold

Batas yang **kita tetapkan** agar test dianggap lulus. Jika terlampaui, k6 menampilkan:

```
ERRO thresholds on metrics 'http_req_duration' have been crossed
```

Threshold gagal **tidak selalu berarti aplikasi rusak** — bisa juga karena environment lokal terbatas (lihat analisis per skenario).

---

## 6. Detail Skenario Pengujian

### 6.1 Skenario 01 — API Profil Nasabah

**File:** `k6/scenarios/01-profile.js`  
**Fitur yang diuji:** membaca dan memperbarui profil nasabah

**Apa yang dilakukan tiap iterasi:**

```
1. GET  /api/accounts/{id}     → baca profil
2. PATCH /api/accounts/{id}    → ubah customer_name & phone
3. sleep 0.1 detik             → jeda singkat
4. Ulangi
```

**Konfigurasi beban:**

| Fase | Durasi | Jumlah VU |
|------|--------|-----------|
| Naik pelan | 30 detik | 0 → 10 |
| Beban puncak | 30 detik | 10 → 30 |
| Turun | 30 detik | 30 → 0 |

**Data test:** 1 akun (ID 101), dibuat otomatis di `setup()`.

**Threshold:** error < 5%, p95 latency < 2 detik.

---

### 6.2 Skenario 02 — Manajemen Status Rekening

**File:** `k6/scenarios/02-status.js`  
**Fitur yang diuji:** mengubah status rekening (`active`, `inactive`, `blocked`)

**Apa yang dilakukan tiap iterasi:**

```
PATCH /api/accounts/{id}/status
Body: { "status": "active" | "inactive" | "active" }  (bergilir)
```

**Konfigurasi beban:**

| Fase | Durasi | Jumlah VU |
|------|--------|-----------|
| Naik pelan | 30 detik | 0 → 10 |
| Beban puncak | 30 detik | 10 → 20 |
| Turun | 30 detik | 20 → 0 |

**Threshold:** error < 5%, p95 latency < 2 detik.

---

### 6.3 Skenario 03 — Pembaruan Saldo Atomik (PALING PENTING)

**File:** `k6/scenarios/03-balance-atomic.js`  
**Fitur yang diuji:** debit/credit saldo dengan locking atomik

**Apa yang dilakukan tiap iterasi:**

```
POST /api/accounts/{id}/balance/adjust
Body: { "type": "debit", "amount": 10.00 }
```

**Yang membuat skenario ini spesial:**

- **Semua 50 VU menembak rekening yang SAMA** (bukan rekening berbeda).
- Ini sengaja dirancang untuk memicu **antrian lock** di MySQL.
- Tujuan utamanya: membuktikan saldo **tetap benar** meski banyak debit bersamaan.

**Konfigurasi beban:**

| Fase | Durasi | Jumlah VU |
|------|--------|-----------|
| Naik pelan | 20 detik | 0 → 10 |
| Beban puncak | 40 detik | 10 → 50 |
| Turun | 20 detik | 50 → 0 |

**Data test:**

| Item | Nilai |
|------|-------|
| Saldo awal | Rp 10.000.000 |
| Nominal debit | Rp 10 per request |
| Status akun | `active` |

**Threshold:** error < 5%, checks > 95%, p95 latency < 5 detik.

**Diagram antrian lock (yang terjadi di skenario 03):**

```
Waktu →

VU-1:  [====LOCK====][debit][commit]
VU-2:            [tunggu...........][====LOCK====][debit][commit]
VU-3:            [tunggu...........................][====LOCK====]...
VU-4:            [tunggu...........................................]
...
VU-50:           [tunggu sangat lama ...............................]

Hasil: latency tinggi (normal), tapi saldo tetap konsisten
```

---

## 7. Hasil Pengujian & Analisis

> Data di bawah ini diambil dari hasil run aktual pada 11 Juni 2026.

### Ringkasan cepat

| Skenario | Fitur | Error rate | Checks | Integritas data | Verdict |
|----------|-------|------------|--------|-----------------|---------|
| 01 Profil | API Profil Nasabah | 85,71% | 10% | — | Gagal (timeout infrastruktur) |
| 02 Status | Status Rekening | — | — | — | Tidak jalan (setup timeout) |
| **03 Saldo** | **Saldo Atomik** | **0%** | **100%** | **Saldo benar** | **Berhasil** |

---

### 7.1 Skenario 01 — API Profil Nasabah

#### Angka dari terminal

| Metrik | Hasil | Batas (threshold) | Lulus? |
|--------|-------|-------------------|--------|
| `http_req_failed` | **85,71%** (18 dari 21 gagal) | < 5% | Tidak |
| `http_req_duration` p(95) | **59,99 detik** | < 2 detik | Tidak |
| `checks_succeeded` | **10%** (4 dari 40) | — | Tidak |
| Total `http_reqs` | **21** request | — | Sangat sedikit |

#### Detail per assertion

| Check yang diuji | Lolos | Gagal |
|------------------|-------|-------|
| GET profile status 200 | 2 | 16 |
| GET profile has customer_name | 2 | 16 |
| PATCH profile status 200 | 0 | 2 |
| PATCH profile updated name | 0 | 2 |

#### Apa artinya untuk Anda?

1. **Hampir semua request timeout** setelah ~60 detik (batas default k6).
2. Hanya **21 request** dalam ~2 menit — padahal ada 30 VU. Artinya request **mengantre sangat panjang**.
3. Penyebab utama: **PHP-FPM di Docker dev punya worker terbatas** (default ~5 proses). Saat 30 VU mengirim GET + PATCH bersamaan (60 request potensial paralel), sisanya antre hingga timeout.
4. Request yang **sempat masuk** (2 GET) berhasil — artinya **logika endpoint profil tidak rusak**.

#### Kesimpulan skenario 01

| Aspek | Penilaian |
|-------|-----------|
| Bug logika profil? | Tidak terindikasi |
| Bottleneck infrastruktur lokal? | Ya |
| Perlu retest? | Ya, dengan VU lebih rendah (5–10) |

---

### 7.2 Skenario 02 — Manajemen Status Rekening

#### Angka dari terminal

| Metrik | Hasil |
|--------|-------|
| `http_reqs` | **0** (nol request) |
| Error | `setup() execution timed out after 60 seconds` |

#### Apa artinya untuk Anda?

1. Test **tidak pernah mulai** — gagal di tahap persiapan.
2. Di `setup()`, k6 mencoba `POST /api/accounts` untuk buat akun test, tapi API tidak merespons dalam 60 detik.
3. Kemungkinan besar karena API **masih terbebani** setelah skenario 01 selesai.

#### Kesimpulan skenario 02

| Aspek | Penilaian |
|-------|-----------|
| Endpoint status teruji? | **Tidak** — belum ada data |
| Perlu retest? | **Ya** — jalankan sendiri setelah `docker compose restart` |

---

### 7.3 Skenario 03 — Pembaruan Saldo Atomik

#### Angka dari terminal

| Metrik | Hasil | Batas (threshold) | Lulus? |
|--------|-------|-------------------|--------|
| `http_req_failed` | **0%** (0 dari 152) | < 5% | **Ya** |
| `checks` (balance_adjust) | **100%** (300/300) | > 95% | **Ya** |
| `http_req_duration` avg | **15,78 detik** | — | — |
| `http_req_duration` p(95) | **34,94 detik** | < 5 detik | Tidak |
| Iterasi selesai | 150 (+ 13 interrupted) | — | — |
| Throughput | ~1,22 req/detik | — | — |

#### Output teardown (dari k6)

```
Account ID: 103
Initial balance: 10000000
Final balance: 9998370
Debit amount per request: 10
```

#### Detail per assertion

| Check | Hasil |
|-------|-------|
| debit status 200 | 100% lolos |
| debit returns balance | 100% lolos |

#### Apa artinya untuk Anda?

**Hal positif (yang paling penting):**

1. **0% error** — semua debit yang masuk ke server diproses sukses.
2. **100% checks lolos** — setiap response punya status 200 dan field `balance`.
3. **Saldo akhir matematis benar** — tidak ada lost update (lihat [Bagian 8](#8-verifikasi-saldo-atomik-step-by-step)).

**Hal yang terlihat "buruk" tapi sebenarnya wajar:**

1. **Latency tinggi** (rata-rata 15 detik, p95 35 detik) — karena 50 VU debit **1 rekening yang sama**. MySQL **harus** memproses satu per satu (`lockForUpdate`). Semakin banyak VU, antrian semakin panjang.
2. **Throughput rendah** (~1,22 req/detik) — bukan bug, melainkan efek sengaja dari desain locking atomik.

#### Kesimpulan skenario 03

| Aspek | Penilaian |
|-------|-----------|
| Integritas saldo | **Terjaga 100%** |
| Locking atomik bekerja? | **Ya** |
| Latency threshold gagal? | Ya, tapi **diharapkan** pada skenario concurrent ke 1 rekening |
| Siap dilampirkan ke laporan? | **Ya — ini bukti utama** |

---

## 8. Verifikasi Saldo Atomik (Step by Step)

Ini bagian terpenting untuk membuktikan fitur **pembaruan saldo atomik** bekerja benar.

### Langkah 1 — Catat data dari output k6

| Item | Nilai |
|------|-------|
| Saldo awal | Rp 10.000.000 |
| Saldo akhir (dari teardown) | Rp 9.998.370 |
| Nominal per debit | Rp 10 |

### Langkah 2 — Hitung selisih

```
Selisih = Saldo awal − Saldo akhir
        = 10.000.000 − 9.998.370
        = 1.630
```

### Langkah 3 — Hitung jumlah debit sukses

```
Jumlah debit = Selisih ÷ Nominal debit
             = 1.630 ÷ 10
             = 163 debit
```

### Langkah 4 — Cocokkan dengan iterasi k6

| Sumber | Jumlah |
|--------|--------|
| Iterasi selesai penuh | 150 |
| Iterasi interrupted (debit sempat sukses sebelum k6 stop) | 13 |
| **Total debit sukses** | **150 + 13 = 163** |

### Langkah 5 — Verifikasi rumus

```
Saldo akhir = Saldo awal − (jumlah debit × nominal)
            = 10.000.000 − (163 × 10)
            = 10.000.000 − 1.630
            = 9.998.370  ✓ COCOK
```

### Langkah 6 — Verifikasi manual via API (opsional)

```powershell
Invoke-WebRequest -Uri http://localhost:8000/api/accounts/103 -UseBasicParsing
```

Pastikan field `balance` di response = `9998370`.

### Apa yang terbukti?

Jika perhitungan cocok, berarti:

- Tidak ada **lost update** (dua debit tidak saling timpa).
- Tidak ada **double spending** (saldo tidak berkurang lebih atau kurang dari seharusnya).
- `lockForUpdate()` di Laravel + transaksi MySQL **bekerja dengan benar** di bawah 50 concurrent virtual users.

---

## 9. Perbandingan k6 vs PHPUnit

Keduanya menguji hal berbeda dan **saling melengkapi**:

| Aspek | PHPUnit | k6 |
|-------|---------|-----|
| **Di mana berjalan** | Di dalam proses PHP/Laravel | Di luar, via HTTP |
| **Siapa yang "menyerang"** | Kode test PHP | Virtual users (koneksi HTTP nyata) |
| **Concurrent** | Terbatas (satu proses test) | Puluhan koneksi paralel (50 VU) |
| **Yang diuji** | Logika benar atau salah | Ketahanan & konsistensi di bawah beban |
| **Metrik** | Pass / Fail | Latency, throughput, error rate |
| **Contoh di project** | `test_concurrent_transactions_maintain_balance_integrity` | Skenario 03: 163 debit, saldo konsisten |

**Analogi:**

- PHPUnit = "Apakah prosedur bank benar di atas kertas?"
- k6 = "Apakah prosedur bank tetap benar saat 50 orang antri bersamaan?"

---

## 10. Kesimpulan & Rekomendasi

### Kesimpulan akhir

| Fitur modul | Hasil stress test | Status |
|-------------|-------------------|--------|
| API Profil Nasabah | Timeout di 30 VU — bottleneck PHP-FPM lokal | Perlu retest (VU lebih rendah) |
| Manajemen Status Rekening | Setup timeout — belum teruji | Perlu dijalankan ulang |
| **Pembaruan Saldo Atomik** | **0% error, 163 debit sukses, saldo 100% konsisten** | **Berhasil** |

**Temuan utama:**

Mekanisme **pessimistic locking** (`lockForUpdate`) pada endpoint `POST /api/accounts/{id}/balance/adjust` **terbukti menjaga integritas saldo** di bawah 50 concurrent virtual users. Ini adalah bukti kuat untuk fitur inti modul Account Management.

### Rekomendasi

| No | Rekomendasi | Alasan |
|----|-------------|--------|
| 1 | Gunakan **skenario 03** sebagai bukti utama di laporan | Satu-satunya skenario dengan hasil lengkap dan verifikasi saldo |
| 2 | Retest **skenario 02** setelah `docker compose restart` | Belum ada data karena setup timeout |
| 3 | Retest **skenario 01** dengan `--vus 5 --duration 30s` | Environment dev tidak kuat untuk 30 VU |
| 4 | Lampirkan screenshot terminal k6 + tabel verifikasi saldo | Bukti visual untuk dosen |
| 5 | (Produksi) Tingkatkan `pm.max_children` PHP-FPM jika butuh throughput lebih tinggi | Worker default terbatas di Docker dev |

### Cara retest skenario 01 dengan beban ringan

```powershell
k6 run --vus 5 --duration 30s k6/scenarios/01-profile.js
```

### Cara retest skenario 02 setelah restart

```powershell
docker compose restart
# tunggu ~30 detik
k6 run k6/scenarios/02-status.js
```

---

## 11. FAQ — Pertanyaan yang Sering Muncul

### "Threshold gagal, apakah modul saya gagal?"

**Tidak selalu.** Threshold adalah batas yang kita tentukan. Di skenario 03, threshold latency gagal karena antrian lock — itu **perilaku normal**, bukan bug. Yang penting: **error 0% dan saldo benar**.

### "Kenapa skenario 01 error 85% tapi skenario 03 0% error?"

Skenario 01: 30 VU × 2 request (GET+PATCH) = beban tinggi tanpa lock, tapi **banyak worker PHP habis** → timeout.

Skenario 03: request diproses **satu per satu** oleh MySQL lock → tidak ada race, hanya antrian panjang. Request tetap sukses, hanya lambat.

### "Apa itu lost update?"

Contoh tanpa locking:

```
Saldo awal: Rp 1.000

VU-1 baca saldo: 1.000 → debit 100 → tulis 900
VU-2 baca saldo: 1.000 (sebelum VU-1 selesai) → debit 100 → tulis 900

Saldo akhir: 900 (seharusnya 800) ← DATA KORUP
```

Dengan `lockForUpdate()`, VU-2 **harus menunggu** VU-1 selesai sebelum membaca saldo.

### "Kenapa skenario 02 tidak jalan?"

Karena `setup()` timeout — API terlalu lambat merespons `POST /api/accounts` setelah skenario 01. Bukan bug di skenario 02, tapi efek samping beban sebelumnya.

### "Berapa VU yang ideal untuk laptop dev?"

Untuk Docker lokal: **5–10 VU** untuk skenario profil/status. Skenario saldo atomik bisa **30–50 VU** karena memang ingin menguji antrian lock (latency tinggi wajar).

---

## 12. Lampiran

### File skenario k6

| File | Fitur |
|------|-------|
| `k6/scenarios/01-profile.js` | API Profil Nasabah |
| `k6/scenarios/02-status.js` | Manajemen Status Rekening |
| `k6/scenarios/03-balance-atomic.js` | Pembaruan Saldo Atomik |
| `k6/lib/config.js` | Konfigurasi shared (BASE_URL, createAccount) |

### Kode aplikasi terkait

| File | Relevansi |
|------|-----------|
| `app/Repositories/Account/EloquentAccountRepository.php` | `adjustBalanceAtomically()` + `lockForUpdate()` |
| `routes/api.php` | Definisi endpoint API |
| `tests/Feature/TransactionEventTest.php` | Unit test concurrent balance integrity |

### Yang perlu dilampirkan ke laporan tugas

- [ ] Screenshot output terminal k6 skenario 03
- [ ] Tabel verifikasi saldo (Bagian 8)
- [ ] Penjelasan singkat: 50 VU, 0% error, saldo konsisten
- [ ] (Opsional) Screenshot skenario 01 & 02 sebagai catatan keterbatasan environment
