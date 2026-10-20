# Business Process Design

**Project:** Nusalogi Maintenance & Service Management System  
**Version:** 0.1 — Draft  
**Status:** Menunggu validasi NIS

## 1. Work Order Management

### 1.1 Alur Utama

1. Admin menerima permintaan instalasi atau maintenance dari customer.
2. Admin membuat Work Order berdasarkan kebutuhan customer.
3. Koordinator menentukan teknisi dan jadwal pekerjaan.
4. Teknisi memulai pekerjaan.
5. Teknisi mencatat hasil pekerjaan dan mengunggah dokumentasi.
6. Teknisi mengirim laporan untuk diverifikasi.
7. Koordinator menyetujui atau menolak hasil pekerjaan.
8. Jika disetujui, Work Order ditutup.
9. Jika ditolak, pekerjaan dikembalikan kepada teknisi untuk diperbaiki.

### 1.2 Status Work Order

| Status | Keterangan |
|---|---|
| Pending | WO baru dibuat |
| Assigned | Teknisi sudah ditugaskan |
| In Progress | Pekerjaan sedang berlangsung |
| On Hold | Pekerjaan sementara tertunda |
| Pending Review | Menunggu verifikasi koordinator |
| Rework | Pekerjaan perlu diperbaiki |
| Closed | Pekerjaan selesai dan disetujui |
| Cancelled | Pekerjaan dibatalkan |

### 1.3 Business Rules

- Hanya pengguna berwenang yang dapat menugaskan teknisi.
- Teknisi hanya dapat memperbarui pekerjaan yang ditugaskan kepadanya.
- Perubahan status harus tersimpan dalam histori.
- Pekerjaan yang ditunda wajib memiliki alasan.
- Pekerjaan hanya bisa ditutup setelah hasilnya disetujui.
- Penolakan hasil pekerjaan wajib disertai alasan.
- Work Order yang sudah dibatalkan tidak boleh dilanjutkan tanpa proses pembukaan kembali yang disetujui.

### 1.4 Work Order Status Transitions

| Current Status | Next Status | Actor |
|---|---|---|
| Pending | Assigned | Koordinator |
| Assigned | In Progress | Teknisi |
| In Progress | On Hold | Teknisi |
| On Hold | In Progress | Teknisi |
| In Progress | Pending Review | Teknisi |
| Pending Review | Closed | Koordinator |
| Pending Review | Rework | Koordinator |
| Rework | In Progress | Teknisi |

### 1.5 Exception Handling

- Work Order dapat dibatalkan oleh pengguna berwenang.
- Setiap pembatalan wajib memiliki alasan.
- Pekerjaan On Hold harus memiliki alasan penundaan.
- Pekerjaan Rework harus memiliki catatan penolakan.
- Status Closed tidak boleh diubah tanpa prosedur khusus.

## 2. Service Report Management

1. Teknisi mencatat keluhan awal.
2. Teknisi mencatat diagnosis dan tindakan perbaikan.
3. Teknisi mengunggah foto sebelum dan sesudah pekerjaan.
4. Teknisi mengirim laporan.
5. Koordinator memverifikasi hasil.

## 3. Invoice Management

1. Finance memilih Work Order yang sudah diverifikasi.
2. Finance menambahkan item jasa, material, dan biaya lain.
3. Sistem menghitung subtotal, diskon, pajak yang berlaku, serta total.
4. Finance memeriksa dan menerbitkan invoice.
5. Sistem menghasilkan invoice PDF.
6. Finance mencatat pembayaran customer.
7. Sistem menghitung sisa tagihan berdasarkan pembayaran terverifikasi.

### 3.1 Status Dokumen Invoice

- Draft
- Issued
- Void

### 3.2 Status Pembayaran

- Unpaid
- Partially Paid
- Paid
- Overdue

Status pembayaran dihitung berdasarkan total invoice, jumlah pembayaran terverifikasi, dan tanggal jatuh tempo. Overdue menandakan adanya sisa tagihan yang melewati jatuh tempo.

## 4. Important Business Rules

- Status Work Order dan pembayaran invoice merupakan dua proses yang berbeda.
- Satu Work Order dapat memiliki maksimal satu invoice aktif pada MVP.
- Invoice yang sudah diterbitkan tidak dapat diubah langsung tanpa prosedur koreksi.
- Semua rincian biaya disimpan untuk menjaga histori penagihan.
- Pekerjaan garansi atau tanpa biaya memerlukan perlakuan khusus sesuai aturan NIS.
- Pembayaran tidak otomatis mengubah status Work Order.

## 5. Pending Confirmation

- Validasi daftar status pekerjaan bersama Mas Tri.
- Tentukan siapa yang berhak menunda dan membatalkan pekerjaan.
- Konfirmasi proses persetujuan laporan teknisi.
- Konfirmasi aturan pembuatan, pembatalan, dan penerbitan ulang invoice.
- Konfirmasi apakah NIS menggunakan sistem DP atau termin pembayaran.