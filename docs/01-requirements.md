# Software Requirement Specification (SRS)

**Project:** Nusalogi Maintenance & Service Management System  
**Perusahaan:** Nusalogi Integrasi Sistem (NIS)  
**Versi:** 0.1 — Draft Awal  
**Teknologi:** Laravel, Blade, Tailwind CSS, MySQL

## 1. Project Overview

Nusalogi Maintenance & Service Management System merupakan aplikasi berbasis web yang dirancang untuk membantu pengelolaan pekerjaan instalasi, maintenance, dan after-sales service pada bidang CCTV, Fiber Optic, Networking, dan Data Center.

Sistem ini bertujuan untuk mempermudah administrasi Work Order, monitoring pekerjaan teknisi, pencatatan laporan service, dokumentasi perangkat, serta pembuatan invoice.

## 2. Tujuan Sistem

1. Memusatkan data pelanggan, lokasi, dan perangkat.
2. Mempermudah pembuatan serta penugasan Work Order.
3. Memantau progres pekerjaan teknisi.
4. Menyimpan dokumentasi pekerjaan sebelum dan sesudah perbaikan.
5. Mengelola laporan hasil maintenance.
6. Membuat invoice dengan rincian biaya dan output PDF.
7. Memantau pembayaran dan riwayat transaksi.

## 3. User Roles (Scope Internal MVP)

| Role | Nilai Database | Tanggung Jawab |
|---|---|---|
| Admin | `admin` | Mengelola akun, customer, lokasi, perangkat, data master, invoice, dan pembayaran |
| Koordinator | `koordinator` | Mengelola Work Order, menugaskan teknisi, dan memverifikasi hasil pekerjaan |
| Teknisi | `teknisi` | Melihat assignment sendiri, memperbarui progres, dan mengisi laporan maintenance |

Tiga role ini merupakan keputusan scope MVP internal yang masih perlu divalidasi dengan pihak NIS, bukan persetujuan resmi stakeholder. Nilai database dan pemeriksaan authorization menggunakan huruf kecil; kapitalisasi hanya untuk label tampilan. Role `finance` dan `manager` tidak digunakan untuk akun baru.

Dokumen ini menjelaskan target kewenangan, bukan pernyataan bahwa authorization seluruh modul bisnis sudah diimplementasikan. Pembagian jobdesk lima anggota tim pengembang tetap berlaku; jumlah role aplikasi tidak mengubah jumlah anggota atau tanggung jawab pengembang.

## 4. Functional Requirements

| ID | Kebutuhan | Prioritas |
|---|---|---|
| FR-01 | Login dan otorisasi berdasarkan role | High |
| FR-02 | CRUD customer dan lokasi | High |
| FR-03 | Pengelolaan data perangkat | High |
| FR-04 | Pembuatan dan pengelolaan Work Order | High |
| FR-05 | Penugasan teknisi | High |
| FR-06 | Monitoring status pekerjaan | High |
| FR-07 | Laporan hasil maintenance | High |
| FR-08 | Upload foto sebelum dan sesudah | High |
| FR-09 | Invoice dengan rincian biaya jasa dan material | High |
| FR-10 | Perhitungan subtotal, diskon, pajak, dan total | High |
| FR-11 | Generate invoice PDF | High |
| FR-12 | Pencatatan pembayaran invoice | High |
| FR-13 | Dashboard monitoring | Medium |
| FR-14 | Filter dan pencarian riwayat pekerjaan | Medium |

## 5. Business Process

### 5.1 Work Order Management

1. Admin menerima permintaan pekerjaan dari pelanggan.
2. Admin mencatat customer, lokasi, jenis pekerjaan, dan keluhan.
3. Koordinator membuat Work Order dan sistem menghasilkan nomor Work Order.
4. Koordinator menentukan teknisi yang bertanggung jawab.
5. Teknisi menerima penugasan.
6. Teknisi memperbarui status dan mengisi laporan.
7. Koordinator memverifikasi hasil pekerjaan.
8. Work Order dinyatakan selesai dan dapat ditutup.

### 5.2 Invoice Management

1. Admin memilih Work Order yang telah diverifikasi.
2. Admin menambahkan rincian jasa dan material.
3. Sistem menghitung total biaya.
4. Admin memeriksa dan menerbitkan invoice.
5. Sistem menghasilkan dokumen invoice dalam format PDF.
6. Admin mencatat pembayaran.
7. Sistem menampilkan status pelunasan invoice.

## 6. Business Rules (Usulan)

- Nomor Work Order dan invoice harus unik.
- Satu Work Order dapat ditangani lebih dari satu teknisi.
- Setiap pembaruan status pekerjaan dicatat dalam histori.
- Invoice aktif untuk satu Work Order dibatasi satu pada MVP.
- Status pekerjaan dan status pembayaran dikelola secara terpisah.
- Invoice yang sudah diterbitkan tidak dapat diedit langsung tanpa prosedur koreksi.
- Nominal pembayaran tidak boleh melebihi sisa tagihan tanpa penanganan kelebihan pembayaran.
- Nominal dan total invoice harus divalidasi ulang oleh backend.

## 7. Non-Functional Requirements

- Aplikasi dapat diakses melalui browser.
- Sistem memiliki autentikasi dan otorisasi.
- Input pengguna divalidasi di server.
- File dokumentasi diperiksa tipe dan ukurannya.
- Database menggunakan MySQL.
- Riwayat pekerjaan dan pembayaran tersimpan secara konsisten.
- Data penting memiliki mekanisme backup.

## 8. Open Questions

Hal-hal berikut perlu dikonfirmasi dengan Mas Tri:

1. Bagaimana struktur peran pengguna sebenarnya di NIS?
2. Siapa yang membuat dan menyetujui Work Order?
3. Apakah invoice dapat mencakup lebih dari satu Work Order?
4. Apakah terdapat DP atau pembayaran bertahap?
5. Bagaimana aturan pajak dan format invoice resmi NIS?
6. Apakah pekerjaan dalam masa garansi tetap membutuhkan invoice?
7. Siapa yang memverifikasi laporan teknisi?

## 9. Out of Scope (MVP)

Fitur berikut belum menjadi prioritas awal:

- Payment gateway otomatis.
- Integrasi GPS atau live tracking teknisi.
- Aplikasi mobile native.
- Manajemen inventori gudang lengkap.
- Integrasi dengan software akuntansi eksternal.

Bagian ini masih dapat berubah berdasarkan kebutuhan NIS.
