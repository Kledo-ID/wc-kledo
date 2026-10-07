# WooCommerce Kledo

<!-- Plugin description -->
Plugin ini digunakan untuk mengintegrasikan WooCommerce dengan aplikasi akuntasi <a href="https://kledo.com/" target="_blank">Kledo</a>.
<!-- Plugin description end -->

## Instalasi

- Instal Plugin menggunakan `WordPress Plugin Search`

Di dalam area Admin WordPress, klik menu <kbd>Plugins</kbd> » <kbd>Add New</kbd> » <kbd>Cari plugin "Kledo"</kbd> » <kbd>Install Now</kbd>

- Secara Manual

Unduh [`kledo.zip` rilis terbaru](https://github.com/Kledo-ID/wc-kledo/releases/latest/download/kledo.zip), kemudian pada area Admin WordPress klik menu <kbd>Plugins</kbd> » <kbd>Add New</kbd> » <kbd>Upload Plugin</kbd> » <kbd>Pilih File ZIP Plugin</kbd> » <kbd>Install Now</kbd> » <kbd>Activate Plugin</kbd>

Versi sebelumnya bisa diunduh di halaman [Releases](https://github.com/Kledo-ID/wc-kledo/releases), file `kledo.zip` ada di bagian **Assets**.

> [!IMPORTANT]
> Gunakan `kledo.zip`, bukan **Source code (zip)** / **Source code (tar.gz)**. File source code berisi folder `wc-kledo-<versi>`, jadi WordPress akan menganggapnya plugin yang berbeda dan plugin tidak akan mendapat update dari wordpress.org.

Untuk update manual, unggah `kledo.zip` versi terbaru lalu pilih <kbd>Replace current with uploaded</kbd> (WordPress 5.5 ke atas). Pengaturan yang sudah disimpan tetap aman.

## Konfigurasi

1. Buka menu <kbd>WooCommerce</kbd> » <kbd>Kledo</kbd>.
2. Tab **Configure**: isi **API Key** dan **API Endpoint URL** dari Kledo, lalu klik <kbd>Save changes</kbd>. Bagian **Status API Key** akan menampilkan nama key, kapan terakhir dipakai, dan kapan kedaluwarsa.
   - Mulai 30 hari sebelum key kedaluwarsa (atau saat Kledo sudah menolaknya), muncul pemberitahuan di halaman admin dan **banner** di tab Configure. Klik <kbd>Buat API key baru di Kledo</kbd> untuk membuka halaman API key di Kledo ([Pengaturan › Integrasi › bagian *Developer & Keamanan* › **API Key**](https://app.kledo.com/#/settings/apps?activeKey=6)), buat key baru untuk WooCommerce, lalu tempel di kolom **API Key** dan klik <kbd>Save changes</kbd>. Sinkronisasi otomatis tetap berjalan tanpa putus.
3. Tab **Order**: aktifkan **Enable Create Order** kalau pesanan penjualan (sales order) perlu dibuat di Kledo, lalu atur prefix nomor, gudang, dan tag.
4. Tab **Invoice**: aktifkan **Enable Create Invoice**, lalu atur prefix nomor, status tagihan (lunas/belum lunas), akun pembayaran, gudang, dan tag.
   - **Link Invoice to Sales Order** (default aktif): tagihan ditautkan ke pesanan penjualannya, sehingga Kledo menutup pesanan penjualan setelah semua kuantitas ditagihkan.
   - **Close Sales Order When Invoiced** (default aktif): pesanan penjualan langsung ditutup saat tagihan dibuat.
   - **Create Sales Order First When an Order Skips Processing** (default aktif): pesanan yang langsung diubah ke Completed tetap dibuatkan pesanan penjualan dulu, lalu tagihannya menyusul. Matikan kalau pesanan seperti itu cukup dibuatkan tagihan saja.

## Cara Kerja

| Status pesanan WooCommerce | Yang dibuat di Kledo |
|---|---|
| **Processing** | Pesanan penjualan (kalau *Enable Create Order* aktif) |
| **Completed** | Tagihan (kalau *Enable Create Invoice* aktif), ditautkan ke pesanan penjualannya |
| Status lain (Pending, On hold, Cancelled, …) | Tidak ada |

Kledo menjawab pengiriman begitu transaksinya masuk antrean, **sebelum** transaksinya dibuat. Karena itu plugin **mengecek ulang** setiap transaksi ke Kledo, dan statusnya terlihat di kolom **Kledo** pada daftar pesanan:

| Status | Arti | Yang perlu dilakukan |
|---|---|---|
| **Belum dikirim** | Belum pernah dikirim ke Kledo | Kirim lewat aksi pesanan, aksi massal, atau tab Sinkronisasi |
| **Menunggu Kledo** | Sudah diterima Kledo, sedang dibuat | Tidak perlu apa-apa, dicek otomatis |
| **Menunggu pesanan penjualan** | Tagihan ditahan sampai pesanan penjualannya ada | Tidak perlu apa-apa, dikirim otomatis |
| **Ada di Kledo** | Sudah dipastikan ada di Kledo | — |
| **Gagal kirim, dicoba ulang** | Pengiriman gagal, plugin mencoba lagi otomatis | Biasanya tidak perlu apa-apa |
| **Gagal kirim** | Percobaan otomatis sudah berhenti | Periksa koneksi, lalu kirim ulang |
| **Ditolak Kledo** | Kledo menolak datanya (produk, pelanggan, akun, …) | Perbaiki data yang disebut di pesan error, lalu kirim ulang |
| **Gagal di Kledo** | Diterima Kledo, tetapi tidak pernah terbentuk | Kirim ulang; kalau masih gagal, jalankan Diagnosa |

Pengiriman yang gagal dicoba ulang otomatis selama dua hari (5 menit, lalu makin jarang sampai tiap 8 jam). Data yang ditolak Kledo tidak dicoba ulang, karena pasti ditolak lagi.

## Cara Pakai

### Daftar pesanan (WooCommerce » Orders)

- **Kolom Kledo** menampilkan status pesanan penjualan dan tagihan untuk setiap pesanan. Arahkan kursor ke status untuk melihat nomor transaksinya di Kledo.
- **Filter**: *Pesanan penjualan Kledo*, *Tagihan Kledo* (Belum dikirim / Menunggu Kledo / Ada di Kledo / Gagal), dan **rentang tanggal** *Dari – sampai*. Rentang tanggal menggantikan pilihan bulan di *All dates*.
  - Contoh: pilih status **Completed**, *Tagihan Kledo* = **Belum dikirim**, lalu tanggal **15–17 Okt** untuk melihat pesanan selesai di tanggal itu yang tagihannya belum ada di Kledo.
- **Aksi massal**: centang pesanan, pilih *Kledo: Kirim pesanan penjualan*, *Kledo: Kirim tagihan*, atau *Kledo: Cek status di Kledo*, lalu klik <kbd>Apply</kbd>. Maksimal 20 pesanan per klik, dan pesanan yang sudah ada di Kledo dilewati. Kalau hasil filternya lebih banyak, klik tombol **Kirim semuanya bertahap lewat Sinkronisasi →**.

### Halaman pesanan

Kotak **Kledo sync** menampilkan status pesanan penjualan dan tagihan, nomor Kledo, dan kapan terakhir dicek. Dari sini bisa:
- kirim atau kirim ulang;
- menjalankan aksi pesanan **Kledo: Periksa status Kledo sekarang**;
- membuka **Diagnosa pesanan ini**.

### Tab Kledo Status (Status Kledo)

Daftar semua pesanan penjualan dan tagihan yang pernah dikirim, lengkap dengan statusnya di Kledo.
- **Kartu ringkasan** di atas (Ada di Kledo, Menunggu Kledo, Perlu tindakan, Belum dikirim) bisa diklik untuk memfilter.
- Di bawah setiap nomor pesanan ada **Cek status · Kirim ulang · Buka pesanan · Diagnosa**. *Cek status* dan *Kirim ulang* memperbarui baris tanpa memuat ulang halaman.
- Filter jenis transaksi, rentang tanggal pesanan, dan nomor pesanan.
- Buka **Apa arti setiap status?** untuk penjelasan tiap status.

### Tab Sync (Sinkronisasi): kirim pesanan yang belum ada di Kledo

Untuk pesanan yang dibuat **sebelum plugin dipasang**, atau yang terlewat.

1. Setelah plugin dipasang atau di-update, **tidak ada yang dikirim otomatis**. Plugin hanya menghitung pesanan 30 hari terakhir yang belum ada di Kledo, lalu menampilkan pemberitahuan.
2. Buka tab **Sync**. Atur rentang tanggal kalau perlu (kosong = 30 hari terakhir), lalu klik <kbd>Hitung ulang</kbd> untuk melihat jumlahnya.
3. Klik <kbd>Proses bertahap</kbd> dan baca konfirmasinya:
   - tagihan dibuat lunas atau belum lunas sesuai tab Invoice;
   - transaksi memakai tanggal pesanan, jadi laporan bulan-bulan sebelumnya di Kledo ikut berubah;
   - stok gudang di Kledo berkurang pada tanggal pesanan.
4. Pesanan dikirim **5–10 per menit** (atur di *Speed*). Batch berikutnya baru dikirim setelah batch sebelumnya benar-benar terbentuk di Kledo. Anda boleh menutup halaman; proses tetap berjalan.
5. Gunakan <kbd>Jeda</kbd>, <kbd>Lanjutkan</kbd>, atau <kbd>Batalkan</kbd> kapan saja. Kalau Kledo belum selesai memproses satu batch setelah 30 menit, sinkronisasi dijeda otomatis.

> [!WARNING]
> Kledo saat ini **tidak menolak** transaksi bertanggal di periode yang sudah dikunci (tutup buku). Pastikan rentang tanggal sinkronisasi tidak masuk ke periode tersebut.

**Kirim Otomatis Setiap Hari** (default **mati**): kalau dicentang, setiap malam plugin mencari pesanan beberapa hari terakhir yang belum masuk ke Kledo, lalu mengirimkannya sendiri pelan-pelan. Atur jumlah hari dan jam mulainya. Biarkan mati kalau Anda ingin memeriksa dulu sebelum ada yang dikirim.

### Tab Diagnostics (Diagnosa): kenapa pesanan tidak masuk ke Kledo?

1. Buka tab **Diagnostics**, atau klik **Diagnosa** dari tab Kledo Status / halaman pesanan.
2. Pilih pesanan dari daftar lalu klik <kbd>Jalankan diagnosa</kbd>.
   - Saat dibuka, daftar menampilkan pesanan yang **belum ada di Kledo atau gagal** di bagian atas, lalu pesanan terbaru, masing-masing dengan status, tanggal, total, dan status pesanan penjualan/tagihannya di Kledo.
   - Ketik untuk mencari berdasarkan nomor pesanan, nama, atau email pelanggan.
   - Nomor pesanan juga bisa **diketik manual** lalu dipilih lewat opsi *Pakai nomor pesanan #…* (*Use order number #…* di WordPress berbahasa Inggris).

   Delapan pemeriksaan lalu berjalan satu per satu (✔ lolos · ⚠ peringatan · ✖ masalah):
   1. lingkungan toko
   2. koneksi ke Kledo
   3. pengaturan plugin
   4. pesanan
   5. data yang akan dikirim
   6. status di Kledo
   7. riwayat pengiriman
   8. antrean & tugas terjadwal
3. Di bawahnya muncul **Kemungkinan penyebab** dan **Yang bisa dilakukan**.
4. Centang **Sekalian kirim ulang ke Kledo dan rekam prosesnya** untuk mengirim ulang yang belum ada di Kledo, sambil merekam apa yang dikirim dan jawaban Kledo. Yang sudah ada di Kledo tidak dikirim ulang.
5. Klik <kbd>Unduh laporan</kbd>, lalu kirim file-nya ke tim Kledo dengan menyebutkan kode laporannya (misalnya `DIAG-7F3K2`). Versi JSON ditujukan untuk tim developer.

Privasi laporan:
- API key **tidak pernah** ikut.
- Nama, email, telepon, dan alamat pelanggan disamarkan sebagian, kecuali Anda mencentang *Sertakan data pelanggan lengkap*.
- Laporan disimpan di toko selama 7 hari dan hanya keluar kalau Anda mengunduh atau menyalinnya.

**Masalah yang hanya terjadi sesekali**: klik <kbd>Rekam selama 24 jam</kbd>. Semua pengiriman ke Kledo dicatat di log WooCommerce (source `wc-kledo-debug`), dan diagnosa yang dijalankan setelahnya ikut menyertakan rekaman itu. Rekaman berhenti sendiri setelah 24 jam.

### Tab Guide & FAQ (Panduan & FAQ)

Panduan lengkap di dalam plugin, di sebelah kiri tab **Support**:
- **12 bagian langkah demi langkah**: cara kerja plugin, membuat API key, mengatur pesanan penjualan dan tagihan, mengecek status, mengirim satu atau banyak pesanan, tab Status Kledo, Sinkronisasi, kirim otomatis harian, Diagnosa, serta percobaan ulang dan cron.
- **17 pertanyaan yang sering diajukan**.
- Kotak pencarian, dan tombol yang langsung membuka tab terkait.

Setiap tab pengaturan juga punya link **Panduan untuk tab ini** yang langsung membuka bagian panduannya.

### Log

Semua aktivitas plugin tercatat di <kbd>WooCommerce</kbd> » <kbd>Status</kbd> » <kbd>Logs</kbd> dengan source `wc-kledo`, dan hasil penting juga dicatat sebagai catatan pesanan.

### Akses cepat

- **Angka di menu Kledo**: menu <kbd>WooCommerce</kbd> » <kbd>Kledo</kbd> menampilkan angka, seperti angka di menu Orders, berisi jumlah pesanan yang perlu dicek. Yang dihitung: pesanan Processing/Completed 30 hari terakhir yang belum dikirim ke Kledo, ditambah pesanan yang pesanan penjualan atau tagihannya gagal, ditolak, atau tidak ditemukan di Kledo. Di atas 99 tampil **99+**. Angkanya hilang sendiri setelah pesanan-pesanan itu masuk ke Kledo (diperbarui paling lambat 10 menit). Angkanya juga muncul di tab tempat menanganinya: tab **Sync** (belum dikirim, buka untuk mengirimnya) dan tab **Kledo Status** (gagal di Kledo). Satu pesanan bisa masuk ke dua tab sekaligus, misalnya pesanan penjualannya gagal dan tagihannya belum dikirim, jadi jumlah angka di kedua tab bisa lebih besar dari angka di menu.
- Link **Settings** di halaman Plugins langsung membuka WooCommerce » Kledo.
- Di WordPress 6.9 ke atas, tekan <kbd>Ctrl</kbd>+<kbd>K</kbd> (<kbd>Cmd</kbd>+<kbd>K</kbd> di Mac) lalu ketik **kledo** untuk membuka tab mana pun.

## Bantuan

Ada pesanan yang tidak masuk ke Kledo? Jalankan **Diagnosa** dulu, lalu kirim laporannya ke tim Kledo lewat WhatsApp (tersedia di tab **Support**) atau email ke hello@kledo.com.

## Changelog

Lihat [CHANGELOG.md](CHANGELOG.md).


