1. Ringkasan Modul
    Modul Statement Generator digunakan untuk menampilkan riwayat transaksi nasabah dan menyediakan fitur ekspor data transaksi ke file CSV yang memiliki endpoint utama:
    - GET /api/statements → Menampilkan daftar transaksi berdasarkan rekening dan periode tertentu.
    - GET /api/statements/export → Mendownload seluruh transaksi dalam format CSV.

2. Optimasi Database
    Tabel transactions berpotensi menampung puluhan juta data transaksi per tahun. Tanpa optimasi tingkat database, query pencarian riwayat transaksi nasabah dengan rentang tanggal tertentu akan memicu full table scan yang sangat lambat. Database dioptimalkan menggunakan teknik range columns partitioning pada kolom transaction_date. Teknik ini memecah penyimpanan fisik tabel ke dalam beberapa partisi berdasarkan waktu transaksi.

3. Optimasi Indexing
    Untuk mempercepat kueri filter, pengurutan, dan pengelompokan agregasi, dipasang composite indexes pada tabel transactions:
    - ix_transactions_account_date untuk pencarian mutasi reguler.
    - ix_transactions_account_type_date untuk pencarian berdasarkan tipe mutasi.
    - ix_transactions_account_id_id untuk pencarian data transaksi terakhir.

4. Redis Caching
    Dengan redis caching, beban database berkurang drastis dan waktu respons menjadi sangat cepat karena data transaksi di-cache di Redis.

5. Pagination dan Export CSV
    Data transaksi ditampilkan per halaman untuk mengurangi ukuran data yang dikirim ke klien, sehingga mempercepat waktu respons. 
    Selain itu, fitur export CSV menyediakan cara untuk mendownload seluruh data transaksi dalam format CSV.

6. Hasil Stress Test
    Stress test dilakukan untuk mengetahui kemampuan sistem dalam menangani banyak pengguna yang mengakses layanan secara bersamaan.

    - Metode pengujian sebelum 
    Pengujian menggunakan skrip PHP (load_test_statements.php). Pengujian dilakukan dengan mengirim banyak request ke endpoint statement generator.
    Metode ini memiliki keterbatasan karena proses pengiriman request juga membebani aplikasi PHP yang digunakan sebagai alat pengujian. Akibatnya, hasil pengujian tidak sepenuhnya menggambarkan kemampuan server sebenarnya.

    - Metode pengujian setelah 
    Pengujian menggunakan Autocannon melalui skrip stress_to_crash.cjs. Autocannon dipilih karena mampu menghasilkan ribuan request secara bersamaan dengan lebih efisien sehingga hasil pengujian lebih akurat dalam mengukur performa server.
    Beban pengguna virtual (Virtual Users/VUs) ditingkatkan secara bertahap hingga ribuan koneksi untuk mengetahui batas kemampuan sistem.

    - Hasil pengujian
      Perbandingan waktu eksekusi pengujian beban sangat signifikan antara metode lama (PHP Load Test) dan metode baru (CommonJS Autocannon):

      Sebelum dilakukan optimasi, pengujian beban dilakukan menggunakan skrip PHP (load_test_statements.php). Saat mensimulasikan hingga 2000 pengguna, proses pengujian membutuhkan waktu lebih dari 400 detik untuk selesai. Hal ini terjadi karena aplikasi masih harus memproses query database yang cukup berat, sementara alat pengujian yang digunakan juga memiliki keterbatasan dalam mengirim banyak request secara bersamaan. Akibatnya, performa yang diperoleh belum mampu menggambarkan kemampuan server secara maksimal.

      Setelah optimasi diterapkan, pengujian dilakukan kembali menggunakan Autocannon (stress_to_crash.cjs). Berbeda dengan metode sebelumnya, Autocannon dapat menghasilkan ribuan request secara bersamaan dengan lebih efisien sehingga proses pengujian menjadi jauh lebih cepat. Selain itu, data yang sering diakses sudah disimpan di Redis Cache sehingga server tidak perlu berulang kali mengambil data dari database. Dengan kondisi tersebut, pengujian untuk 2000 pengguna dapat diselesaikan hanya dalam waktu sekitar 10 detik dan sistem tetap mampu merespons request dengan baik.

      Dari hasil pengujian tersebut terlihat bahwa optimasi yang dilakukan memberikan peningkatan performa yang signifikan. Waktu pengujian berkurang drastis, jumlah request yang dapat ditangani meningkat, dan respons sistem menjadi lebih cepat serta stabil. Hal ini menunjukkan bahwa penerapan partitioning, indexing, dan Redis Cache berhasil membantu sistem menangani beban akses yang tinggi dengan lebih efektif.

7. Kesimpulan
    Hasil stress test menunjukkan bahwa sistem yang telah dioptimasi mampu menangani beban yang jauh lebih besar dibandingkan sebelum optimasi. Kombinasi Redis Cache, indexing, dan partitioning berhasil meningkatkan kecepatan respons serta menjaga kestabilan sistem saat diakses oleh banyak pengguna secara bersamaan.