# Changelog

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
