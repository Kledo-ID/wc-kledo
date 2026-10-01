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

- Klik Menu <kbd>WooCommerce</kbd> » <kbd>Kledo</kbd>
- Pada tab `Configure`, isi API Key dan endpoint Kledo, kemudian klik tombol `Save changes`. Setelah tersimpan, bagian `Status API Key` menampilkan nama key, kapan terakhir dipakai, dan kapan kedaluwarsa.
- Atur pengaturan tagihan pada tab `Invoice`, jangan lupa untuk klik tombol `Save changes` ketika melakukan perubahan.

## Changelog

Lihat [CHANGELOG.md](CHANGELOG.md).


