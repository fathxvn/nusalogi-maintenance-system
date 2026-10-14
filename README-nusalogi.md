# Nusalogi Maintenance System

Aplikasi simulasi untuk pengelolaan maintenance dan monitoring pekerjaan teknisi, dikembangkan sebagai project KKP.

## Tech stack

- Laravel 13 (PHP; ikuti persyaratan versi pada `composer.json`)
- Blade + Tailwind CSS 4
- MySQL 8.4 di lingkungan Docker Sail; MySQL lokal yang kompatibel untuk pengembangan tanpa Docker
- Vite 8
- Git dan GitHub untuk kolaborasi

## Sebelum mulai

Minta akses repository **private** kepada pengelola project. Pastikan Git sudah terpasang. Semua developer bekerja dengan database dan file `.env` masing-masing, bukan database bersama secara otomatis.

> **Alamat repository:** Ganti `OWNER/REPO` pada perintah berikut dengan URL repository GitHub tim yang benar.

```bash
git clone https://github.com/OWNER/REPO.git
cd REPO
```

## Pilihan A — Windows tanpa Docker (Laragon / PHP lokal)

### 1. Persyaratan

- PHP dan ekstensi yang sesuai dengan `composer.json` / kebutuhan Laravel (misalnya PDO MySQL, mbstring, openssl, fileinfo, xml)
- Composer
- Node.js dan npm yang kompatibel dengan Vite dari `package.json`
- MySQL berjalan melalui Laragon atau instalasi lokal
- Git, PowerShell / Git Bash / terminal VS Code

> Git Bash dan PowerShell sama-sama bisa dipakai. Perintah penyalinan `.env` di bawah berbeda sesuai terminal.

### 2. Instal dependensi

```bash
composer install
npm ci
```

Jika tidak tersedia `package-lock.json`, gunakan `npm install` sebagai pengganti `npm ci`.

### 3. Siapkan environment

PowerShell:

```powershell
Copy-Item .env.example .env
```

Git Bash:

```bash
cp .env.example .env
```

Ubah `.env` sesuai laptop masing-masing. Contoh:

```dotenv
APP_NAME="Nusalogi Maintenance System"
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nusalogi_maintenance
DB_USERNAME=root
DB_PASSWORD=
VITE_HMR_HOST=localhost
VITE_DEV_PORT=5174
```

Buat database kosong bernama `nusalogi_maintenance` di MySQL lokal (misalnya melalui HeidiSQL atau phpMyAdmin). **Nilai user/password hanyalah contoh**; ikuti konfigurasi MySQL lokal.

> Variabel `VITE_HMR_HOST` dan `VITE_DEV_PORT` digunakan jika `vite.config.js` sudah dikonfigurasi untuk membacanya. Jika belum, ikuti port yang ditampilkan saat `npm run dev`.

### 4. Inisialisasi Laravel

```bash
php artisan key:generate
php artisan migrate
```

Jangan menjalankan `migrate:fresh` pada database berisi data yang ingin dipertahankan. Jika tim menyediakan seeder data simulasi, jalankan `php artisan db:seed` sesuai petunjuk tim.

### 5. Jalankan aplikasi

Terminal pertama:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal kedua:

```bash
npm run dev
```

Buka **http://127.0.0.1:8000**. Perintah `npm run dev` dijalankan terus selama development; gunakan `npm run build` untuk membuat asset build.

## Pilihan B — WSL2 + Docker Desktop + Laravel Sail

### 1. Persyaratan

- Docker Desktop aktif dengan integrasi WSL2 Ubuntu
- Git dan akses repository
- PHP/Composer lokal tidak wajib untuk menjalankan aplikasi setelah dependensi tersedia; untuk *bootstrap* vendor pada clone baru, gunakan Composer di container atau PHP/Composer lokal yang kompatibel

### 2. Clone dan bootstrap dependency

```bash
git clone https://github.com/OWNER/REPO.git
cd REPO
cp .env.example .env
```

Jika belum ada folder `vendor/`, salah satu opsi bootstrap menggunakan Docker (dari direktori project):

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" -w /app composer:2 composer install --ignore-platform-reqs
```

> Langkah bootstrap ini bergantung pada ekstensi/versi PHP yang dibutuhkan project. `--ignore-platform-reqs` hanya untuk menyiapkan `vendor` agar Sail tersedia, **bukan** untuk mengabaikan persyaratan runtime aplikasi.

### 3. Konfigurasi `.env`

Contoh jika port-port tersebut tersedia:

```dotenv
APP_NAME="Nusalogi Maintenance System"
APP_URL=http://localhost:8081
APP_PORT=8081
FORWARD_DB_PORT=3307
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password
```

Gunakan nilai database yang sesuai project. Container MySQL akan membuat database sesuai nilai `DB_DATABASE` pada saat volume pertama kali diinisialisasi; mengubahnya belakangan tidak otomatis membuat database baru.

### 4. Jalankan Laravel

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

Buka **http://localhost:8081** jika `APP_PORT=8081` dan mapping Docker sesuai.

**Catatan khusus WSL2:** pada laptop Fathan, publikasi port Vite melalui Docker Desktop pernah gagal. Solusi development yang sedang digunakan ialah Laravel/MySQL di Docker, Vite dijalankan dari Ubuntu WSL secara lokal. Jangan menyalin IP WSL milik orang lain ke konfigurasi bersama. Jika menggunakan pendekatan tersebut, instal Node.js/npm kompatibel di WSL, jalankan `npm ci` lalu `npm run dev`, dan sesuaikan alamat HMR menurut kondisi jaringan laptop sendiri. Jika port Vite di `compose.yaml` masih dinonaktifkan, jangan mengaktifkannya sembarangan tanpa pengujian.

## Struktur project

```text
app/                Logika backend Laravel (controller, model, dll.)
database/           Migration dan seeder
resources/views/    Halaman Blade
resources/css/      Tailwind CSS
routes/web.php      Rute web
tests/              Pengujian
docs/               Dokumen requirement, desain, testing, dan laporan (jika dibuat)
```

## Workflow GitHub tim

Jangan mengerjakan fitur langsung di branch `main`.

```bash
git switch main
git pull origin main
git switch -c feature/nama-fitur
# Kerjakan perubahan, tes dahulu
git add <file-yang-diubah>
git commit -m "feat: deskripsi singkat fitur"
git push -u origin feature/nama-fitur
```

Buka GitHub lalu buat **Pull Request** ke `main`. Minta review dari anggota lain sebelum merge. Saat mendapat task baru, buat branch baru dari `main` terbaru.

## Aturan keamanan dan dokumentasi

- Jangan commit `.env`, password, token, database dump berisi data pribadi, `vendor/`, atau `node_modules/`.
- Commit `composer.lock` dan `package-lock.json` agar dependency konsisten.
- Setiap programmer mencatat fitur yang dikerjakan, cara pengujian, dan bukti hasil (misalnya screenshot) pada issue/PR.
- Arif mengoordinasikan dokumentasi analisis dan laporan, Ibnu menjelaskan serta memvalidasi alur operasional, dan Daus mencatat hasil pengujian sesuai pembagian tugas tim.
- Gunakan data simulasi, bukan data pelanggan nyata, kecuali penggunaan data tersebut diizinkan secara eksplisit.

## Troubleshooting singkat

- **Laravel HTTP 500:** cek `storage/logs/laravel.log`, konfigurasi `.env`, dan status migration.
- **`sessions` table doesn't exist:** pastikan migration database sudah dijalankan.
- **CSS tidak muncul:** jalankan `npm run dev` (development) atau `npm run build`.
- **Port sudah dipakai:** sesuaikan port lokal yang dipakai tanpa mengubah port anggota lain.
- **MySQL tidak terkoneksi:** cek service aktif serta `DB_HOST`, `DB_PORT`, user/password, dan nama database.

## Status proyek

Project ini masih tahap setup awal. Fitur maintenance dan monitoring akan ditentukan berdasarkan analisis kebutuhan yang disetujui tim.
