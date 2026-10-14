# JV Story di Home Screen iPhone

Pembaruan menambahkan manifest aplikasi, ikon JV Story, dukungan tampilan standalone,
dan tombol panduan pemasangan pada halaman login serta Ruang kita.

## Extract ke cPanel

1. Simpan backup folder aplikasi JV Story dan source backend sebelum pembaruan.
2. Upload **jascon-jv-story-iphone-update-2026-10-08.zip** ke **`/home/cirj8428`**.
3. Extract ke **`/home/cirj8428`** dan izinkan penggantian file aplikasi dengan nama sama.
   Struktur folder domain sudah disertakan. Jangan extract ke dalam folder `jv-story`.
4. Buka `https://jascon.my.id/jv-story/` dan muat ulang halaman.

ZIP pembaruan mempertahankan `config.local.php`, password, kredensial Google, media privat,
database, dan portofolio. Tidak ada konfigurasi Google atau perubahan database baru untuk
dukungan Home Screen. Pembaruan ini juga memuat implementasi Google Photos sebelumnya;
penggunaan Google Photos tetap memerlukan aktivasi API dan scope pada project OAuth.

## Pasang di iPhone

1. Buka **Safari** dan kunjungi **`https://jascon.my.id/jv-story/`**.
2. Ketuk **Bagikan / Share** (kotak dengan panah ke atas). Pada beberapa tata letak Safari,
   buka menu halaman terlebih dahulu, lalu pilih Bagikan.
3. Pilih **Tambah ke Layar Utama / Add to Home Screen**.
4. Jika pilihan **Buka sebagai App Web / Open as Web App** muncul, aktifkan.
5. Gunakan nama **JV Story**, lalu ketuk **Tambah / Add**.
6. Ketuk ikon JV Story pada Home Screen untuk membukanya sebagai aplikasi.

Pemasangan dilakukan manual oleh pemilik iPhone. Tombol panduan pada web tidak dapat
langsung memasang ikon tanpa langkah Safari tersebut.

Koneksi internet tetap diperlukan. Login masih memakai dua Gmail kalian. Jika diminta
masuk kembali setelah memasang, login dari aplikasi JV Story. Google dapat membuka
halaman akun ketika kamu login atau mengizinkan Google Photos.

Jika sebelumnya sudah ada pintasan JV Story yang selalu membuka tab Safari, hapus
pintasan tersebut dari Home Screen dan tambahkan ulang setelah pembaruan terpasang.
Ini menghapus pintasannya, bukan kenangan pada database.

Jika menu Add to Home Screen tidak terlihat, gulir menu Bagikan dan buka **Edit Actions**
untuk menambahkan tindakan itu. Nama menu bisa mengikuti bahasa dan versi iOS.

Manifest dan ikon diperiksa dari hasil build/paket. Pemasangan serta login pada iPhone
fisik perlu diperiksa setelah pembaruan diextract di hosting.

Referensi: [Panduan Home Screen Apple](https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios),
[Dukungan web app WebKit](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/).
