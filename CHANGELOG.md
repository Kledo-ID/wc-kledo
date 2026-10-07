# Changelog

## 1.8.0 (2026-10-07)

### Fitur baru

- Setiap pesanan penjualan dan tagihan yang dikirim kini dicek ulang ke Kledo. Kledo langsung menjawab begitu transaksi masuk antrean, jadi transaksi yang ternyata gagal dibuat di Kledo dulu tetap tampil "terkirim". Sekarang statusnya "Menunggu Kledo" sampai ditemukan, lalu "Ada di Kledo", atau "Gagal di Kledo" kalau tidak pernah terbentuk sehingga bisa dikirim ulang. Tidak ada kirim ulang otomatis, supaya tidak muncul duplikat kalau masih di proses di Kledo.
- Kolom "Kledo" di daftar pesanan menampilkan status pesanan penjualan dan tagihan secara terpisah, dengan nomor Kledo saat kursor diarahkan.
- Dua filter baru di daftar pesanan, "Pesanan penjualan Kledo" dan "Tagihan Kledo", misalnya untuk melihat semua pesanan Completed yang tagihannya belum ada di Kledo.
- Filter rentang tanggal (Dari–Sampai) di daftar pesanan. WooCommerce sendiri hanya bisa memfilter per bulan. Rentang yang diisi menggantikan pilihan bulan.
- Aksi massal di daftar pesanan: "Kledo: Kirim pesanan penjualan", "Kledo: Kirim tagihan", dan "Kledo: Cek status di Kledo". Yang sudah ada di Kledo atau sedang diproses dilewati.
- Tab Transaksi dirombak dan namanya diganti menjadi **Status Kledo**, supaya langsung jelas isinya: status setiap pesanan penjualan dan tagihan di Kledo. Isinya kartu ringkasan, tab per status Kledo, nomor Kledo dan waktu cek terakhir, langkah berikutnya untuk setiap baris gagal, serta panel "Apa arti setiap status?". Kolom diringkas dari 10 menjadi 5, dan aksi *Cek status · Kirim ulang · Buka pesanan* ada di bawah nomor pesanan, sehingga tetap terlihat di layar sempit. *Cek status* dan *Kirim ulang* berjalan tanpa memuat ulang halaman. Filter tanggal berupa rentang. Daftar sekarang dipaginasi di database, jadi pesanan di luar 2.500 terbaru tidak hilang lagi.
- Tab baru **Sinkronisasi** untuk mengirim pesanan yang belum ada di Kledo, misalnya pesanan dari sebelum plugin dipasang. Pesanan dikirim bertahap 5–10 per menit, dan batch berikutnya baru dikirim setelah batch sebelumnya benar-benar terbentuk di Kledo. Pesanan yang sudah ada di Kledo dilewati. Bisa dijeda, dilanjutkan, dan dibatalkan, dan dijeda otomatis kalau Kledo belum selesai setelah 30 menit. Tidak pernah berjalan sendiri saat plugin dipasang atau di-update: plugin hanya menghitung pesanan yang belum ada di Kledo dari 30 hari terakhir lalu menampilkan pemberitahuan.
- Opsi "Kirim Otomatis Setiap Hari" di tab Sinkronisasi (default mati) untuk mengirim sendiri, setiap malam, pesanan beberapa hari terakhir yang terlewat.
- Link "Kirim semuanya bertahap lewat Sinkronisasi" di daftar pesanan saat filter Kledo "Belum dikirim" atau "Gagal" dipakai, karena aksi massal dibatasi 20 pesanan per klik.
- Setting baru di tab Invoice, "Buat Pesanan Penjualan Dulu Jika Pesanan Langsung Selesai". Default aktif (perilaku sama seperti 1.7.4). Kalau dimatikan, pesanan yang langsung Completed hanya dibuatkan tagihan, tanpa tautan ke pesanan penjualan.
- Kotak Kledo di halaman pesanan merangkum status pesanan penjualan dan tagihan, dan aksi "Periksa status Kledo sekarang" juga memastikan keduanya ada di Kledo.
- "Cek status di Kledo" mengenali transaksi yang sudah ada di Kledo tapi belum tercatat terkirim, lalu menandainya terkirim agar tidak dibuat dua kali.

- Tab baru **Diagnosa** untuk mencari tahu kenapa sebuah pesanan tidak masuk ke Kledo. Masukkan nomor pesanan, lalu delapan pemeriksaan berjalan satu per satu (lingkungan toko, koneksi, pengaturan, pesanan, data yang akan dikirim, status di Kledo, riwayat pengiriman, tugas terjadwal) dan kemungkinan penyebabnya dijelaskan dengan bahasa sederhana beserta cara memperbaikinya. Bisa sekalian mengirim ulang sambil merekam apa yang dikirim dan jawaban Kledo. Laporannya bisa diunduh (teks atau JSON) untuk dikirim ke tim Kledo; API key tidak pernah ikut dan data pelanggan disamarkan secara default. Ada juga tombol "Rekam selama 24 jam" untuk masalah yang hanya terjadi sesekali. Pesanan dipilih dari daftar yang bisa dicari (pesanan yang belum ada di Kledo atau gagal tampil paling atas, lengkap dengan status, tanggal, total, dan statusnya di Kledo), atau nomornya diketik manual. Bisa dibuka dari tab Status Kledo, halaman pesanan, dan tab Support.

- Saat API key akan kedaluwarsa (30 hari sebelumnya) atau sudah ditolak Kledo, tab Configure menampilkan banner berisi langkah-langkah dan tombol **Buat API key baru di Kledo** yang langsung membuka halaman API key di Kledo (Pengaturan › Integrasi › Developer & Keamanan › API Key). Pemberitahuan di halaman admin lain juga membawa link yang sama.

- Tab baru **Panduan & FAQ** di sebelah tab Bantuan: panduan langkah demi langkah dari membuat API key sampai mencari pesanan yang tidak masuk, 17 pertanyaan yang sering diajukan, kotak pencarian, dan tombol ke tab terkait. Setiap tab pengaturan punya link "Panduan untuk tab ini".

- Angka notifikasi di menu **WooCommerce › Kledo**, seperti angka di menu Orders, berisi jumlah pesanan yang perlu dicek: pesanan Processing/Completed 30 hari terakhir yang belum dikirim ke Kledo, ditambah pesanan yang pesanan penjualan atau tagihannya gagal, ditolak, atau tidak ditemukan di Kledo. Di atas 99 ditampilkan "99+". Tidak muncul kalau jumlahnya nol, atau saat integrasi mati atau belum terhubung. Dihitung ulang paling lambat setiap 10 menit, dan langsung setiap kali status transaksi berubah. Angkanya juga dipecah ke tab tempat menanganinya: tab **Sinkronisasi** (belum dikirim) dan tab **Status Kledo** (gagal di Kledo). Warnanya mengikuti skema warna admin WordPress (merah di skema Default) dan tetap terlihat saat menu aktif atau disorot.

### Perbaikan

- Jadwal cron plugin dibersihkan saat plugin dinonaktifkan.

## 1.7.4 (2026-10-01)

Rilis ini langsung naik dari 1.5.0. Versi 1.6.0 sampai 1.7.3 tidak pernah dirilis, jadi semua perubahannya digabung di sini.

### Fitur baru

- Setting "Tautkan Tagihan ke Pesanan Penjualan". Kalau aktif, tagihan ditautkan ke pesanan penjualan di Kledo dan pesanannya otomatis ditutup setelah semua kuantitas ditagihkan. Default aktif.
- Setting "Tutup Pesanan Penjualan Saat Ditagihkan", untuk tetap menautkan tagihan tapi membiarkan pesanan penjualan terbuka. Default aktif.
- Bagian "Status API Key" di tab Configure: nama key, tanggal dibuat, terakhir dipakai, dan tanggal kedaluwarsa. Ada notifikasi 30 dan 7 hari sebelum key kedaluwarsa.
- Peringatan kalau key yang tersimpan sudah diganti otomatis oleh Kledo dan berbeda dengan key yang terdaftar di Kledo.
- API key di tab Configure sekarang disamarkan, hanya menampilkan nama perusahaan dan 4 karakter terakhir.
- Pesan error yang lebih jelas kalau API key ditolak Kledo, termasuk membedakan key yang sudah tidak berlaku dengan akun yang tidak lagi punya akses ke perusahaan.
- Kolom "Kledo" di daftar pesanan untuk melihat status pesanan penjualan di Kledo: sudah ditutup, masih diproses, perlu dicek, atau tidak ditautkan.
- Catatan otomatis di pesanan saat pesanan penjualan di Kledo sudah ditutup. Status pesanan WooCommerce tidak diubah.
- Aksi pesanan "Kledo: Periksa status Kledo sekarang".
- Link "Pengaturan" di halaman Plugins.
- Tab pengaturan bisa dibuka lewat command palette (Ctrl+K / Cmd+K) dengan mengetik "kledo". Butuh WordPress 6.9 ke atas.

### Perbaikan

- Sinkronisasi tidak lagi gagal tiba-tiba saat API key diperpanjang. Key baru dari Kledo sekarang langsung disimpan, sebelumnya plugin tetap memakai key lama yang sudah dicabut.
- Transaksi yang ditolak Kledo (HTTP 400) tidak lagi dicoba ulang terus-menerus selama dua hari.
- Sanitasi input di halaman admin, AJAX, dan helper.
- Nomor versi plugin yang masih tertulis 1.5.0.

### Lainnya

- Proses bootstrap plugin dipindah dari `kledo.php` ke class loader terpisah.
- PHPCS sekarang lewat Composer, dan kode disesuaikan dengan WordPress Coding Standards.
- Menambahkan template `wc-kledo.pot` dan memperbarui file terjemahan.

## 1.5.0 (2026-04-23)

- Antrean retry untuk transaksi yang gagal, jalan lewat WP-Cron dengan fallback dari admin.
- Halaman Transaksi di admin: filter, retry manual/massal, dan pengaturan kolom.
- Tombol sinkronisasi manual ke Kledo di halaman pesanan dan aksi pesanan.
- Log pengiriman dan retry masuk ke logger WooCommerce.
- Pengamanan AJAX admin dan dismiss notice (nonce dan capability).

## 1.4.1 (2026-02-02)

- Update terjemahan.

## 1.4.0 (2026-02-02)

- Koneksi ke Kledo sekarang memakai API key.

## 1.3.1 (2025-09-08)

- Perbaikan pesanan baru tidak terbuat saat status berubah ke processing.
- Perbaikan beberapa deprecation notice.

## 1.3.0 (2025-08-08)

- Pesanan baru dibuat di Kledo saat status pesanan menjadi processing.
- Bisa menambahkan lebih dari satu tag saat membuat transaksi (tagihan dan pesanan).
- Dukungan untuk [WooCommerce Shipment Tracking](https://woocommerce.com/products/shipment-tracking/).
- Perbaikan terjemahan.
- Tombol ditampilkan setelah semua kredensial terisi dan disimpan.
- Nilai default saat plugin pertama kali diinstal.

## 1.2.1 (2024-12-10)

- Update file terjemahan.

## 1.2.0

- Kompatibel dengan HPOS WooCommerce.
- Verifikasi SSL dinonaktifkan.
- Perbaikan terjemahan.

## 1.1.5 (2022-09-22)

- Tambahan jumlah diskon.
- Request token hanya diproses jika HTTP code 200.

## 1.1.4 (2021-11-09)

- Perbaikan select2 tidak tampil.

## 1.1.3 (2021-08-31)

- Update dokumentasi readme.

## 1.1.2 (2021-08-26)

- Perbaikan celah keamanan.

## 1.0.0 (2021-08-03)

- Rilis pertama plugin Kledo.
