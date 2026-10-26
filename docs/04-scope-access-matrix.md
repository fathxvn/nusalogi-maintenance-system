# Scope & Access Control Matrix

**Project:** Nusalogi Maintenance & Service Management System  
**Dokumen:** A01 — Scope & Access Matrix  
**Versi:** 0.1 — Draft untuk Validasi NIS  
**Referensi:** SRS v0.1, Business Process Design v0.1  
**Tech Stack:** Laravel, Blade, Tailwind CSS, MySQL

## 1. Tujuan Dokumen

Menentukan batas pengembangan MVP dan rancangan hak akses pengguna agar proses implementasi sistem berjalan konsisten.

Dokumen ini menjadi referensi awal untuk task A02 (validasi kebutuhan NIS), A03 (audit implementasi), dan B02 (Role & Authorization).

## 2. Ruang Lingkup MVP

| Kode | Modul | Fungsi Utama |
|---|---|---|
| M01 | Authentication & User Management | Login, logout, pengelolaan akun internal, role |
| M02 | Customer & Site Management | CRUD pelanggan dan lokasi pekerjaan |
| M03 | Asset Management | Pengelolaan perangkat pelanggan |
| M04 | Work Order Management | Pembuatan WO, penugasan, status dan histori |
| M05 | Service Report Management | Laporan pekerjaan, foto, review dan revisi |
| M06 | Invoice Management | Rincian tagihan, perhitungan, penerbitan dan PDF |
| M07 | Payment Management | Pencatatan pembayaran, saldo dan status pelunasan |
| M08 | Dashboard & Reporting | Monitoring pekerjaan, invoice dan riwayat |

## 3. User Roles

- **Admin (`admin`):** Mengelola akun internal, customer, data master, invoice, dan pembayaran.
- **Koordinator (`koordinator`):** Mengelola Work Order, mengatur penugasan teknisi, dan memverifikasi hasil pekerjaan.
- **Teknisi (`teknisi`):** Melihat assignment sendiri, melaksanakan pekerjaan yang ditugaskan, memperbarui progres, dan membuat laporan maintenance.

Daftar ini adalah keputusan scope internal MVP yang masih perlu divalidasi stakeholder. Nilai role disimpan dan diperiksa dalam huruf kecil; kapitalisasi hanya untuk label tampilan. Role `finance` dan `manager` tidak tersedia untuk akun baru. Data role lama tidak otomatis dihapus, dinormalisasi, atau dipromosikan menjadi Admin; perubahan data memerlukan persetujuan terpisah.

Pembagian jobdesk lima anggota tim pengembang tetap berlaku. Tiga role pengguna aplikasi tidak mengubah jumlah anggota maupun tanggung jawab pengembang.

Customer tidak memiliki akun login pada rancangan MVP ini. Pembuatan portal customer membutuhkan persetujuan scope tersendiri.

## 4. Matriks Hak Akses

Keterangan:

- **Kelola:** dapat membuat, melihat dan memperbarui data sesuai aturan.
- **Lihat:** akses baca saja.
- **Tugas sendiri:** hanya pekerjaan yang ditugaskan.
- **—:** tidak diberikan akses.
- **Usulan:** kewenangan masih perlu konfirmasi NIS.

| Fitur | Admin | Koordinator | Teknisi |
|---|---|---|---|
| Login / Logout | Ya | Ya | Ya |
| Pengelolaan akun internal | Kelola | — | — |
| Customer & Site | Kelola | Lihat | Lihat terkait |
| Asset | Kelola | Lihat | Lihat terkait |
| Membuat Work Order | — | Ya | — |
| Menugaskan teknisi | — | Ya | — |
| Melihat Work Order | Ya | Ya | Tugas sendiri |
| Memperbarui progres WO | — | Sesuai kewenangan | Tugas sendiri |
| Verifikasi laporan teknisi | — | Ya | — |
| Membuat laporan teknisi | — | — | Tugas sendiri |
| Upload foto pekerjaan | — | — | Tugas sendiri |
| Membuat dan menerbitkan invoice | Ya | — | — |
| Melihat invoice | Ya | Usulan | — |
| Mencatat pembayaran terverifikasi | Ya | — | — |
| Dashboard monitoring | Sesuai akses | Operasional | Tugas sendiri |

Matriks ini merupakan target kewenangan scope internal MVP, bukan aturan resmi yang sudah disetujui NIS dan bukan bukti bahwa authorization seluruh modul bisnis telah diimplementasikan.

## 5. Business Rules Utama

### Work Order

- Nomor Work Order harus unik.
- Satu Work Order dapat ditugaskan kepada beberapa teknisi.
- Setiap perubahan status tercatat dalam histori.
- Teknisi hanya boleh memperbarui Work Order yang ditugaskan kepadanya.
- Work Order hanya boleh ditutup setelah hasil pekerjaan disetujui.
- Pembatalan dan revisi harus memiliki alasan sesuai aturan yang disepakati.

### Service Report

- Laporan terhubung ke Work Order.
- Teknisi mencatat diagnosis, tindakan dan hasil.
- Foto pekerjaan dapat dilampirkan.
- Koordinator dapat menyetujui atau menolak laporan.
- Laporan yang ditolak harus mempunyai alasan revisi.

### Invoice & Payment

- Satu Work Order maksimal memiliki satu invoice aktif pada MVP.
- Invoice harus mempunyai rincian biaya.
- Perhitungan biaya divalidasi di backend.
- Invoice yang diterbitkan tidak boleh diedit langsung tanpa prosedur koreksi.
- Status penerbitan dan status pembayaran merupakan dua hal terpisah.
- Pembayaran tidak boleh melebihi sisa tagihan.
- Pembayaran yang dicatat harus berdasarkan konfirmasi penerimaan dana.
- Pembayaran tidak otomatis mengubah status Work Order.

## 6. Status Proses

### Work Order

Pending, Assigned, In Progress, On Hold, Pending Review, Rework, Closed, Cancelled.

### Invoice

Draft, Issued, Void.

### Payment

Unpaid, Partially Paid, Paid, Overdue.

Overdue merupakan kondisi berdasarkan jatuh tempo dan sisa tagihan, bukan otomatis menggantikan status pembayaran parsial.

## 7. Out of Scope MVP

- Payment gateway otomatis.
- Portal login customer.
- GPS atau live tracking teknisi.
- Aplikasi mobile native.
- Inventori gudang lengkap.
- Integrasi software akuntansi eksternal.

SLA penuh, manajemen kontrak dan proyek multi-Work Order belum ditetapkan sebagai scope final, karena mekanisme operasionalnya masih membutuhkan konfirmasi.

## 8. Keputusan yang Menunggu Validasi NIS

1. Struktur role dan batas kewenangan final.
2. Hak membuat, menugaskan dan membatalkan Work Order.
3. Mekanisme deadline proyek dan SLA maintenance.
4. Hubungan antara kontrak, proyek dan Work Order.
5. Prosedur review dan penutupan pekerjaan.
6. Apakah satu invoice bisa menagih beberapa Work Order.
7. Pembayaran DP, termin dan prosedur verifikasi transfer bank.
8. Pajak, rekening tujuan, format PDF dan pengiriman invoice.
9. Mekanisme pembatalan dan koreksi invoice.

## 9. Kriteria Penyelesaian A01

- Delapan modul MVP telah diidentifikasi.
- Aktor dan batas akses tiap fitur telah dirancang.
- Aturan bisnis utama telah dicatat.
- Fitur di luar scope telah dipisahkan.
- Pertanyaan terbuka telah disiapkan untuk konfirmasi Ibnu kepada Mas Tri.

**Status A01:** Draft siap direview dan diserahkan untuk task A02. Belum dinyatakan final/disetujui NIS.
