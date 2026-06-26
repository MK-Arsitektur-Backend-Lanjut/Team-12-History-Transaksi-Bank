# Analisis Proyek: History Transaksi Bank — Team 12

**Mata Kuliah:** Arsitektur Backend Lanjut  
**Tanggal:** 26 Juni 2026  

---

## Sekilas tentang Proyek Ini

Proyek ini adalah sistem API perbankan sederhana yang dibangun pakai **Laravel 11** dan **MySQL**. Intinya: sistem ini bisa mencatat setiap transaksi masuk/keluar (debit/kredit) ke rekening nasabah, menampilkan riwayat mutasi, dan mengekspornya ke file CSV.

Selain fitur utama itu, proyek ini juga punya beberapa lapisan "pengamanan" dan "optimasi" yang dipelajari dari mata kuliah ini — mulai dari cara mencegah data saldo korup sampai cara mengukur ketahanan sistem di bawah tekanan banyak pengguna sekaligus.

---

## Apa Saja yang Sudah Dikerjakan

### 1. Fitur Inti: Manajemen Akun & Transaksi

Ini adalah fondasi utama aplikasi.

**Yang bisa dilakukan:**
- Membuat dan mengelola akun nasabah (nama, email, nomor HP, alamat)
- Mengubah status rekening: `active`, `inactive`, atau `blocked`
- Melakukan debit atau kredit ke saldo rekening
- Mencatat setiap transaksi secara otomatis dengan nomor referensi unik

**Endpoint API yang tersedia:**

| Fungsi | Metode | Alamat |
|--------|--------|--------|
| Daftar semua akun | GET | `/api/accounts` |
| Buat akun baru | POST | `/api/accounts` |
| Detail akun | GET | `/api/accounts/{id}` |
| Update profil | PATCH | `/api/accounts/{id}` |
| Update status | PATCH | `/api/accounts/{id}/status` |
| Sesuaikan saldo | POST | `/api/accounts/{id}/balance/adjust` |
| Riwayat mutasi | GET | `/api/statements` |
| Export CSV | GET | `/api/statements/export` |
| Catat transaksi | POST | `/api/transactions` |

---

### 2. Arsitektur Kode yang Rapi (Layered Architecture)

Kode tidak ditulis sembarangan — ada pembagian tanggung jawab yang jelas:

```
Request dari client
    ↓
Controller  → menerima input, validasi awal
    ↓
Service     → logika bisnis (misal: hitung saldo baru)
    ↓
Repository  → akses database (ambil/simpan data)
    ↓
Model       → representasi tabel di database
    ↓
Database (MySQL)
```

**Kenapa ini penting?** Kalau suatu hari mau ganti database atau ubah logika bisnis, tidak perlu bongkar seluruh kode — cukup ubah bagian yang relevan saja.

---

### 3. Keamanan Data Saldo: Pessimistic Locking

Ini salah satu bagian paling penting di proyek ini.

**Masalah yang diselesaikan:** Bayangkan ada 50 orang melakukan debit ke rekening yang sama dalam waktu bersamaan. Tanpa pengamanan khusus, dua proses bisa membaca saldo yang sama (`Rp 1.000`) sebelum salah satunya sempat menyimpan perubahan — hasilnya data saldo jadi salah (fenomena ini disebut **lost update** atau **race condition**).

**Solusinya:** Pakai `lockForUpdate()` di MySQL. Ini seperti memasang "tanda sedang dipakai" di baris data rekening. Selama satu proses belum selesai, proses lain harus menunggu giliran.

```php
// Contoh di kode (TransactionService.php)
$account = Account::query()
    ->whereKey($payload['account_id'])
    ->lockForUpdate()  // kunci dulu, baru baca saldo
    ->first();
```

**Hasilnya:** Sudah dibuktikan lewat stress test — dengan 50 pengguna virtual melakukan debit bersamaan, **tidak ada satu pun data yang korup**. Saldo akhir selalu sesuai perhitungan manual.

---

### 4. Redis Cache untuk Profil Akun

Daripada setiap request menarik data dari database (yang butuh baca disk), data profil akun disimpan sementara di **Redis** (di memori RAM yang jauh lebih cepat).

**Perbandingan nyata:**

| Kondisi | Jumlah query ke DB (5 akses berurutan) | Waktu rata-rata |
|---------|----------------------------------------|-----------------|
| Sebelum optimasi | 5 query ke MySQL disk | ~12–15 ms/query |
| Sesudah optimasi | 1 query ke MySQL + 4 dari Redis | ~0.1 ms dari Redis |

**Efeknya:** Beban database berkurang 80% untuk akses data profil yang sering diakses berulang.

---

### 5. Partisi Tabel Transaksi

Tabel `transactions` bisa sangat besar — bayangkan 1 rekening saja bisa punya 50.000+ transaksi. Kalau semua disimpan di satu tabel besar, query akan makin lambat seiring bertambahnya data.

**Solusinya:** Tabel dipartisi berdasarkan `transaction_date` (tanggal transaksi):

| Partisi | Rentang Tanggal |
|---------|----------------|
| `p2025` | Sebelum 2026 |
| `p2026_h1` | Januari–Juni 2026 |
| `p2026_h2` | Juli–Desember 2026 |
| `pmax` | Setelahnya |

Ketika ada query dengan filter tanggal, MySQL tidak perlu menyisir seluruh tabel — cukup partisi yang relevan saja.

---

### 6. Index Database untuk Query Cepat

Selain partisi, ditambahkan juga index komposit pada tabel transaksi:

- **`(account_id, transaction_date)`** — untuk query mutasi rekening per periode
- **`(account_id, type, transaction_date)`** — untuk filter berdasarkan jenis (debit/kredit)
- **`(account_id, id)`** — untuk cari transaksi terakhir per rekening

Ini seperti daftar isi di buku — MySQL tidak perlu baca dari halaman 1, tapi langsung loncat ke halaman yang tepat.

---

### 7. Sistem Event setelah Transaksi

Setiap kali transaksi berhasil disimpan, sistem otomatis menyiarkan sebuah **event** (`TransactionCreated`). Event ini kemudian "didengarkan" oleh beberapa listener:

| Listener | Fungsinya |
|----------|-----------|
| `LogTransactionCreated` | Mencatat transaksi ke log aplikasi + monitoring latency |
| `ReplicateTransactionToLedger` | Replikasi data ke sistem ledger (buku besar) |
| `SendTransactionNotification` | Kirim notifikasi (siap untuk email/push notification) |

**Manfaatnya:** Fitur-fitur tambahan ini tidak mengganggu proses utama penyimpanan transaksi. Kalau listener error, transaksi tetap tersimpan.

---

### 8. Monitoring Performa Transaksi

Sistem mencatat **berapa lama** setiap transaksi diproses (dalam milidetik). Data ini disimpan di kolom `latency_ms` pada tabel transaksi.

Ada `TransactionMonitoringService` yang bisa:
- Memberi peringatan kalau ada transaksi yang butuh lebih dari **500ms** (ambang batas)
- Menghitung **tingkat error** transaksi dalam jendela waktu tertentu
- Menghitung **persentil latency** (p50, p95, p99) untuk monitoring SLA

---

### 9. Seeder Data 50.000 Transaksi

Untuk keperluan testing dan demonstrasi performa, dibuat seeder yang bisa mengisi database dengan data transaksi dalam jumlah besar secara otomatis.

- Default: **50.000 transaksi** per rekening, untuk 10 rekening = **500.000 baris data**
- Data dibuat secara acak dengan tanggal yang tersebar dalam 2 tahun terakhir
- Proses insert dilakukan per batch 1.000 baris (bukan satu per satu) agar efisien

---

### 10. Stress Test dengan k6

Untuk membuktikan sistem kuat di bawah tekanan nyata, dipakai alat bernama **k6** — program yang bisa mensimulasikan banyak pengguna mengakses API bersamaan.

**6 skenario yang diuji:**

| Skenario | Yang Diuji | Beban |
|----------|------------|-------|
| 01 - Profil Nasabah | GET/PATCH data profil | Sampai 30 pengguna bersamaan |
| 02 - Status Rekening | Ubah status active/inactive | Sampai 20 pengguna bersamaan |
| 03 - Saldo Atomik | Debit saldo dengan locking | Sampai 50 pengguna bersamaan |
| 04 - Logging Transaksi | POST transaksi baru | Sampai 50 pengguna bersamaan |
| 05 - Riwayat Mutasi | GET statement + pagination | Sampai 30 pengguna bersamaan |
| 06 - Export CSV | Download file CSV besar | Sampai 20 pengguna bersamaan |

---

### 11. Pengujian Otomatis (PHPUnit)

Selain stress test, ada pengujian unit dan feature yang bisa dijalankan dengan `php artisan test`:

**Test yang sudah ada:**

| File Test | Yang Diuji |
|-----------|------------|
| `BeforeOptimizationDemoTest` | Membuktikan perbedaan sebelum vs sesudah optimasi (cache, locking, validasi status) |
| `TransactionEventTest` | Membuktikan event dikirim setelah transaksi, idempotency, dan integritas saldo |
| `AccountManagementSmokeTest` | Cek dasar semua endpoint bisa diakses |
| `DatabaseOptimizationExtensionTest` | Verifikasi index dan struktur tabel |

---

### 12. Dokumentasi API (Swagger)

Semua endpoint API didokumentasikan pakai **OpenAPI/Swagger** sehingga bisa langsung dicoba lewat browser di alamat `/api/documentation`.

---

### 13. Docker untuk Deployment

Proyek sudah siap dijalankan lewat Docker dengan konfigurasi:
- **Nginx** sebagai web server
- **Laravel (PHP-FPM 8.3)** sebagai aplikasi
- **MySQL 8.4** sebagai database

Cukup satu perintah `docker compose up -d` dan semua berjalan.

---

## Hasil yang Dicapai

### Hasil Stress Test (11 Juni 2026)

| Skenario | Error Rate | Integritas Data | Status |
|----------|------------|-----------------|--------|
| Profil Nasabah | **0%** (0 dari 1.177 request gagal) | — | ✅ Lulus |
| Status Rekening | **0%** (0 dari 1.070 request gagal) | — | ✅ Lulus |
| **Saldo Atomik** | **0%** (0 dari 917 request gagal) | **Saldo selalu benar** | ✅ Lulus sempurna |

**Verifikasi saldo (skenario 03):**
- Saldo awal: Rp 10.000.000
- Setelah 915 kali debit @ Rp 10 oleh 50 pengguna bersamaan
- Saldo akhir: Rp 9.990.850
- Kalkulasi: 10.000.000 − (915 × 10) = **9.990.850** ✅ Persis cocok

Ini membuktikan tidak ada satu pun transaksi yang "terlewat" atau "tertimpa" — data 100% konsisten.

### Hasil Pengujian Unit

```
PASS  Tests\Feature\BeforeOptimizationDemoTest
  ✓ caching overhead demo                   (1.10s)
  ✓ concurrency race condition lost update  (0.07s)
  ✓ inactive account transaction bypass     (0.18s)

Tests: 3 passed (6 assertions) — Duration: 2.68s
```

### Performa Aggregasi Data Besar

Pembuatan ringkasan saldo harian untuk 100 rekening dengan total 50.000 transaksi selesai dalam **~1.82 detik** — ini menunjukkan partisi tabel dan index bekerja efektif.

---

## Kesimpulan

Yang berhasil dibangun di proyek ini bukan sekadar CRUD biasa. Ada beberapa lapisan yang ditambahkan untuk membuat sistem lebih tangguh:

1. **Locking database** → saldo tidak bisa korup meski banyak request bersamaan
2. **Redis cache** → akses data profil jauh lebih cepat, beban DB berkurang
3. **Partisi tabel** → query tetap cepat walau data sudah ratusan ribu baris
4. **Index database** → pencarian berdasarkan tanggal dan akun jadi efisien
5. **Event system** → fitur tambahan (notifikasi, log, replikasi) tidak mengganggu alur utama
6. **Monitoring** → sistem bisa mendeteksi sendiri kalau ada transaksi yang lambat
7. **Stress test** → terbukti kuat diuji dengan simulasi 50 pengguna bersamaan

Semua konsep ini adalah fondasi dari sistem perbankan nyata — hanya saja dalam skala yang lebih kecil dan dapat dipelajari.

---

*Dokumen ini ditulis berdasarkan analisis kode sumber, hasil test, dan dokumentasi yang ada di repository.*
