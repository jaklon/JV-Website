# Pembaruan JV Story: Google Photos

Pakai ZIP **jascon-jv-story-google-photos-update-2026-10-08.zip** jika paket lengkap JV Story
sudah pernah diextract. Paket pembaruan ini hanya mengganti aplikasi JV Story dan source
backend; password database, Client ID, Client Secret, folder media, dan portofolio tetap ada.
Tidak ada perubahan tabel MariaDB. Dependensi PHP dan FFmpeg dari paket lengkap tetap digunakan.

## Upload dan extract

1. Backup folder `public_html/jascon.my.id/jv-story` dan `jv_story_private/src` di cPanel.
2. Upload ZIP pembaruan ke **`/home/cirj8428`**.
3. Extract ke **`/home/cirj8428`**, izinkan penggantian file aplikasi yang namanya sama.
   Struktur `public_html/jascon.my.id/jv-story` dan `jv_story_private/src` sudah ada dalam ZIP.
4. Muat ulang halaman JV Story. Bila masih melihat versi lama, tekan Ctrl+Shift+R.

Jangan memakai paket installer lengkap untuk pembaruan ini: installer menyertakan
`config.local.php`, sedangkan ZIP pembaruan sengaja tidak menyertakannya.

## Aktifkan Google Photos satu kali

Gunakan **project Google Cloud yang sama** dengan Client ID login JV Story:

1. **APIs & Services → Library** → cari **Google Photos Picker API** → **Enable**.
   Layanan API: `photospicker.googleapis.com`. Google Picker API adalah API lain.
2. **Google Auth Platform → Data Access → Add or remove scopes**.
   Tambahkan scope berikut, lalu simpan:

   ```text
   https://www.googleapis.com/auth/photospicker.mediaitems.readonly
   ```

3. Pertahankan scope login `openid`, `email`, dan `profile`.
4. Jika status aplikasi **Testing**, pastikan **Audience → Test users** berisi:
   - `jasconkembuan9@gmail.com`
   - `vaniairwandi773@gmail.com`
5. Client ID dan Client Secret tetap sama. Redirect URI tetap:
   **`https://jascon.my.id/jv-story/api/auth/callback`**.

Konfigurasi API dan izin akun memerlukan pengaturan di Google Cloud serta persetujuan
Google dari pemilik akun; extract ZIP saja tidak mengaktifkan layanan Google.

Referensi: [Konfigurasi resmi Google Photos](https://developers.google.com/photos/overview/configure-your-app),
[Google Photos Picker](https://developers.google.com/photos/picker/guides/get-started-picker).

## Cara menggunakan

1. Login ke JV Story, buka **Tambah kenangan** atau edit kenangan milikmu.
2. Tekan **Ambil dari Google Photos**. Saat pertama kali, izinkan pemilihan foto/video
   memakai Gmail yang sama dengan akun JV Story yang sedang masuk.
3. Pilih foto/video di Google Photos, kemudian tekan **Selesai/Done**. Jika jendela pemilih
   tidak terbuka otomatis atau kamu memakai ponsel, klik **Buka Google Photos** di formulir.
4. Tunggu pengambilan file. Lampiran yang berhasil diambil masuk ke daftar dalam formulir.
5. Isi cerita dan tekan **Simpan kenangan**. File disimpan ke penyimpanan privat hosting dan
   dapat dipakai pada film kenangan seperti lampiran biasa.

Maksimal 20 file dan total 100 MB setiap pemilihan. Foto JPEG/PNG/WebP maksimal 10 MB;
video MP4 maksimal 50 MB. HEIC/RAW harus diubah formatnya terlebih dahulu. Video yang
masih diproses Google belum dapat diimpor; coba lagi setelah selesai. Jika beberapa file
gagal, lampiran yang sudah berhasil tetap ada. Tombol Batalkan impor menghentikan pengambilan.
Cerita yang belum disimpan tetap ada pada formulir yang masih terbuka.

Izin Photos diminta terpisah dari login. Token akses berumur pendek disimpan dalam sesi
PHP dan dihapus saat logout; token dan alamat unduhan privat Google tidak dikirim ke React.
Hanya item yang dipilih yang diambil. Sesi pemilih dibersihkan sesudah impor/batal; file
asli Google Photos tetap ada. Jangan menutup tab JV Story selama proses pengambilan.

## Pemeriksaan dan kendala

- **API belum diizinkan:** pastikan Google Photos Picker API aktif pada project Client ID yang dipakai.
- **Akun berbeda:** gunakan Gmail yang sama dengan sesi login JV Story, lalu coba kembali.
- **Izin belum diberikan:** hubungkan lagi, lalu pilih izin Google Photos pada layar Google.
- **Jendela tidak terbuka:** klik Buka Google Photos pada formulir.
- **Video besar atau pengambilan terputus:** coba video lebih kecil; batas waktu hosting/proxy
  dapat lebih rendah daripada batas aplikasi. Unduh dari Google Photos lalu unggah manual sebagai alternatif.
- **Tombol nonaktif di demo:** Google Photos memerlukan login asli, bukan data contoh.

Build, tes otomatis menggunakan fixture, dan pemeriksaan paket dilakukan secara lokal.
Impor dari akun Google sungguhan perlu diperiksa setelah API, scope, dan OAuth di hosting aktif.
