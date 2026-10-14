# JV Story Chat — deployment Qwen CPU di Cloud Run

Frontend/PHP tetap di cPanel; layanan ini hanya menerima konteks teks yang dipilih PHP. Qwen3-0.6B Q4_K_M, llama.cpp, mode tanpa thinking. Model tidak mempunyai tool, browser, atau akses database. Cloud Run wajib privat melalui IAM.

**Status 8 Oktober 2026: layanan Cloud Run privat sudah dideploy.** Seluruh tes lokal lulus; evaluasi GCP nyata lulus **20/20 utama + 33/33 tambahan**. Image sekitar 406 MiB, RAM teramati sekitar 45% dari 2 GiB, IAM anonim ditolak. Pengguna mengizinkan biaya tambahan maksimal Rp1.000/bulan karena transfer menuju hosting Indonesia; spend cap Cloud Run gross Rp800 dan budget alert aktual proyek Rp1.000 sudah terpasang. Cap/alert mempunyai keterlambatan dan bukan jaminan invoice keras. Koneksi produksi cPanel menunggu key invoker di direktori privat. Baca `VALIDATION.md`, laporan evaluasi GCP dan `deployment-results.json`.

## 1. Siapkan model dan jalankan pemeriksaan lokal

Windows x64: Python 3, Go 1.24+, Docker Desktop Linux containers, dan Google Cloud CLI (`gcloud`).

```powershell
python prepare-model.py
go test ./... -count=1 -timeout=30s
go build -o bin/gateway.exe .
python run-local-evaluation.py --php C:\xampp\php\php.exe --extended
```

Script menggunakan rilis llama.cpp `b11491` dan revision Qwen `23749fefcc72300e3a2ad315e1317431b06b590a`, memverifikasi checksum saat tersedia, lalu requantisasi bobot Q8 resmi menjadi Q4_K_M. `model/manifest.json` mencatat checksum dan commit. Model hasil lokal sekitar 379 MiB. Requantisasi dapat menurunkan kualitas; hasil model ini harus diuji, bukan dianggap setara bobot asli.

`evaluation.json` memuat 20 pertanyaan sintetis, jawaban/sumber, token/durasi, skor, serta skenario curhat, follow-up, informasi tidak tersedia, dan permintaan draft. `evaluation-extended.json` menambahkan data baru, fakta yang diubah, riwayat assistant yang salah, privasi draft, dan sepuluh percakapan. Opsi `--php` menjalankan pencarian SQL asli di SQLite sintetis; tidak membaca konfigurasi atau database hosting. Paket sumber menyertakan fixture PHP tanpa kredensial atau dependensi Google. Minimal **18/20** lulus dan skenario tambahan harus direview manual; perbaikan ini mensyaratkan semua kasus lokal lulus.

Dari root workspace JV Story, `python scripts/test-chat-suite.py --php C:\xampp\php\php.exe` menjalankan frontend, helper PHP, HTTP API, MariaDB sementara, Go, Qwen + SQL, dan build hosting. Laporan lokal tidak membuktikan latensi/biaya GCP. Jika Windows menolak executable Go di direktori sementara, kompilasikan tes di workspace dengan `go test -c -o bin/chat-tests.exe`, kemudian jalankan `& .\bin\chat-tests.exe '-test.count=1' '-test.timeout=30s'`. Tidak perlu mengubah proteksi Windows.

Skor 20/20 adalah skor **pipeline aplikasi**. Kolom `model_called`, `used_fallback`, dan `answer_mode` mencatat keputusan setiap jawaban. Jawaban yang gagal diverifikasi diganti dengan kutipan data terpilih; informasi yang tidak ada ditolak. Pertanyaan percakapan dibatasi pilihan bahasa yang wajar, sehingga fitur ini berfungsi sebagai pendamping bercerita yang ringan dan terarah.

Jika kualitas kurang dari 18/20, **hentikan peluncuran**. Jangan otomatis menaikkan model, memori, GPU, atau memakai Gemini. Laporkan hasilnya terlebih dahulu.

## 2. Build image lokal dan ukur penyimpanan

Docker harus aktif. Jalankan dari direktori ini:

```powershell
$taskModel = Get-Content model/manifest.json | ConvertFrom-Json
docker build --platform linux/amd64 --build-arg "LLAMA_COMMIT=$($taskModel.llama_commit)" -t jv-story-chat:tested .
docker image inspect jv-story-chat:tested --format '{{.Size}}'
```

Build arg wajib berupa commit terverifikasi. Model berada dalam layer sendiri yang tidak berubah saat gateway diperbarui. Tidak mengunduh bobot saat cold start. Model/tokenizer tidak disimpan lagi di Cloud Storage.

Gunakan budget konservatif image lokal **maksimal 480 MiB** sebelum push; cek juga **total storage yang benar-benar dipakai akun Artifact Registry** setelah push. Metadata/layer/image lama ikut dihitung. Hentikan jika tidak ada ruang. Backup image lama ke lokal sebelum menghapusnya, agar pemulihan tetap mungkin tanpa menyimpan dua image model di registry. Artifact Registry gratis 0,5 GiB/account; biaya scan otomatis juga perlu diperiksa. Jangan aktifkan Container Scanning API untuk paket ini jika targetnya Rp0.

## 3. Deployment privat

Login memakai akun GCP milikmu. Proyek pengguna: **`jv-story`**, billing dinyatakan aktif. Verifikasi project ini benar sebelum menjalankan perintah. Jangan memakai service account default dengan hak Editor.

```powershell
gcloud auth login
$taskProject = 'jv-story'
$taskRegion = 'us-central1'
$taskRepo = 'jv-chat'
$taskService = 'jv-story-chat'
$taskRuntime = "jv-chat-runtime@$taskProject.iam.gserviceaccount.com"
$taskInvoker = "jv-chat-invoker@$taskProject.iam.gserviceaccount.com"
gcloud services enable run.googleapis.com artifactregistry.googleapis.com iam.googleapis.com --project $taskProject
gcloud iam service-accounts create jv-chat-runtime --project $taskProject
gcloud iam service-accounts create jv-chat-invoker --project $taskProject
gcloud artifacts repositories create $taskRepo --repository-format docker --location $taskRegion --project $taskProject
gcloud auth configure-docker us-central1-docker.pkg.dev
$taskImage = "us-central1-docker.pkg.dev/$taskProject/$taskRepo/chat:tested"
docker tag jv-story-chat:tested $taskImage
docker push $taskImage
gcloud run deploy $taskService --image $taskImage --region $taskRegion --project $taskProject --service-account $taskRuntime --no-allow-unauthenticated --cpu 1 --memory 2Gi --concurrency 1 --min 0 --max 1 --min-instances 0 --max-instances 1 --cpu-throttling --no-cpu-boost --timeout 60s
gcloud run services add-iam-policy-binding $taskService --member "serviceAccount:$taskInvoker" --role roles/run.invoker --region $taskRegion --project $taskProject
gcloud run services describe $taskService --format 'value(status.url)' --region $taskRegion --project $taskProject
```

Runtime account memerlukan **nol** izin aplikasi cloud tambahan. Endpoint model loopback tidak diekspos. Gateway hanya menyajikan `/chat` dan `/healthz`; tidak ada UI llama, tool, slot state, atau endpoint manipulasi model yang dapat diakses client.

Untuk cPanel tanpa penyedia identitas workload, buat key **hanya akun invoker**:

```powershell
gcloud iam service-accounts keys create chat-invoker.json --iam-account $taskInvoker --project $taskProject
```

Simpan file langsung di direktori privat hosting, permission 600; tidak masuk ZIP. PHP menggunakan library Google untuk menukar JWT menjadi ID token dengan audience URL Cloud Run. Tidak perlu hak Token Creator tambahan. Rotasi/revoke key jika terpapar. Jika kebijakan organisasi melarang key, gunakan Workload Identity Federation; jangan membuka layanan ke `allUsers` sebagai workaround.

## 4. Verifikasi GCP dan biaya sebelum enabled=true

1. Tanpa token, `/healthz` dan `/chat` harus ditolak IAM. Uji akun invoker dapat memanggilnya; akun Google login web tidak dipakai sebagai kredensial server.
2. Jalankan evaluasi yang sama pada URL Cloud Run dengan token dari identitas invoker (`evaluate.py --url https://...run.app --token-file PATH`). Simpan token sementara di direktori privat lokal lalu hapus. ID token bukan access token dan audience harus URL layanan.
3. Ukur memory dan startup/latensi Cloud Run lewat monitoring: RAM tidak melebihi 2 GiB, cold startup <=60 detik, gateway <=45 detik, total permintaan <=90 detik. Uji hosting dapat menunggu hasil; kegagalan harus kembali ke halaman tanpa retry otomatis.
4. Untuk 600 permintaan, alokasikan konservatif sampai 120 detik aktif/request: 72.000 vCPU-detik dan 144.000 GiB-detik, ditambah startup/probe/redeploy. Bandingkan dengan **sisa kuota akun**, bukan hanya batas proyek. Periksa egress ke negara hosting; jatah transfer gratis tidak boleh diasumsikan berlaku untuk setiap tujuan.
5. Periksa total registry <=480 MiB, tidak ada biaya scanning, GPU, Cloud Build, VPC connector, load balancer, atau Cloud SQL. Evaluasi dan redeploy juga memakai resource.
6. Untuk akun billing IDR yang dipakai deployment ini, **Spend cap enforcement** Cloud Run telah dipasang sebesar **Rp800/bulan**, dengan alert biaya aktual proyek **Rp1.000/bulan**, sesuai izin tambahan biaya pengguna. Cap memakai biaya kotor sebelum diskon/free tier; enforcement terlambat dan tidak menghentikan penyimpanan tetap. Cap bukan jaminan batas invoice keras. Jangan lift cap otomatis.
7. Cloud Logging hanya menerima status/durasi/token dari Go dan metadata request Cloud Run. Konten prompt/jawaban tidak dicetak; stdout/stderr llama tidak dihubungkan ke Logging.

Setelah seluruh pemeriksaan lulus, copy `readiness.example.json`, isi URL layanan, tanggal pemeriksaan dan rujukan bukti, lalu set semua boolean lulus yang **benar-benar diverifikasi**. `quality_pass` hanya true jika skor >=18/20 dan review skenario tambahan lulus. Simpan sebagai `chat.readiness.json` privat di hosting. Laporan contoh sengaja berisi semua nilai false; tidak boleh diaktifkan hanya dengan mengganti boolean tanpa pengujian.

Jika lokasi egress/akun billing tidak memenuhi anggaran yang diizinkan pengguna, atau kualitas/latensi gagal, **enabled tetap false**. Izin Rp1.000/bulan khusus deployment ini harus tetap dicatat; pemasangan baru tidak otomatis memperoleh izin tersebut. Riwayat dan situs utama tetap tersedia. Tidak ada fallback berbayar.

## Referensi resmi

- Qwen: https://huggingface.co/Qwen/Qwen3-0.6B
- Runtime: https://github.com/ggml-org/llama.cpp
- Cloud Run: https://cloud.google.com/run/pricing
- IAM: https://docs.cloud.google.com/run/docs/authenticating/service-to-service
- Artifact Registry: https://cloud.google.com/artifact-registry/pricing
- Spend cap: https://docs.cloud.google.com/billing/docs/how-to/budgets-spend-caps
