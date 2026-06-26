# Analisis Backend — Modul Account Management

**Proyek:** Core Banking System (Team 12)
**Modul:** Modul 1 — Account Management
**Framework:** Laravel 11 (PHP 8.3) · MySQL 8.4 · Redis 7 · Docker Compose
**Tanggal analisis:** 26 Juni 2026

Dokumen ini menganalisis sisi backend Modul Account Management: arsitektur, alur kode, pemenuhan ketentuan teknis tugas, optimasi yang sudah dikerjakan, hasil pengujian, serta temuan teknis dan rekomendasi. Analisis dibuat dengan membaca langsung kode sumber (bukan hanya dokumentasi yang sudah ada).

---

## Daftar Isi

1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Tanggung Jawab Modul & Pemetaan ke Studi Kasus](#2-tanggung-jawab-modul--pemetaan-ke-studi-kasus)
3. [Arsitektur Backend (Layered + Repository Pattern)](#3-arsitektur-backend-layered--repository-pattern)
4. [Analisis Per Fitur Utama](#4-analisis-per-fitur-utama)
5. [Skema Database & Strategi Performa](#5-skema-database--strategi-performa)
6. [Optimasi yang Sudah Dilakukan](#6-optimasi-yang-sudah-dilakukan)
7. [Hasil Pengujian (PHPUnit + k6)](#7-hasil-pengujian-phpunit--k6)
8. [Pemenuhan Ketentuan Teknis Tugas](#8-pemenuhan-ketentuan-teknis-tugas)
9. [Temuan Teknis & Catatan](#9-temuan-teknis--catatan)
10. [Rekomendasi](#10-rekomendasi)

---

## 1. Ringkasan Eksekutif

Modul Account Management mengelola siklus hidup rekening nasabah dan operasi saldo pada inti sistem perbankan. Tantangan utama studi kasus — **integritas data saldo** dan **performa pada tabel transaksi jutaan baris** — dijawab oleh modul ini melalui tiga pilar teknis:

| Pilar | Mekanisme | Status |
|-------|-----------|--------|
| Integritas saldo konkuren | `DB::transaction` + `lockForUpdate` (pessimistic row-lock) | Terimplementasi & teruji 1000 VU |
| Latensi baca profil | Redis cache *cache-aside* + auto-invalidation via model event | Terimplementasi & teruji |
| Skalabilitas tabel transaksi | Partisi `RANGE COLUMNS(transaction_date)` + composite index | Terimplementasi & teruji 50k baris |

Secara arsitektur, modul menerapkan **Repository Pattern** yang bersih (interface + binding di service provider), pemisahan lapisan Controller → Service → Repository, validasi via Form Request, dan dokumentasi OpenAPI inline. Seluruh ketentuan teknis wajib tugas terpenuhi.

---

## 2. Tanggung Jawab Modul & Pemetaan ke Studi Kasus

Deskripsi modul pada tugas: *"API Profil Nasabah, manajemen status rekening, dan fungsi pembaharuan saldo atomik."*

Ketiga tanggung jawab tersebut dipetakan langsung ke endpoint berikut (lihat [routes/api.php](../../routes/api.php)):

| Tanggung jawab | Endpoint | Handler |
|----------------|----------|---------|
| API Profil Nasabah | `GET /api/accounts`, `GET /api/accounts/{id}`, `POST /api/accounts`, `PATCH /api/accounts/{id}` | `AccountController::index/show/store/update` |
| Manajemen Status Rekening | `PATCH /api/accounts/{id}/status` | `AccountController::updateStatus` |
| Pembaruan Saldo Atomik | `POST /api/accounts/{id}/balance/adjust` | `AccountController::adjustBalance` |

Keterkaitan dengan tantangan studi kasus:
- **"saldo selalu sinkron dengan histori transaksi"** → operasi saldo atomik dengan row-lock memastikan tidak ada *lost update* saat ribuan mutasi konkuren.
- **"penarikan data historis tetap cepat meski jutaan baris"** → ditangani lewat partisi tabel `transactions` dan composite index `(account_id, transaction_date)`. Endpoint laporannya sendiri ada di modul Statement Generator, tetapi fondasi skema dan index disiapkan di sini.

---

## 3. Arsitektur Backend (Layered + Repository Pattern)

Alur request untuk operasi rekening:

```
HTTP Request
   │
   ▼
Route (routes/api.php)
   │
   ▼
FormRequest  ── validasi input (StoreAccountRequest, AdjustAccountBalanceRequest, dst.)
   │
   ▼
AccountController (app/Http/Controllers/Api)  ── tipis, hanya orkestrasi HTTP
   │
   ▼
AccountService (app/Services/Account)  ── logika bisnis (generate nomor rekening, default status/saldo)
   │
   ▼
AccountRepositoryInterface  ── kontrak akses data
   │
   ▼
EloquentAccountRepository  ── implementasi (Eloquent + Redis cache + DB transaction)
   │
   ▼
MySQL / Redis
```

**Pemisahan tanggung jawab yang jelas:**

- **Controller** ([AccountController.php](../../app/Http/Controllers/Api/AccountController.php)) hanya menangani concern HTTP: ambil input tervalidasi, panggil service, bentuk JSON response, atur status code. Tidak ada logika bisnis maupun query di sini.
- **Service** ([AccountService.php](../../app/Services/Account/AccountService.php)) memegang aturan bisnis: pembuatan nomor rekening unik (`generateAccountNumber`), pengisian default `status='active'` dan `balance=0`. Service bergantung pada **interface** repository, bukan implementasi konkret — inversi dependensi yang benar.
- **Repository** ([EloquentAccountRepository.php](../../app/Repositories/Account/EloquentAccountRepository.php)) mengisolasi seluruh akses data: query Eloquent, caching, dan transaksi DB atomik.

**Binding Repository Pattern** dilakukan di [AppServiceProvider.php](../../app/Providers/AppServiceProvider.php#L17):

```php
$this->app->bind(AccountRepositoryInterface::class, EloquentAccountRepository::class);
```

Konsekuensi positif: implementasi data layer dapat ditukar (misal ke repository in-memory untuk test, atau backend lain) tanpa menyentuh Service/Controller. Ini sesuai betul dengan ketentuan **"Repository Pattern untuk memisahkan logika akses data"**.

---

## 4. Analisis Per Fitur Utama

### 4.1 API Profil Nasabah (Read-heavy OLTP)

Pembacaan profil dibungkus pola **cache-aside** di [EloquentAccountRepository::findById](../../app/Repositories/Account/EloquentAccountRepository.php#L20):

```php
public function findById(int $id): ?Account
{
    return $this->rememberAccount("account:id:{$id}", function () use ($id) {
        return Account::query()->find($id);
    });
}
```

Detail penting pada helper `rememberAccount` ([baris 98–121](../../app/Repositories/Account/EloquentAccountRepository.php#L98)): cache **menyimpan array atribut**, bukan objek `Account` utuh, lalu merekonstruksi model via `newFromBuilder()`. Ini adalah perbaikan dari bug awal (`__PHP_Incomplete_Class` saat unserialize model dari Redis) yang tercatat di riwayat run k6 11 Juni. Pendekatan ini lebih tahan terhadap perubahan struktur kelas model.

- **Pembuatan akun** ([store](../../app/Http/Controllers/Api/AccountController.php#L74)) memvalidasi email unik dan membatasi panjang field via [StoreAccountRequest](../../app/Http/Requests/StoreAccountRequest.php).
- **Update profil** ([update](../../app/Http/Controllers/Api/AccountController.php#L158)) memakai `Rule::unique(...)->ignore(...)` agar email pemilik sendiri tidak dianggap duplikat.

### 4.2 Manajemen Status Rekening

Status divalidasi ketat ke enum `active | inactive | blocked` melalui [UpdateAccountStatusRequest](../../app/Http/Requests/UpdateAccountStatusRequest.php#L19). Pembaruan status sendiri sederhana ([updateStatus](../../app/Repositories/Account/EloquentAccountRepository.php#L49)).

Yang membuat fitur ini bermakna secara bisnis adalah **integrasinya dengan operasi saldo**: status dijadikan *safety gate*. Lihat poin berikutnya.

### 4.3 Pembaruan Saldo Atomik (Write OLTP — fitur paling kritis)

Inti integritas data ada di [adjustBalanceAtomically](../../app/Repositories/Account/EloquentAccountRepository.php#L60):

```php
return DB::transaction(function () use ($accountId, $type, $amount) {
    $account = Account::query()
        ->whereKey($accountId)
        ->lockForUpdate()              // SELECT ... FOR UPDATE (row-level lock)
        ->firstOrFail();

    if ($account->status !== 'active') {            // safety gate status
        throw ValidationException::withMessages(['status' => 'Account is not active.']);
    }

    $currentBalance = (float) $account->balance;

    if ($type === 'debit' && $currentBalance < $amount) {   // cegah saldo negatif
        throw ValidationException::withMessages(['amount' => 'Insufficient balance.']);
    }

    $newBalance = $type === 'credit'
        ? $currentBalance + $amount
        : $currentBalance - $amount;

    $account->balance = round($newBalance, 2);
    $account->save();
    // ...
    return $account->refresh();
});
```

Tiga jaminan yang diberikan blok ini:

1. **Atomisitas** — `DB::transaction` menjamin *all-or-nothing*; bila ada exception (mis. saldo kurang), seluruh perubahan di-rollback.
2. **Isolasi konkurensi** — `lockForUpdate()` mengunci baris rekening hingga commit. Request konkuren pada rekening yang sama dipaksa antre, sehingga tidak ada dua proses membaca saldo lama yang sama (*lost update* dicegah).
3. **Integritas bisnis** — validasi status `active` dan validasi saldo cukup dilakukan **setelah** lock diambil, sehingga keputusan didasarkan pada data paling mutakhir.

Pemilihan input divalidasi di [AdjustAccountBalanceRequest](../../app/Http/Requests/AdjustAccountBalanceRequest.php): `type` harus `debit|credit`, `amount` numerik `> 0`.

---

## 5. Skema Database & Strategi Performa

### 5.1 Tabel `accounts`

Dari [migration accounts](../../database/migrations/2026_04_15_142417_create_accounts_table.php):

| Kolom | Tipe | Catatan |
|-------|------|---------|
| `account_number` | string, **unique** | nomor rekening |
| `email` | string, **unique** | identitas nasabah |
| `status` | enum(`active`,`inactive`,`blocked`), **index** | dipakai safety gate |
| `balance` | **decimal(18,2)** | presisi uang (bukan float) — tepat untuk nominal besar |

Pemakaian `decimal(18,2)` penting: menghindari galat pembulatan floating-point pada nominal uang dan mendukung saldo hingga ratusan triliun.

### 5.2 Tabel `transactions` — kunci skalabilitas

Tiga lapis optimasi disiapkan untuk menjaga query historis tetap cepat saat baris membengkak:

**a. Partisi tabel** ([partition migration](../../database/migrations/2026_06_04_000002_partition_transactions_table.php)):

```sql
ALTER TABLE transactions PARTITION BY RANGE COLUMNS(transaction_date) (
    PARTITION p2025    VALUES LESS THAN ('2026-01-01 00:00:00'),
    PARTITION p2026_h1 VALUES LESS THAN ('2026-07-01 00:00:00'),
    PARTITION p2026_h2 VALUES LESS THAN ('2027-01-01 00:00:00'),
    PARTITION pmax     VALUES LESS THAN MAXVALUE
);
```

Karena MySQL mensyaratkan kolom partisi masuk ke primary key, migration ini juga melakukan rekonstruksi: drop FK `account_id`, ubah PK menjadi komposit `(id, transaction_date)`, dan jadikan unique `reference_number` komposit dengan `transaction_date`. Manfaatnya **partition pruning** — query laporan untuk rentang tanggal tertentu hanya memindai partisi yang relevan, bukan seluruh tabel.

Migration ini juga punya **fallback SQLite** untuk environment testing (SQLite tidak mendukung partisi) — hanya membuat index `transaction_date`. Desain yang baik agar test suite tetap bisa jalan.

**b. Composite index** ([add_transaction_indexes](../../database/migrations/2026_06_04_000001_add_transaction_indexes.php)):

- `ix_transactions_account_date (account_id, transaction_date)` — query laporan & agregat per rekening per rentang tanggal.
- `ix_transactions_account_type_date (account_id, type, transaction_date)` — filter berdasarkan tipe.
- `ix_transactions_account_id_id (account_id, id)` — lookup transaksi terakhir per rekening.

**c. Pra-agregasi harian** — tabel `daily_balances_summary` + command `app:generate-daily-summary` melakukan rollup saldo harian, sehingga laporan jangka panjang tidak perlu `SUM` jutaan baris secara real-time.

### 5.3 Seeder untuk uji performa

[TransactionLoadSeeder](../../database/seeders/TransactionLoadSeeder.php) memenuhi ketentuan **minimal 50.000 transaksi per rekening**:

```php
$transactionsPerAccount = max(50000, (int) env('SEED_TRANSACTIONS_PER_ACCOUNT', 50000));
```

Karakteristik seeder yang baik:
- **Chunked insert** 1000 baris/batch — hemat memori, hindari query raksasa.
- Mengisi `balance_before`/`balance_after` yang konsisten secara berurutan, sehingga data historis realistis dan dapat diverifikasi.
- `transaction_date` disebar mundur per-detik agar tersebar lintas waktu (relevan untuk uji partisi & rentang tanggal).
- Konfigurable via env (`SEED_ACCOUNTS_COUNT`, `SEED_TRANSACTIONS_PER_ACCOUNT`).

---

## 6. Optimasi yang Sudah Dilakukan

Ringkasan optimasi backend yang sudah terpasang (detail di [REPORT_DB_OPTIMIZATION.md](REPORT_DB_OPTIMIZATION.md) dan [BUKTI_OPTIMASI_DATABASE.md](BUKTI_OPTIMASI_DATABASE.md)):

| # | Optimasi | Sebelum | Sesudah | Dampak |
|---|----------|---------|---------|--------|
| 1 | **Redis cache profil** (cache-aside) | tiap baca profil → query disk MySQL | data dari RAM Redis (TTL 1 jam) | latensi baca ~15–30ms → <1ms; ~80% reduksi query baca berulang |
| 2 | **Pessimistic locking** saldo | saldo bisa saling timpa (race condition) | `lockForUpdate` + `DB::transaction` | tidak ada lost update di 1000 VU |
| 3 | **Safety gate status** | rekening blocked tetap bisa mutasi | validasi `status==='active'` dalam transaksi terkunci | rekening non-aktif menolak mutasi |
| 4 | **Partisi tabel** `transactions` | full scan saat jutaan baris | RANGE COLUMNS by `transaction_date` | partition pruning untuk query rentang tanggal |
| 5 | **Composite index** | scan tanpa index pendukung | 3 index komposit `account_id`-leading | pagination & agregat laporan cepat |
| 6 | **Auto cache-invalidation** | risiko data basi | model event `saved`/`deleted` hapus cache | konsistensi cache (cache coherence) |

**Mekanisme invalidation cache** ada di model [Account::booted()](../../app/Models/Account.php#L27): setiap `save()` (termasuk setelah mutasi saldo) memicu `Cache::forget("account:id:{id}")` dan `Cache::forget("account:number:{number}")`, sehingga pembacaan berikutnya mengambil data segar.

---

## 7. Hasil Pengujian (PHPUnit + k6)

### 7.1 Pengujian fungsional (PHPUnit)

9 test lulus, mencakup smoke test endpoint, demonstrasi sebelum-vs-sesudah optimasi, caching/invalidation, dan integritas saldo konkuren:

```
PASS  Tests\Feature\BeforeOptimizationDemoTest (3)
PASS  Tests\Feature\AccountManagementSmokeTest (2)
PASS  Tests\Feature\DatabaseOptimizationExtensionTest (1)
PASS  Tests\Feature\TransactionEventTest (2)
Tests: 9 passed
```

Yang menonjol: `BeforeOptimizationDemoTest` secara sengaja membuat `UnoptimizedAccountRepository` (mock perilaku lama) untuk **membuktikan** kerentanan sebelum optimasi (race condition, bypass status, query overhead) — pendekatan demonstratif yang kuat untuk laporan.

### 7.2 Stress test (k6) — run final 1000 VU, 20 Juni 2026

Detail di [K6_STRESS_TEST_REPORT.md](K6_STRESS_TEST_REPORT.md).

#### Mengapa harus 1000 VU? (rasional pemilihan beban)

Angka 1000 Virtual User bukan dipilih sembarangan, melainkan untuk **mencerminkan profil beban Core Banking** sesuai konteks studi kasus ("ribuan mutasi rekening setiap detiknya"). Alasannya bertingkat:

1. **Memetakan klaim studi kasus ke beban nyata.** Studi kasus menyebut sistem mencatat *ribuan transaksi per detik*. Pengujian dengan 10–50 VU tidak akan pernah menyentuh kondisi itu. 1000 VU concurrent adalah angka realistis minimum untuk mensimulasikan "ribuan nasabah/sistem menembak loket yang sama bersamaan".

2. **Hanya beban tinggi yang membuktikan locking benar-benar bekerja.** Inilah alasan paling penting. Pada beban rendah, request datang nyaris berurutan sehingga *race condition* jarang terpicu — sistem yang **salah pun bisa terlihat benar**. Bug *lost update* baru muncul ketika banyak transaksi benar-benar bertabrakan di baris yang sama dalam jendela waktu sangat sempit. Dengan **1000 VU menembak satu rekening yang sama** (skenario 03), kita memaksa antrean lock MySQL terisi penuh — ini adalah *worst case* yang sengaja diciptakan. Karena saldo tetap konsisten di kondisi terburuk ini, kita punya bukti kuat bahwa `lockForUpdate` valid, bukan kebetulan.

3. **Menemukan titik jenuh (saturation point) infrastruktur.** 1000 VU pada Docker dev lokal sengaja melampaui kapasitas worker PHP-FPM. Tujuannya bukan agar latensi rendah, melainkan mengamati **bagaimana sistem berperilaku saat kelebihan beban**: apakah ia tetap menjaga integritas data (ya) dan tetap merespons tanpa crash (ya, error rate <3%), atau justru korup/tumbang. Sistem perbankan wajib *fail-safe*, bukan *fail-corrupt*.

4. **Ramp bertahap, bukan lonjakan instan.** Profil `CORE_BANKING_STRESS_STAGES` menaikkan beban 0 → 250 → 500 → 750 → 1000, menahan plateau 5 menit, lalu menurunkan. Pola ini meniru lonjakan trafik nyata (mis. jam gajian/akhir bulan) dan menguji ketahanan saat beban puncak *ditahan*, bukan sekadar disentuh sesaat.

**Kenapa skenario 04 (laporan) justru hanya 8 VU?** Ini disengaja dan konsisten dengan tujuan masing-masing uji. Skenario 04 menguji **kecepatan baca pada tabel 50k baris**, bukan konkurensi tulis. Memaksakan 1000 VU baca berat hanya akan mengantrekan PHP-FPM dan menghasilkan timeout — yang terukur jadi keterbatasan infrastruktur, bukan kualitas query laporan. Jadi beban write kritis diuji tinggi (1000 VU), beban read berat diuji pada konkurensi wajar (8 VU). Pemilihan beban mengikuti **tujuan pengujian**, bukan angka seragam.

#### Ringkasan hasil

| Skenario | Fitur | Peak VU | Error rate | Checks | Verdict |
|----------|-------|---------|------------|--------|---------|
| 01 Profil | API Profil Nasabah | 1000 | 0% | 100% | Berhasil |
| 02 Status | Status Rekening | 1000 | 2,75% | 97,24% | Berhasil |
| 03 Saldo | **Saldo Atomik** | 1000 | 0,75% | 99,24% | Berhasil |
| 04 Laporan | Statement 50k | 8 | lulus threshold | lulus threshold | Berhasil |

**Bukti integritas saldo (skenario 03)** — verifikasi matematis dari teardown k6:
- Saldo awal Rp 500.000.000, saldo akhir Rp 499.779.610, debit Rp 10/request.
- Selisih 220.390 ÷ 10 = **22.039 debit efektif** → cocok dengan rumus `saldo_akhir = saldo_awal − (debit × nominal)`.
- Kesimpulan: **tidak ada lost update** pada 1000 concurrent VU. `lockForUpdate` terbukti bekerja di skala beban tinggi.

Latensi tinggi (p95 ~47–59 detik) wajar pada profil 1000 VU di Docker dev lokal — efek antrean PHP-FPM dan serialisasi lock di MySQL, bukan kegagalan logika.

---

## 8. Pemenuhan Ketentuan Teknis Tugas

| # | Ketentuan | Status | Bukti |
|---|-----------|--------|-------|
| 1 | Menggunakan Laravel | Terpenuhi | Laravel 11, struktur app/ standar |
| 2 | Berjalan di Docker Container | Terpenuhi | [docker-compose.yml](../../docker-compose.yml): app (PHP-FPM 8.3), web (Nginx), db (MySQL 8.4), redis |
| 3 | Repository Pattern | Terpenuhi | interface + binding di [AppServiceProvider](../../app/Providers/AppServiceProvider.php) |
| 4 | Seeder ≥50.000 transaksi/rekening | Terpenuhi | [TransactionLoadSeeder](../../database/seeders/TransactionLoadSeeder.php) `max(50000, ...)` |
| 5 | Repository organization dari dosen | Terpenuhi | remote `MK-Arsitektur-Backend-Lanjut`, branch per-modul (lihat git log) |

Catatan tambahan yang melebihi ketentuan minimal: dokumentasi OpenAPI inline (atribut `#[OA\...]` di controller), event-driven (`TransactionCreated` + listeners), dan pra-agregasi saldo harian.

---

## 9. Temuan Teknis & Catatan

Beberapa hal yang layak diperhatikan saat penyempurnaan (tidak ada yang bersifat kritis/menggagalkan):

1. **Duplikasi/inkonsistensi key cache-invalidation.** Repository (`update`, `updateStatus`, `adjustBalanceAtomically`) memanggil `Cache::store('redis')->forget("account:profile:{id}")`, tetapi key `account:profile:{id}` **tidak pernah ditulis** di mana pun (yang ditulis adalah `account:id:{id}` dan `account:number:{number}`). Invalidasi yang benar-benar bekerja datang dari model event `Account::booted()`. Akibatnya `forget("account:profile:...")` adalah no-op (dead code) — tidak berbahaya, tetapi membingungkan. Sebaiknya disatukan: cukup andalkan model observer, atau samakan penamaan key.

2. **`paginate()` tidak di-cache.** [paginate](../../app/Repositories/Account/EloquentAccountRepository.php#L15) memakai `latest()->paginate()` langsung ke DB. Ini wajar (daftar berubah-ubah), tetapi pada tabel `accounts` besar `latest()` (order by `created_at`) tanpa index `created_at` bisa lambat. Saat ini jumlah akun kecil sehingga belum jadi masalah.

3. **Migrasi `transactions` ganda.** Ada beberapa file migrasi pembuatan `transactions` (`2026_04_15_230000`, `2026_04_16_000003`, `2026_04_22_000000`) dan dua migrasi index `(account_id, id)` yang fungsinya tumpang tindih (`ix_transactions_account_id_id` dan `transactions_account_id_id_index`). Perlu dipastikan hanya satu yang aktif agar `migrate:fresh` tidak bentrok. (Ini area lintas modul Transaction, bukan murni Account.)

4. **Tidak ada autentikasi pada endpoint API.** Seluruh route `/api/accounts/*` terbuka tanpa middleware auth. Untuk konteks tugas/simulasi ini dapat diterima, tetapi pada sistem perbankan nyata endpoint mutasi saldo wajib di belakang autentikasi + otorisasi. Layak disebut sebagai *known limitation* di laporan.

5. **`firstOrFail()` dalam transaksi** akan melempar `ModelNotFoundException` (HTTP 404 oleh handler Laravel) bila akun terhapus di antara `find()` controller dan lock — perilaku ini benar dan aman, sekadar dicatat.

---

## 10. Rekomendasi

| Prioritas | Rekomendasi | Alasan |
|-----------|-------------|--------|
| Tinggi | Bersihkan dead-code invalidation `account:profile:{id}`; standarkan ke satu strategi (model observer) | Menghilangkan kebingungan & potensi asumsi salah saat maintenance |
| Sedang | Tambahkan middleware auth (mis. Sanctum) pada route accounts, atau dokumentasikan sebagai batasan eksplisit | Endpoint mutasi saldo tanpa auth adalah risiko keamanan nyata |
| Sedang | Konsolidasikan migrasi `transactions` yang duplikat | Memastikan `migrate:fresh` deterministik |
| Rendah | Jadwalkan penambahan partisi `transaction_date` ke depan (mis. `p2027_h1`) sebelum `pmax` terlalu besar | Menjaga manfaat partition pruning jangka panjang |
| Rendah | Pertimbangkan index `created_at` bila tabel `accounts` tumbuh besar | Menjaga performa `paginate()->latest()` |

**Kesimpulan:** Backend Modul Account Management sudah solid dan memenuhi seluruh ketentuan teknis. Tiga fitur inti terimplementasi dengan benar, integritas saldo terbukti tahan di 1000 VU, dan strategi performa (partisi + index + cache + pra-agregasi) sudah disiapkan untuk skala jutaan baris. Temuan yang ada bersifat penyempurnaan, bukan cacat fungsional.
