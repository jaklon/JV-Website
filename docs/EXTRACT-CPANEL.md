# Paket lengkap Jascon + JV Story

Paket ini dibuat untuk folder domain yang sudah diverifikasi di cPanel:
`/home/cirj8428/public_html/jascon.my.id`.

## Satu kali upload dan extract

1. Buka **cPanel → Files → File Manager → Home directory** (`/home/cirj8428`). Simpan backup folder domain sebelum menimpa versi portofolio.
2. Upload **jascon-jv-story-cpanel-2026-10-08.zip** ke Home directory.
3. Pilih ZIP → **Extract**, dengan tujuan **`/home/cirj8428`**. Jangan extract ke `public_html`, `jascon.my.id`, atau `jv-story`; struktur folder sudah ada di ZIP.
4. Hasilnya:

   ```text
   /home/cirj8428/
   ├── public_html/
   │   └── jascon.my.id/
   │       ├── index.html               ← portofolio
   │       ├── .htaccess
   │       ├── assets/ dan aset portofolio
   │       └── jv-story/
   │           ├── index.html
   │           ├── api.php dan media.php
   │           ├── .htaccess dan .user.ini
   │           ├── assets/
   │           └── ffmpeg/              ← mesin pembuat video
   └── jv_story_private/
       ├── config.local.php            ← isi tiga kredensial di sini
       ├── src/
       ├── vendor/                     ← dependensi PHP sudah disertakan
       ├── storage/                    ← media privat
       ├── schema.sql
       └── bin/install.php
   ```

Folder situs lain pada akun cPanel tidak masuk paket. Media lama tidak masuk ZIP.
Paket ini untuk pemasangan pertama JV Story. Saat memperbarui nanti, jangan
menimpa `config.local.php` dan jangan menghapus isi `storage/`.

## Tiga nilai yang harus diisi

Di File Manager, buka **Home directory → jv_story_private → config.local.php → Edit**.
Isi:

```php
'db_password' => 'PASSWORD_DATABASE_KAMU',
'google_client_id' => 'CLIENT_ID_GOOGLE_KAMU',
'google_client_secret' => 'CLIENT_SECRET_GOOGLE_KAMU',
```

Gunakan password milik user database `cirj8428_db_jv`, bukan password cPanel.
Apabila password mengandung tanda `'` atau `\`, escape dengan `\` pada string PHP.
Jangan kirim password atau Client Secret melalui chat.

Konfigurasi lain sudah diisi: host `localhost`, port `3306`, database
`cirj8428_JVDB`, user `cirj8428_db_jv`, HTTPS, dan path `/jv-story`.
Login hanya menerima `jasconkembuan9@gmail.com` dan `vaniairwandi773@gmail.com`.
Tidak ada password database atau kredensial OAuth yang tersedia ketika paket ini dibuat;
extract saja menampilkan halaman login, tetapi belum mengaktifkan penyimpanan dan login Google.

## Login Google

Gunakan OAuth client bertipe **Web application** di Google Cloud / Google Auth Platform.
Scopes: `openid`, `email`, `profile`. Tambahkan dua Gmail di atas sebagai test users
jika aplikasinya masih dalam mode Testing. Tidak diperlukan akun layanan atau kunci API database.

- Authorized JavaScript origin (jika diminta): `https://jascon.my.id`
- Authorized redirect URI: **`https://jascon.my.id/jv-story/api/auth/callback`**

Salin Client ID dan Client Secret langsung ke konfigurasi privat. Jangan memasukkan
keduanya ke source React, folder publik, atau URL.

Referensi: [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect).

## Pasang di Home Screen iPhone

Buka `https://jascon.my.id/jv-story/` melalui Safari → Bagikan → Tambah ke Layar Utama.
Jika tersedia, aktifkan **Open as Web App**, lalu ketuk **Add**. Ikon JV Story membuka
aplikasi dalam tampilan standalone. Koneksi internet tetap diperlukan. Panduan juga
tersedia lewat tombol **Pasang di Home Screen iPhone** pada halaman login dan Ruang kita.

Referensi: [Panduan Apple](https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios).

## Foto/video dari Google Photos

Tombol **Ambil dari Google Photos** tersedia pada formulir kenangan. Aktifkan
**Google Photos Picker API** pada project OAuth yang sama dan tambahkan scope
`https://www.googleapis.com/auth/photospicker.mediaitems.readonly` di Google Auth Platform → Data Access.
Client ID, Client Secret, dan callback tetap sama. Izin Photos diminta saat fitur digunakan.
Pilih maksimal 20 file (total 100 MB), kemudian tekan Simpan kenangan.
Foto JPEG/PNG/WebP hingga 10 MB dan video MP4 hingga 50 MB; HEIC/RAW perlu dikonversi.

Referensi: [Konfigurasi Google Photos](https://developers.google.com/photos/overview/configure-your-app).

## PHP dan database

Domain saat diperiksa menggunakan PHP 7.4, dan PHP 8.3 tersedia di daftar cPanel.
Backend memerlukan **PHP 8.1 atau lebih baru**. Paket menyertakan handler PHP 8.3
di `.htaccess` folder JV Story, agar tidak perlu mengubah PHP domain lain.
Jika hosting tidak menerapkan handler per-folder atau API menghasilkan 500,
pilih PHP 8.3 untuk **jascon.my.id saja** melalui
**Websites → Manage jascon.my.id → Settings → PHP Version**. Domain lain tidak perlu diubah.
Jika opsi ini dikelola hosting, gunakan **MultiPHP Manager** atau bantuan Rumahweb.
Ekstensi yang diperlukan: PDO MySQL, fileinfo, curl, mbstring, openssl, session.

Delapan tabel sudah dibuat pada `cirj8428_JVDB`. Tidak perlu menghapus database atau
mengimport ulang. `schema.sql` disertakan sebagai cadangan; gunakan hanya jika tabel
belum ada. Semua perintahnya memakai CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
Jika memilih menjalankan installer melalui Terminal cPanel, setelah mengisi config:

```sh
php /home/cirj8428/jv_story_private/bin/install.php
```

Pastikan terminal memakai PHP 8.1+. Installer tidak dapat dijalankan melalui URL publik.
Dependensi PHP dan mesin FFmpeg sudah ada di ZIP; hosting tidak membutuhkan npm, Composer,
Node.js, atau FFmpeg server. Video MP4 dibuat di browser pengguna.

Referensi: [cPanel MultiPHP Manager](https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/).

## Periksa setelah extract

1. `https://jascon.my.id/` tetap membuka portofolio tanpa tombol JV Story pada navigasi biasa.
2. Tahan logo **j.** selama 2 detik atau tekan **Ctrl+Alt+J**, kemudian pilih JV Story.
3. `https://jascon.my.id/jv-story/` menampilkan halaman login, bukan 404.
4. `https://jascon.my.id/jv-story/api/session` mengembalikan JSON dengan `user: null`.
   `configured: false` berarti Client ID/Secret belum lengkap; `true` berarti konfigurasi OAuth
   terdeteksi, belum membuktikan login dan koneksi database berhasil.
5. Setelah tiga kredensial diisi dan PHP sesuai, login dengan Gmail yang diizinkan.
   Buat satu kenangan dan muat ulang untuk memastikan tersimpan di MariaDB.
6. Foto/video dibuka melalui endpoint yang memerlukan sesi login. File aslinya berada di
   `jv_story_private/storage`, di luar semua folder publik.

## Bila ada kendala

- **404 di JV Story:** periksa `public_html/jascon.my.id/jv-story/index.html` dan `.htaccess`.
  Aktifkan **Show Hidden Files** di File Manager. Pastikan tidak terbentuk folder bersarang akibat salah extract.
- **500 atau halaman kosong:** periksa versi PHP dan log hosting. PHP 7.4 tidak dapat menjalankan backend ini. Jika handler `.htaccess` tidak diterapkan hosting, pilih PHP 8.3 untuk domain ini di Settings.
- **Google redirect_uri_mismatch:** URI callback harus sama persis, termasuk `/jv-story/api/auth/callback`.
- **Server/database belum siap:** periksa password DB, user yang ditugaskan ke database, dan izin user database.
- **Unggah ditolak karena ukuran:** paket menyediakan `.user.ini` (upload 52 MB, POST 55 MB).
  Batas hosting bisa lebih rendah; periksa **MultiPHP INI Editor**. Foto maks. 10 MB, MP4 maks. 50 MB, MP3 maks. 10 MB.
- **Film tidak selesai:** gunakan Chrome/Edge di laptop, kurangi media, dan biarkan tab terbuka.
  Film maksimal 30 adegan, total sumber 100 MB, durasi 3 menit, keluaran MP4 720p.

File publik dalam ZIP memakai izin 0644 dan folder 0755; folder backend 0700 dan
file privat 0600. PHP cPanel harus berjalan sebagai user akun yang sama. Jika ada
error akses backend, periksa konfigurasi PHP handler dengan Rumahweb; jangan membuat
folder backend menjadi publik atau memindahkan konfigurasi ke document root.
