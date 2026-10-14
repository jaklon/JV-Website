# Update chatbot JV Story — Qwen di GCP

Paket ini menambahkan antarmuka chat, riwayat bersama, API PHP, dan migrasi. Cloud Run privat telah dideploy dan lulus pengujian. Koneksi produksi cPanel masih menunggu key invoker di direktori privat. Tidak ada Gemini atau layanan AI berbayar cadangan.

**Hasil uji 8 Oktober 2026:** seluruh suite lokal lulus, termasuk 40 pemeriksaan helper PHP. Evaluasi nyata Cloud Run lulus **20/20** pertanyaan dan **33/33** skenario tambahan. Fakta memakai kutipan yang divalidasi; dialog Indonesia dibuat pendek dan terarah. Image sekitar 406 MiB, RAM teramati sekitar 45% dari 2 GiB, dan IAM telah diuji. Paket sumber memuat laporan `VALIDATION.md`, hasil uji sintetis GCP, serta `deployment-results.json`. Migrasi telah dijalankan di hosting; konfigurasi database dan portfolio dipertahankan.

## Extract pembaruan cPanel

1. Backup database melalui phpMyAdmin dan backup file hosting.
2. Upload ZIP `jascon-jv-story-chat-update-*.zip` ke **`/home/cirj8428`**, lalu Extract di direktori tersebut. ZIP sudah memuat struktur `public_html/jascon.my.id/jv-story` dan `jv_story_private`. Jangan extract di document root karena akan membuat direktori bertingkat.
3. ZIP tidak mengganti `config.local.php`, foto/video, portfolio, konfigurasi chat aktif, atau kredensial GCP.
4. Di phpMyAdmin pilih **`cirj8428_JVDB`**, kemudian Import file **`jv_story_private/migrations/001-chat.sql`**. Alternatif melalui Terminal cPanel: `php /home/cirj8428/jv_story_private/bin/migrate-chat.php`. Migrasi aman dijalankan ulang, tanpa menghapus tabel lama.
5. Buka JV Story dan login. Tombol **Teman cerita** tersedia; sebelum GCP siap, muncul status “Chat sedang disiapkan”. Halaman kenangan tetap berfungsi.

## Menyiapkan layanan GCP

Gunakan paket sumber `jv-story-chat-gcp-source-*.zip` dan baca `README.md` di dalamnya. Build image dilakukan di komputer lokal dengan Docker, bukan memakai Cloud Build. Model GGUF tidak dimasukkan ke ZIP cPanel; bobot hanya berada dalam container GCP.

Cloud Run memakai IAM dan akun layanan invoker khusus. Setelah deployment:

1. Copy **`chat.config.example.php`** menjadi **`chat.config.php`** di `jv_story_private`.
2. Isi `service_url` dengan URL Cloud Run persis, tanpa slash akhir atau path.
3. Simpan key akun layanan sebagai **`/home/cirj8428/jv_story_private/chat-invoker.json`**, permission 600. Jangan upload ke `public_html`, commit, atau kirim key di chat.
4. Tempatkan hasil verifikasi sebagai `chat.readiness.json`; contoh dan pemeriksaan tersedia di paket GCP. URL/model laporan harus sama dengan layanan yang dipakai. Semua pemeriksaan kualitas, biaya, runtime, dan IAM wajib lulus. Review percakapan bahasa Indonesia secara manual juga wajib dilakukan.
5. Baru ubah `enabled` menjadi `true`. Jika ada pemeriksaan gagal, biarkan `false`.

Backend memerlukan koneksi HTTPS keluar ke Google dan Cloud Run; timeout hosting harus mendukung sampai 110 detik. Aktifkan PHP cURL, mbstring, PDO MySQL, OpenSSL; dependensi autentikasi Google sudah disertakan pada pemasangan awal. Key tidak membutuhkan hak Editor/Owner: cukup Cloud Run Invoker pada layanan chatbot.

## Penggunaan dan privasi

- Hanya kedua Gmail yang sudah diizinkan dapat membaca chat. Semua percakapan dapat dibaca pasangan; pengirim tampil pada setiap pesan.
- Draft surat milik siapa pun tidak masuk pencarian. Foto/video tidak dikirim ke model.
- Jawaban AI bisa keliru. Tautan sumber hanya menunjuk konten JV Story yang dipilih backend.
- Batas bersama: 20 permintaan/hari dan 600/bulan, mengikuti waktu Jakarta. Permintaan yang sudah memulai proses GCP tetap dihitung walaupun gagal.
- Model memproses maksimal 2.048 token konteks total dan 256 token keluaran. Runtime membatalkan inferensi setelah 45 detik; server awal yang dingin dapat menambah waktu tunggu.
- Tidak ada percobaan ulang otomatis. Pesan gagal tetap terlihat dan riwayat lama tetap bisa dibaca.
- Hanya pembuat dapat menghapus percakapan. Penghapusan berlaku untuk kedua akun dan tidak mengembalikan kuota.
- Riwayat disimpan di MariaDB cPanel. Model tidak mempunyai akses langsung ke database. Log cloud tidak memuat pertanyaan/jawaban.

## Biaya dan mematikan fitur

Pengguna telah mengizinkan biaya tambahan maksimal **Rp1.000/bulan**, sebab transfer menuju hosting Indonesia dikenai tarif. Spend cap Cloud Run gross **Rp800/bulan** dan budget alert aktual proyek **Rp1.000/bulan** telah dipasang. Cap menghitung sebelum kredit gratis dan bisa menghentikan chat lebih awal; keterlambatan penegakan berarti ini bukan jaminan batas invoice keras. Kuota gratis berbagi dengan proyek lain pada akun billing. Periksa Artifact Registry, Logging, transfer, dan pemakaian saat pembaruan image. Jangan mengandalkan kredit trial untuk biaya bulanan.

Jika ada biaya, pemakaian berlebih, atau kualitas kurang baik: ubah `enabled` ke `false`, dan hentikan layanan di GCP bila perlu. Mengubah PHP saja tidak menghapus resource GCP. Tidak ada perpindahan otomatis ke layanan berbayar.
