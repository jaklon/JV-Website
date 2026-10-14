# Hasil verifikasi chatbot JV Story

Tanggal: 8 Oktober 2026. Project tujuan: `jv-story`; billing dinyatakan aktif oleh pengguna. Screenshot billing menunjukkan Rp1 sebelum deployment chatbot. Nilai itu adalah baseline akun, bukan biaya hasil deployment ini.

## Hasil akhir lokal

**Seluruh tes lokal lulus.** Evaluasi utama: **20/20**. Evaluasi tambahan: **33/33**. Frontend: **24 tes**. Helper PHP: **40 pemeriksaan**. HTTP API: **49 pemeriksaan**. Gateway Go: **12 tes**. Migrasi dan konkurensi MariaDB asli juga lulus dalam instance uji terpisah. Hasil rangkaian: `local-test-results.json` dalam ZIP, atau `release/chat-test-results.json` di workspace.

Cloud Run privat sudah dideploy dan evaluasi nyata GCP lulus **20/20 utama + 33/33 tambahan**. Tidak ada pergantian model, Gemini, GPU, atau fallback berbayar. Aktivasi cPanel masih memerlukan key invoker di direktori privat; status ini tidak membuktikan koneksi produksi cPanel sudah berhasil.

## Perbaikan dan batas kemampuan

Pencarian PHP kini mengenali bulan bernama, tahun, dan kata dengan akhiran “nya”; pertanyaan lanjutan membawa topik sebelumnya. Kata bulan/tahun menyaring kolom tanggal, tanpa mewajibkan kata itu muncul dalam judul cerita. Draft milik kedua akun tetap dikecualikan.

Gateway memilih jenis sumber dan topik sebelum memanggil Qwen. Tanggal, nama, judul album, dan kutipan cerita diambil dari data yang tersedia. Qwen diberi tugas ekstraksi yang singkat. Jawaban harus mencakup kutipan terpilih secara lengkap; jawaban yang berubah, tidak lengkap, atau tidak didukung diganti dengan kutipan sumber. ID sumber dibuat server. Riwayat assistant tidak dapat mengubah fakta sumber. Informasi yang tidak ditemukan ditolak dan chatbot meminta detail.

Percakapan bercerita berbentuk **dialog singkat terarah**. Qwen memilih satu pertanyaan yang sesuai konteks dari pilihan bahasa Indonesia yang diperiksa, dengan pembuka yang mengakui cerita pengguna. Pilihan menyesuaikan kelelahan, kabar baik, kesulitan, komunikasi, serta riwayat. Fitur tidak memberi diagnosis atau keputusan hubungan. Keluaran bebas Qwen yang sebelumnya janggal tidak ditampilkan.

Skor 20/20 adalah skor **pipeline aplikasi**, bukan skor Qwen sebagai generator bebas. Dari 20 kasus utama, 19 benar-benar memanggil model; satu informasi yang tidak tersedia ditolak server. Sembilan kasus menggunakan kutipan terverifikasi sebagai fallback setelah keluaran model tidak lolos pemeriksaan. Detail ini tersedia pada `model_called`, `used_fallback`, `answer_mode`, dan `generation_tokens` di laporan.

## Evaluasi nyata dan reproducibility

Bobot Qwen3-0.6B resmi direkuantisasi dari Q8 menjadi Q4_K_M memakai llama.cpp `b11491`, commit `9b4ed0ca572be213f4440a490f341f810f7aa2cf`. Ukuran GGUF 396.704.416 byte (378,33 MiB); checksum dan provenance tersedia di `model-manifest.json` atau `model/manifest.json` di workspace.

Runtime lokal Windows CPU satu thread, thinking dimatikan, konteks total maksimum 2.048 token, keluaran maksimum 256 token. Batas juga diterapkan pada kutipan fallback, bukan hanya pada token model. Data pengujian seluruhnya sintetis; tidak mengambil kenangan dari hosting.

- `evaluation-before-grounding.json`: hasil lama 3/20, dengan sumber exact. Dipertahankan untuk menunjukkan kegagalan sebelumnya.
- `evaluation-baseline.json`: baseline awal 9/20, dengan pemeriksaan sumber yang lebih longgar. Skor ini tidak langsung sebanding dengan skor akhir.
- `evaluation.json`: 20 pertanyaan asli, persyaratan fakta dan himpunan sumber tetap; konteks diperoleh dari SQL PHP nyata pada SQLite sintetis. Seluruhnya lulus.
- `evaluation-extended.json`: data pasangan/tempat/tanggal lain, perubahan warna pada data, follow-up, riwayat assistant salah, permintaan detail yang tidak tersedia, draft, dan sepuluh dialog Indonesia. Seluruh 33 kasus lulus.
- `manual-review.json`: pemeriksaan bahasa dan relevansi dialog yang benar-benar dihasilkan pada evaluasi akhir.

Perintah dari root workspace: `python scripts/test-chat-suite.py --php C:\xampp\php\php.exe`. Semua tes menggunakan data lokal terpisah. Tidak menghubungi hosting atau membuat resource GCP.

Cold start lokal sekitar 2–3 detik; respons fakta pendek sekitar 1–2 detik. Lihat durasi per jawaban untuk detail. Angka ini tidak membuktikan performa Cloud Run.

## Verifikasi GCP aktual

Image Linux lokal dan registry berukuran sekitar **406 MiB**, di bawah 480 MiB. Image yang diuji dideploy menggunakan digest `sha256:a7ab5cd2e63a66504a5d820d6a7cfb4a534ce78f96590cdc4a2e5665c764cd70`. Revisi `jv-story-chat-00001-mwg`: us-central1, 1 vCPU, RAM 2 GiB, concurrency 1, min 0/max 1, CPU request-based tanpa startup boost. Akun runtime tidak mempunyai role proyek. Invoker hanya mempunyai `roles/run.invoker` pada layanan ini.

POST anonim ditolak 403; POST dengan ID token invoker dan audience URL yang tepat menghasilkan 200. Laporan `evaluation-gcp.json` dan `evaluation-extended-gcp.json` memakai SQL PHP dan data sintetis. Monitoring GCP mencatat RAM sekitar 45% dari 2 GiB; startup model 12.035 ms dan generasi terlama 4.674 ms. Cold start HTTP end-to-end belum diukur. iPhone fisik belum diuji.

Hosting berada di Indonesia; transfer GCP ke hosting mempunyai tarif US$0,19/GiB. Pengguna mengizinkan biaya tambahan maksimal **Rp1.000/bulan**, menggantikan target Rp0 mutlak. Pengaman aktual: spend cap Cloud Run gross **Rp800/bulan** dan budget alert proyek aktual **Rp1.000/bulan**. Cap menghitung sebelum kredit gratis dan dapat menghentikan layanan lebih awal; penegakan/alert mempunyai keterlambatan dan bukan jaminan batas invoice keras. Kuota gratis dibagi akun billing; pemakaian mendatang tetap perlu diperiksa.

`deployment-results.json` mencatat bukti runtime, IAM, biaya, dan hash sumber. Contoh readiness tetap false untuk pemasangan baru; jangan memakai laporan deployment ini bagi image atau layanan berbeda.
