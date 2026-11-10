# Nusalogi Maintenance System v0.2
## Architecture Decision Register · Phase 0C

**Architecture Baseline M1 diratifikasi secara internal oleh Muhammad Fathan pada 10 November 2026 (WIB).**

### Catatan keputusan internal · 10 November 2026

Fathan menyetujui amendment ADR-02, 03, 04 dan 08 untuk M1, serta keputusan M1 pada ADR-01, 05, 10, 11 dan 12. Ratifikasi ini menjadi dasar memulai **Phase 0D · Domain Model & Database Design M1**. Rincian desain 0D masuk technical feasibility review Codex sebelum implementasi. Kebijakan operasional dan otoritas pihak perusahaan tetap **PROPOSED — NEEDS VALIDATION**.

Ruang yang diratifikasi mencakup satu primary origin per WO baru (ticket atau manual), provenance legacy_unknown bila asal historis belum jelas, 0..N WO per ticket, serta visit dan booking terpisah dari membership WO-03. Report memiliki revisi dan approval terlacak. BAST berversi menerima scope dari beberapa WO yang kompatibel. Closure normal membutuhkan acceptance dan jalur exception tetap nonaktif sampai kewenangan disahkan. Material Usage terpisah dari inventory. Perubahan database wajib additive dan backward-compatible.

Kolom rekomendasi menyimpan hasil workshop asal. Kolom keputusan merekam ratifikasi Fathan khusus M1. ADR-06/07 dan ERD keuangan M2 menunggu keputusan bisnis. ADR-09 tetap berstatus rekomendasi DEFER di luar ratifikasi ini.

| ADR | Pokok keputusan | Rekomendasi | Keputusan internal Fathan |
|---|---|---|---|
| ADR-01 | Modular monolith | **ACCEPTED AS INTERNAL BASELINE** | Diratifikasi · M1 · 10 Nov 2026 |
| ADR-02 | Ticket dan origin WO | **AMEND** | Amendment diratifikasi · M1 · 10 Nov 2026 |
| ADR-03 | Assignment dan booking per visit | **AMEND** | Amendment diratifikasi · M1 · 10 Nov 2026 |
| ADR-04 | Lifecycle dan syarat penutupan WO | **AMEND** | Amendment diratifikasi · M1 · 10 Nov 2026 |
| ADR-05 | BAST berversi dan scope acceptance | **ACCEPTED AS INTERNAL BASELINE** | Diratifikasi · M1 · 10 Nov 2026 |
| ADR-06 | Sumber tagihan dan invoice | **AMEND** | Terbuka · M2 |
| ADR-07 | Verifikasi pembayaran dan kardinalitas allocation | **AMEND** | Terbuka · M2 |
| ADR-08 | Authorization dan wewenang manusia | **AMEND** | Amendment diratifikasi · M1 · 10 Nov 2026 |
| ADR-09 | Automasi native dan pemulihan | **DEFER** | Di luar ratifikasi · rekomendasi DEFER |
| ADR-10 | Waktu operasional dan tanggal bisnis | **ACCEPTED AS INTERNAL BASELINE** | Diratifikasi · M1 · 10 Nov 2026 |
| ADR-11 | Asset pelanggan dan material usage | **ACCEPTED AS INTERNAL BASELINE** | Diratifikasi · M1 · 10 Nov 2026 |
| ADR-12 | Perubahan additive dan bukti historis | **ACCEPTED AS INTERNAL BASELINE** | Diratifikasi · M1 · 10 Nov 2026 |

**Hasil usulan** 5 ACCEPTED AS INTERNAL BASELINE, 6 AMEND, 1 DEFER, 0 REJECT. Untuk AMEND, teks pengganti ada pada baris Keputusan. DEFER pada ADR-09 berlaku untuk aktivasi otomasi dan menyebut syarat review ulang. Keputusan aktual M1 tercatat pada tabel di atas. Detail per ADR berikut mempertahankan usulan workshop sebagai riwayat.

**Dasar pembandingan** Blueprint §13 dan laporan Codex Phase 0B menggunakan commit acuan yang sama dengan HEAD main yang diperiksa pada workshop ini, `9df13b6952a88d4e396a21e95dda83e66c55d801`. WO-03 masuk melalui PR #14. Status CI dan working tree lokal berasal dari saat audit 0B. Workshop ini membandingkan dokumen dengan sumber GitHub secara baca-saja. Tidak ada uji aplikasi baru atau pemeriksaan DB produksi. Pranala bukti tersedia pada akhir register.

### ADR-01 · Modular monolith

**ACCEPTED AS INTERNAL BASELINE**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Aplikasi sudah memakai satu Laravel/MySQL. Audit §2 menemukan batas domain informal, halaman bisnis React/Inertia, serta halaman autentikasi dan welcome yang masih memakai Blade. Routes dan services pada commit acuan mendukung susunan tersebut. |
| Alternatif | Pertahankan organisasi konvensional dengan tanggung jawab domain yang eksplisit, restrukturisasi semua direktori menurut domain, atau pisah service per modul. |
| Keputusan yang direkomendasikan | Pakai satu deployment dan satu database. Modul pemilik memvalidasi tiap perubahan bisnis. Operasi yang harus berhasil bersama memakai transaksi lokal. Komponen lain boleh membaca hasil atau bereaksi setelah commit. |
| Rationale dan trade-off | Perubahan assignment, status dan histori dapat konsisten dalam satu transaksi. Biaya desainnya ialah disiplin menentukan pemilik data agar controller dan listener tidak menjadi jalur tulis yang saling bersaing. |
| Dampak DB, backend, frontend | DB: pertahankan identitas dan FK bersama. Backend: dokumentasikan pemilik operasi dan gunakan services existing yang sesuai. Frontend: pertahankan kontrak halaman React/Inertia dan halaman autentikasi yang berlaku. |
| Risiko dan mitigasi | Ketergantungan antar-modul bisa tersembunyi. Pada 0D, setiap entitas dan setiap perubahan lintas entitas harus memiliki pemilik serta batas transaksi yang tertulis. |
| Validasi stakeholder | Pilihan arsitektur internal. Perusahaan mengonfirmasi volume, konektivitas lapangan dan target pemulihan saat merencanakan deployment. |

### ADR-02 · Ticket dan origin WO

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | WO-02 menerima pembuatan manual oleh Koordinator, dengan satu site dan asset opsional yang harus berada di site tersebut. Audit §3–4 mendapati entitas asal pekerjaan belum tersedia. Contoh relasi project/plan di audit masih konseptual. |
| Alternatif | WO langsung untuk semua permintaan, source polymorphic bebas, atau satu primary origin bertipe dengan hubungan konteks tambahan. |
| Keputusan yang direkomendasikan | M1 mendukung `ticket` dan `manual` untuk WO baru. Manual memerlukan alasan dan aktor. WO lama dengan asal tak diketahui diberi provenance `legacy_unknown`. Satu ticket menghasilkan 0..N WO. Target Phase 2 menambah `maintenance_occurrence` dan `project_work_package`. Coverage kontrak dan ticket pendahulu project dapat ditelusuri sebagai konteks. Pilih relasi ber-FK dengan aturan tepat satu asal sah untuk WO baru di 0D. |
| Rationale dan trade-off | Ticket dapat selesai secara remote dan instalasi dapat dipecah per paket. Biaya tambahan ialah validasi kombinasi origin dan perlakuan transisi untuk klien WO-02 yang belum mengirim asal. |
| Dampak DB, backend, frontend | DB: relasi ticket opsional pada tahap perluasan, identitas origin dan alasan/provenance. Backend: buat WO+origin+audit sekali dalam transaksi. Kunci pembuatan ulang per aksi/purpose agar WO berbeda dari satu ticket tetap sah. Frontend: pada WO-02 pertahankan alur manual dan tambah jalur prefill dari ticket. |
| Risiko dan mitigasi | Form lama akan gagal bila kolom baru langsung wajib. Tambah dukungan baca dahulu, perbarui semua pemanggil, baru wajibkan alasan untuk manual baru. Peralihan tidak boleh menciptakan asal atau alasan palsu untuk request lama. |
| Validasi stakeholder | Nusalogi menetapkan jalur manual/emergency, otorisasi biaya dan data intake minimum. Untuk ERD, kaitan satu site per WO dan pemisahan origin dari coverage dapat disetujui internal. |

### ADR-03 · Assignment dan booking per visit

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | WO-03 merekam keanggotaan unik `(work_order_id, user_id)`, `assigned_at`, status dan history secara atomik. Audit §2/4 hanya menemukan `scheduled_at` opsional. Tidak ada durasi, slot, atau bukti pengujian bentrok simultan. |
| Alternatif | Satu waktu per WO, interval pada pivot teknisi, atau visit dan booking teknisi terpisah dari pivot. |
| Keputusan yang direkomendasikan | Pilih visit untuk satu kunjungan ke site WO. Satu WO memiliki 0..N visit. M1 menggunakan satu interval per visit dan satu booking per teknisi peserta. Peserta harus anggota WO yang eligible. Satu teknisi yang assigned bisa belum memiliki booking. Konfirmasi, reschedule dan pembatalan booking milik Koordinator. Akses WO tetap berasal dari membership WO-03. |
| Rationale dan trade-off | Kunjungan ulang dan perubahan jadwal tidak menimpa riwayat assignment. Satu interval bersama membatasi pengaturan jam berbeda antaranggota dalam kunjungan yang sama. Bila perlu slot berbeda, buat visit terpisah pada M1. |
| Dampak DB, backend, frontend | DB: visit, interval dan booking mengacu ke WO, teknisi dan membership yang valid. Backend: saat konfirmasi, lock resource teknisi dalam urutan tetap, cek lagi seluruh interval aktif, lalu tulis booking dan histori atomik. Frontend: pisahkan daftar assigned dari slot confirmed, tampilkan bentrok dan opsi jadwal ulang. |
| Risiko dan mitigasi | Overlap ditentukan dengan A.start < B.end dan B.start < A.end. Slot bersebelahan sah. Tim dengan satu anggota bentrok gagal dikonfirmasi seluruhnya. Reschedule mengganti booking dalam satu transaksi. `scheduled_at` lama hanyalah waktu rencana, tidak menghasilkan durasi atau booking terkonfirmasi. |
| Validasi stakeholder | Validasi slot kerja, siapa boleh menjadwalkan ulang, dan kebutuhan override. Default internal M1 memblokir bentrok tanpa override. Skill matrix, travel buffer dan optimizer ditunda. |

### ADR-04 · Lifecycle dan syarat penutupan WO

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | WorkOrderStatusService hanya memuat `pending→assigned` dan `assigned→in_progress`. Hanya assignment awal memiliki business route. Audit §3 menegaskan report, customer acceptance, rework dan closure belum end-to-end. |
| Alternatif | Tutup langsung setelah QA, satukan semua status dalam WO, atau pisahkan lifecycle dan simpan dasar penutupan. |
| Keputusan yang direkomendasikan | Gunakan `awaiting_acceptance`. WO normal ditutup dari status itu dengan report approved dan bukti acceptance yang mencakup scope. Pengecualian hanya dengan keputusan pihak berwenang, alasan, waktu, dan scope. Jika kebijakan pengecualian belum disahkan, jalur itu nonaktif. Bedakan perbaikan dokumen dari rework teknis. |
| Rationale dan trade-off | Antrean menunjukkan apakah pekerjaan menunggu QA, customer, atau teknisi. Dibutuhkan pemeriksaan lintas versi report/acceptance. BAST boleh disiapkan dari approved reports sebelum WO closed agar tidak terjadi saling tunggu. |
| Dampak DB, backend, frontend | DB: histori transisi, waktu selesai lapangan/QA/acceptance/closure, dasar closure dan versi evidence. Backend: satu service memvalidasi actor, expected state/version dan guards. Frontend: action sesuai peran dan state, antrean QA serta acceptance terpisah. |
| Risiko dan mitigasi | Klik ganda atau report direvisi ketika BAST diproses bisa menutup scope lama. Recheck versi di transaksi closure. Closed historis tetap menyimpan status aslinya dengan provenance yang diketahui. Insiden baru setelah closed menjadi follow-up yang tertaut. |
| Validasi stakeholder | Konfirmasi kapan job sheet cukup, siapa dapat membatalkan setelah pekerjaan berjalan, dan apakah exception closure diizinkan. Rincian mekanisme sebelum aktivasi M1. |

### ADR-05 · BAST berversi dan scope acceptance

**ACCEPTED AS INTERNAL BASELINE**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Audit §3–5 menemukan report/foto sebatas schema dengan banyak report per WO. BAST, review workflow, dan jalur unduh berotorisasi belum ada. Private disk sudah tersedia sebagai fondasi penyimpanan. |
| Alternatif | Satu flag accepted di WO, dokumen berversi dengan signed upload, atau portal customer dan provider tanda tangan. |
| Keputusan yang direkomendasikan | Pakai versi BAST yang dibekukan saat diterbitkan. Setiap item merujuk deliverable serta revisi report approved. Satu BAST dapat mencakup beberapa WO customer yang sama dalam konteks agreement yang kompatibel. MVP merekam dokumen eksternal dan memisahkan signer, internal recorder, signed_at dan recorded_at. |
| Rationale dan trade-off | Bukti tetap dapat direkonstruksi ketika report atau PIC berubah. Ada biaya menyimpan versi, menilai kewenangan signer serta mengelola akses file. Template/generator PDF BAST dapat dipilih kemudian bila format perusahaan membutuhkannya. |
| Dampak DB, backend, frontend | DB: BAST, versi, item scope, keputusan, metadata/hash dan referensi evidence privat. Backend: autorisasi upload/download per record, validasi file dan keterkaitan versi. Frontend: tunjukkan persis WO/revisi yang diterima, identitas signer dan tindakan bila ditolak. |
| Risiko dan mitigasi | No response tetap pending. Defect kembali rework, tambahan scope ke change approval. Untuk penerimaan sebagian, buat subset dengan scope eksplisit. WO hanya closed setelah seluruh scope wajibnya tercakup. Hash mendeteksi perubahan berkas, kewenangan signer tetap membutuhkan bukti tersendiri. |
| Validasi stakeholder | Nusalogi memilih formulir, bukti yang diterima, penanda tangan, retensi, dan pengecualian. Persetujuan ADR menerima mekanisme versi dan scope. |

### ADR-06 · Sumber tagihan dan invoice

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Audit §4/5 dan migration invoice konsisten. Satu invoice wajib memiliki satu `work_order_id`, tetapi banyak invoice dapat menunjuk WO yang sama. `customer_id` invoice berdiri sendiri dari pemilik site. Service menghitung dengan decimal/BCMath dan menerbitkan atas dasar creator. |
| Alternatif | Tetap wajib satu WO per invoice, buat invoice khusus per model bisnis, atau satu mekanisme sumber tagihan dengan scope awal one-time service. |
| Keputusan yang direkomendasikan | Pilih sumber hak menagih per charge jasa/material yang diotorisasi. M2 mendukung satu invoice untuk beberapa charge dan WO milik bill-to/currency/terms yang kompatibel. Satu charge awal ditagih utuh paling banyak sekali secara aktif. Split charge, DP, period dan milestone disiapkan sebagai ekstensi. Gunakan kembali `invoice_items`. Sumber setiap item harus dapat dilacak. Default M2 bill-to adalah customer pemilik site. |
| Rationale dan trade-off | Mendukung konsolidasi yang tidak dapat diwakili FK header saat ini. Batas satu charge utuh mengurangi kebutuhan ledger allocation umum. Perlu reservasi charge yang aman selama draft dan penanganan void/reissue. |
| Dampak DB, backend, frontend | DB: charge dengan sumber dan approved value, hubungan charge–item, snapshot bill-to/terms. Rencanakan transisi FK header menjadi opsional bagi invoice baru setelah pembaca lama siap. Backend: lock/recheck available charges, cek bill-to, kalkulasi ulang saat issue, validasi approval versi. Frontend: pilih charge eligible, preview per WO, rincian harga dan PDF sesuai snapshot issued. |
| Risiko dan mitigasi | Draft terbengkalai dapat menahan charge. Cancel draft melepaskan reservasi. Reissue setelah void mempertahankan reference asal dan histori. Zero charge covered disimpan sebagai disposition. Periksa PHP/BCMath aktual serta rounding sebelum M2. Audit menemukan `bcround()` dipakai walau Composer mengizinkan PHP 8.3. Tidak ada tarif pajak yang ditetapkan. |
| Validasi stakeholder | Konfirmasi consolidasi, bill-to pihak ketiga, coverage, tax/rounding, nomor/format invoice, jatuh tempo dan koreksi. Perubahan bill-to atau kebutuhan split charge dapat mengubah kardinalitas 0D M2. |

### ADR-07 · Verifikasi pembayaran dan kardinalitas allocation

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Audit §4/5 menunjukkan `invoice_payments` terikat satu invoice. `recordPayment` mengunci invoice tetapi tidak mengotorisasi actor atau memverifikasi dana. Model Invoice dan query dashboard menjumlah semua record. Duplicate posting kecil dapat lolos selama saldo cukup. |
| Alternatif | Langsung kurangi saldo saat dicatat, verifikasi payment yang terikat satu invoice, atau receipt independen dengan alokasi M:N. |
| Keputusan yang direkomendasikan | Wajibkan status dan bukti verifikasi sejak M2. Default desain M2 ialah banyak pembayaran parsial untuk satu invoice, setiap receipt dalam alur posting ini terikat ke tepat satu invoice dan currency. Satu transfer untuk beberapa invoice, uang muka tak teralokasi dan overpayment dicatat sebagai kasus rekonsiliasi berreferensi, tanpa memengaruhi saldo sampai ditangani. Aktifkan model Payment+PaymentAllocation independen jika contoh transaksi Nusalogi membuktikan kebutuhan itu. |
| Rationale dan trade-off | Menutup cacat saldo sambil menunda kompleksitas M:N. Bila transfer gabungan lazim, default sederhana akan menambah beban rekonsiliasi dan harus diganti sebelum ERD finance dibekukan. |
| Dampak DB, backend, frontend | DB: identitas receipt, status, pencatat, verifier, bukti dan reversal reference. Backend: kunci payment serta invoice, cek currency/sisa dan duplikasi, commit verification dan histori atomik. Frontend: antrean submitted/rejected/verified dan unmatched, tampilkan outstanding dari verified net. |
| Risiko dan mitigasi | Retry memakai business request key. Reference bank yang sama memicu pemeriksaan, tanpa asumsi unik global. Penolakan dan reversal mempertahankan bukti. Model, dashboard, export dan service harus menggunakan rumus saldo sama. Jangan memecah satu transfer menjadi receipt fiktif agar muat dalam model. |
| Validasi stakeholder | Validasi dengan contoh transfer parsial, gabungan, lebih bayar, refund dan bukti dana yang sah. Nusalogi menetapkan recorder/verifier dan kontrol pengganti bila satu orang menjalankan kedua fungsi. |

### ADR-08 · Authorization dan wewenang manusia

**AMEND**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Routes WO memakai middleware role. WO-01 membatasi teknisi melalui assignment sebelum pagination. Audit §5 menemukan dashboard memakai creator-or-assignee untuk semua role dan route dashboard hanya berada di grup auth. Issuance memeriksa creator. Payment belum memeriksa kewenangan actor. |
| Alternatif | Role kasar saja, capability dan policy per aksi/record, atau paket permission dinamis serta role baru. |
| Keputusan yang direkomendasikan | Pertahankan tiga role aplikasi. Gunakan policy/capability untuk tindakan sensitif serta scope query konsisten untuk baca. Periksa actor aktif/approved dan membership pada server termasuk pemanggil service. Mulai dengan mapping capability terbatas yang dapat diaudit. Record approval menunjuk actor, objek, versi dan alasan. Hak grant capability sensitif harus dibatasi. |
| Rationale dan trade-off | Role existing tetap berguna untuk akses kasar. Keputusan bisnis mendapat kontrol granular tanpa menambah role perusahaan. Memusatkan aturan memerlukan penyelarasan semua jalur baca dan tulis. |
| Dampak DB, backend, frontend | DB: gunakan users/role existing, simpan actor dan version untuk approval. Tabel permissions dinamis bukan syarat M1. Backend: satukan scope dashboard/list/detail/file, Policy untuk review/close/issue/verify, recheck saat commit. Frontend: server memberi allowed actions, financial metrics hanya untuk pengguna berhak. |
| Risiko dan mitigasi | Rute langsung, file download dan agregat dashboard dapat melewati pembatasan UI. Default deny untuk role lama tak didukung. Potensi race last-active-admin pada audit memerlukan evaluasi concurrency tersendiri. Maker/checker harus dua identitas bila separation diwajibkan. |
| Validasi stakeholder | Tentukan pemegang dan limit otorisasi QA, BAST, cancellation, commercial, issue, verify serta grant. Usulan dua orang untuk payment perlu dikonfirmasi. Tidak menunjuk staf atau anggota tim pengembang. |

### ADR-09 · Automasi native dan pemulihan

**DEFER**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Audit §2/5/6 menemukan konfigurasi DB queue, `after_commit=false`, serta ketiadaan business jobs/listeners/schedule. Runtime worker, recipient/channel, dan volume belum dibuktikan. Ini risiko calon workflow, bukan kegagalan pengiriman yang sudah terjadi. |
| Alternatif | Antrean tugas manual dari query domain, native Jobs/Events/Scheduler dengan recovery, atau workflow engine eksternal. |
| Keputusan yang direkomendasikan | Tunda rollout automasi business sampai ada proses dan operator yang siap. M1/M2 dapat berjalan dengan worklist persisten dan perintah manusia. Baseline desain sekarang menetapkan mutasi kritis dan histori dalam satu transaksi, identity stabil untuk retry, serta side effect setelah commit. Saat recurrence disetujui, occurrence harus punya business key sebelum WO dibuat. |
| Rationale dan trade-off | Menjaga operasi dapat dilanjutkan saat worker belum tersedia. Follow-up manual memerlukan pemilik antrean. Teknologi kandidat tetap native Laravel/database queue sesuai audit. |
| Dampak DB, backend, frontend | DB: M1/M2 menyediakan status sumber, timestamps, unique business keys yang diperlukan per slice. Tabel outbox generik, notification bus dan recurrence belum diperlukan. Backend: command sinkron untuk keputusan, recovery scan saat otomatisasi aktif. Frontend: worklist due/pending dan recovery untuk operator saat dibutuhkan. |
| Risiko dan mitigasi | ULID WO hanya unik sebagai nomor. Pencegahan WO PM ganda butuh occurrence identity. Setelah commit masih ada peluang dispatch hilang, maka proses kritis kelak perlu marker durable dan recovery. Buka kembali ADR saat trigger, SLA, retries/backoff, failure ownership dan worker heartbeat dapat diterima. |
| Validasi stakeholder | Perusahaan menetapkan jadwal PM, due rules, kanal/recipient, target eskalasi dan operator. Keputusan penundaan tidak menghalangi ERD M1/M2. |

### ADR-10 · Waktu operasional dan tanggal bisnis

**ACCEPTED AS INTERNAL BASELINE**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Config app UTC. WO-02 mengurai input datetime-local menggunakan timezone aplikasi dan listing mengembalikan tanggal. Dashboard menghitung today dan bulan menurut waktu aplikasi. Audit §5 mencatat potensi pergeseran hari WIB. |
| Alternatif | Pertahankan representasi timezone-naive, simpan semua nilai dalam WIB, atau UTC untuk instant dengan zona bisnis eksplisit. |
| Keputusan yang direkomendasikan | Simpan instant UTC, terima dan tampilkan waktu dengan zona site/operasi yang eksplisit. Default review M1 `Asia/Jakarta`. Simpan due date dan periode kontrak sebagai tanggal kalender pada zona bisnis yang disepakati. Definisikan range hari lokal sebelum query UTC. |
| Rationale dan trade-off | Jadwal, histori dan agregat konsisten di batas hari. Perlu membedakan waktu aktual, waktu yang disampaikan user, dan tanggal komersial. |
| Dampak DB, backend, frontend | DB: pertahankan timestamp existing, tambah metadata zona/provenance jika diperlukan. Backend: tetapkan konversi input dan range setengah terbuka hari lokal. Frontend: tampilkan zona beserta jam, terutama untuk booking dan perubahan jadwal. |
| Risiko dan mitigasi | Contoh uji 10 Nov 00.30 WIB sama dengan 9 Nov 17.30 UTC. Data lama diperiksa per asal dan cara input, tanpa konversi massal otomatis. Due date 10 Nov tetap tanggal 10 Nov. |
| Validasi stakeholder | Konfirmasi apakah semua site memakai WIB, bagaimana menentukan zona site dan kapan due date dianggap lewat. Timezone storage adalah keputusan internal. |

### ADR-11 · Asset pelanggan dan material usage

**ACCEPTED AS INTERNAL BASELINE**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Asset CRUD dan pivot WO–asset sudah tersedia. Audit §2 menemukan pembatasan pindah/hapus asset terkait WO, serta belum ada stock atau usage. Instalasi dapat dimulai sebelum asset baru terdaftar. |
| Alternatif | Gunakan asset sebagai stok, bangun inventory lengkap, atau catat actual usage per WO dan jaga master asset tetap terpisah. |
| Keputusan yang direkomendasikan | M1 merekam deskripsi barang, kuantitas, satuan, asal material bila relevan, dan koreksi/return pada WO. Satu perangkat terpasang dapat didaftarkan menjadi Asset di site yang sesuai. M2 charge material membutuhkan coverage dan harga yang diotorisasi. |
| Rationale dan trade-off | Memberikan bukti pemakaian lapangan dengan input sederhana. Belum dapat menghitung saldo gudang atau profitabilitas dari catatan ini saja. |
| Dampak DB, backend, frontend | DB: MaterialUsage terhubung ke WO, optional installed-asset link yang valid, correction reference dan snapshots. Backend: validasi site, satuan, qty dan approved revision. Frontend: input mobile minimal dan review selisih rencana–aktual. |
| Risiko dan mitigasi | Pemakaian customer-supplied atau material garansi rawan ditagih ulang. Simpan disposition serta link usage yang menjadi dasar charge. Pertahankan pembatasan pindah/hapus asset yang berhistori. |
| Validasi stakeholder | Konfirmasi satuan, bukti penggunaan, return/waste, customer-supplied, dan siapa menyetujui charge tambahan. Gudang/purchasing formal ditinjau di fase berikutnya. |

### ADR-12 · Perubahan additive dan bukti historis

**ACCEPTED AS INTERNAL BASELINE**

| Pertimbangan | Hasil workshop |
|---|---|
| Context, bukti main dan audit 0B | Audit §4 menemukan tabel legacy `customers_sites` kosong di DB development dengan `customers_id`. Model dan migration memakai `customer_sites`. Working tree saat audit hanya memiliki checkpoint untracked. Ini observasi lingkungan audit. State produksi belum diketahui. |
| Alternatif | Reset atau tulis ulang migration lama, langsung menerapkan constraint final, atau tambah struktur lalu backfill/reconcile/cutover bertahap. |
| Keputusan yang direkomendasikan | Gunakan strategi expand → klasifikasi/backfill restartable → parity read → cutover satu writer → perketat constraint. Pertahankan ID, nomor, saldo, status dan evidence lama. Tandai `legacy_unknown` sampai ada bukti. Catat drift per lingkungan dan dependency-nya. |
| Rationale dan trade-off | Release bertahap menjaga integrasi WO-01/02/03 dan data historis. Ada biaya sementara berupa adapter baca, rekonsiliasi dan dua representasi yang harus ditutup secara terencana. |
| Dampak DB, backend, frontend | DB: inventory schema/data per environment, mapping old→new dan bukti backfill. Backend: tentukan writer tunggal dan adapter baca per tahap. Frontend: tampilkan provenance legacy ketika memengaruhi interpretasi, sajikan alur baru hanya saat siap. |
| Risiko dan mitigasi | Bandingkan jumlah WO/pivot, nilai invoice per currency, penerimaan dan outstanding per invoice. Kasus payment lama yang belum terbukti diverifikasi diberi jalur review atau saldo pembuka legacy terkendali dengan provenance sendiri. Selisih tak terjelaskan menahan cutover. Backup DB dan file harus dapat dipulihkan bersama. |
| Validasi stakeholder | Nusalogi menetapkan pemilik rekonsiliasi, retensi, target recovery dan persetujuan cutover. Observasi empty table di development tidak mengotorisasi penghapusan tabel di lingkungan lain. |

## Baseline scope M0, M1 dan M2

| Slice | Hasil yang harus dapat digunakan | Dependensi dan bukti penerimaan | Ditunda secara eksplisit |
|---|---|---|---|
| **M0 · Fondasi dan kompatibilitas** | Baseline schema dan perilaku terpetakan. Scope dashboard konsisten dengan listing. Definisi zona, matriks izin dan kontrak interface lama jelas. | Referensi commit/PR, inventaris drift per lingkungan dan daftar data ambigu. Untuk rilis perubahan M0, Admin/Koordinator mendapat himpunan WO berizin, Teknisi hanya assigned, role lama ditolak. Estimasi jumlah dan tren memakai himpunan berizin yang sama. Browser/mobile QA untuk alur create/assign dicatat terpisah dari CI. | Redesign frontend, granular role administration, penggantian stack. |
| **M1 · Corrective service sampai penutupan operasional** | Intake manual, triage, remote atau kunjungan, coverage/reference biaya, ticket atau manual origin, WO-01/02/03, visit dan booking sederhana, eksekusi, hold, report/checklist/foto privat, actual material usage, QA/rework, BAST berversi, closure dan histori asset/WO yang relevan. | M0 + ADR-02/03/04/05/08/10/11. Satu ticket dapat selesai remote, atau punya dua WO dengan keputusan acceptance berbeda. Operator dapat melihat WO yang tertahan dan alasannya. BAST reject dapat diperbaiki dengan revisi baru. Satu pekerjaan covered selesai operasional dengan disposition tercatat. | Portal dan e-sign provider, advanced dispatch, stock/procurement, contract engine, auto-PM, SLA penalties, invoice dan settlement di slice ini. |
| **M2 · Commercial one-time service** | Approved charge jasa/material, invoice draft/review/issue dan PDF, konsolidasi charge eligible, payment submission/verification, partial payment, outstanding/overdue, antrean dispute dan koreksi terbatas. | M1 + ADR-06/07/08/12. Uji satu invoice dari dua WO, retry invoice dan payment, receipt belum verified, partial payment dan reversal terotorisasi. Rekonsiliasi sebelum cutover finance. Tunjukkan invoice lama tetap dapat ditelusuri. | DP/termin, period billing, split charge, M:N receipt allocation dan unapplied cash ledger kecuali OD-04 mengubah scope, ERP/accounting integration, refund otomatis, multi-currency settlements. |

**Project-to-Service dan PM** tetap menjadi target setelah M2. Survey pra-project dapat direncanakan sebagai pekerjaan dari ticket yang sudah diotorisasi. Fase berikutnya memperkenalkan approved quote/PO, project → work package → WO, milestone dengan kumpulan deliverable, contract → maintenance plan → occurrence. Garansi menggunakan entitlement dan tanggal efektif. Billing DP mengikuti commercial terms, billing milestone mengikuti bukti yang disepakati, dan billing period berdiri sendiri dari jumlah visit. Persetujuan M1 tidak memesan pembangunan tabel untuk seluruh target masa depan.

## Domain boundaries dan business invariants

| Pemilik domain | Fakta yang dikuasai | Invariant lintas modul |
|---|---|---|
| Identity & Access | Akun, role, approval dan capability | Actor internal aktif dan approved untuk tindakan terlindungi. Otorisasi sensitif dicek lagi ketika transaksi dijalankan. |
| Customer, Site & Asset | Customer pemilik, site, installed asset | Satu WO satu site. Semua asset WO harus sah pada site tersebut. Riwayat dan snapshot transaksi tetap dapat dijelaskan setelah master berubah. |
| Service Request | Keluhan, triage, resolution, authorization reference | Ticket memiliki 0..N WO. Satu WO closed baru menyelesaikan ticket ketika semua scope ticket terselesaikan atau memiliki disposition resmi. |
| Project & Contract | Scope komersial, work package, milestone, coverage, periode dan occurrence | Project/contract memberi konteks yang berbeda dari primary origin. Frekuensi visit dan frekuensi penagihan independen menurut terms. Phase 2. |
| Work Order | Instruksi, origin, membership dan status operasional | Nomor/server-managed actor tetap otoritatif. WO baru memiliki satu primary origin. Update status dan histori berhasil atau gagal bersama. |
| Scheduling & Dispatch | Visit/booking, siapa hadir dan kapan | Booking confirmed memerlukan teknisi eligible, membership valid, dan interval bebas bentrok. Pembatalan booking mempertahankan WO dan histori. |
| Field Service / QA | Report revision, waktu eksekusi, checklist, hasil dan foto | Approved menunjukkan revisi spesifik yang diperiksa. Pengiriman ulang mempertahankan evidence versi lama. |
| Customer Acceptance | Versi BAST, item scope, signer dan keputusan | Hanya PIC berwenang menerima hasil. Rekaman internal mencatat bukti keputusan eksternal. Penolakan atau keterlambatan tidak membuka closure normal. |
| Material Usage | Jumlah terpakai, satuan, koreksi, perangkat yang dihasilkan | Usage aktual mungkin covered, milik customer, atau dapat ditagih setelah otorisasi. Satu usage tidak boleh menjadi charge ganda. |
| Billing & Payment | Hak menagih, invoice snapshot, receipt, verification dan saldo | Hak menagih, nilai diterbitkan, serta pembayaran verified masing-masing memiliki bukti. Perubahan payment tidak mengubah status WO atau BAST. |
| Monitoring & Reporting | Antrean, KPI dan proyeksi sesuai scope | Query dashboard, list, export dan detail mengikuti batas data yang sama. Ringkasan finance menggunakan perhitungan settlement canonical. |
| Notification & Automation | Delivery dan recovery saat diaktifkan | Kegagalan delivery tidak membalikkan approval committed. Auto-generation harus dapat dilanjutkan dengan business key yang sama. |

### Lifecycle yang diusulkan untuk review 0D

Nama teknis di bawah adalah vocabulary logis. Mapping nilai existing, contohnya payment status proyeksi `partial`, harus eksplisit bila label target menggunakan `partially_paid`.

| Objek dan slice | Jalur normal, otoritas dan guard | Pengecualian / akhir |
|---|---|---|
| Ticket · M1 | Intake membuat new. Koordinator triage, meneruskan ke awaiting_authorization bila perlu, approve, lalu in_fulfillment setelah WO dibuat. Resolved setelah seluruh scope selesai. Remote resolution dapat dari triaged bila hasil terkonfirmasi. | waiting_information kembali ke triage. duplicate perlu canonical ticket. rejected/cancelled perlu alasan dan review dampak ke WO terbuka. |
| WO · M1 | pending → assigned oleh Koordinator lewat WO-03 → in_progress oleh Teknisi assigned → pending_review saat report terkirim → awaiting_acceptance saat QA menyetujui versi completion report → closed ketika Koordinator mengonfirmasi acceptance mencakup seluruh scope wajib atau mencatat exception sah. | assigned/in_progress/rework → on_hold dengan state asal dan blocker. Resume ke state asal yang masih valid. Technical rejection dari QA atau BAST → rework → in_progress → submit ulang. Cancel pada state nonterminal membutuhkan alasan dan disposition biaya/anak. Closed/cancelled terminal, follow-up memakai WO baru. |
| Report · M1 | draft → submitted oleh author assigned → approved oleh reviewer berwenang. Setiap submission menunjuk satu revisi yang dibekukan. | submitted → needs_revision dengan alasan. Document fix membuat revisi baru dan WO tetap pending_review. Technical rework mengembalikan WO ke lapangan. Perubahan atas approved version perlu revisi pengganti dan review dampak ke BAST. |
| BAST · M1 | draft → pending saat scope dan bukti issued → accepted dengan bukti PIC atau rejected dengan alasan. | Tidak ada respons tetap pending. Salah dokumen → superseded dan pasangan versi baru. Accepted final pada versi itu. Exception closure memiliki record sendiri dengan pihak pemberi izin. |
| Invoice · M2 | draft → pending_approval → approved → issued oleh pihak berwenang. Approval mengacu versi totals, charge dan bill-to. Isi berubah sebelum issued mengembalikan ke draft. | Draft batal melepaskan reservasi. Issued immutable termasuk items. Void/replacement beralasan untuk invoice yang net payment-nya telah ditangani. Invoice dengan dana terverifikasi masuk review koreksi. |
| Payment · M2 | draft → submitted → verified oleh verifier setelah mencocokkan dana dan saldo. Posting bersamaan dikunci agar net verified tidak melebihi tagihan. | submitted → rejected atau cancelled beralasan. Koreksi data rejected mempertahankan riwayat. Verified → reversed melalui compensating entry dan approval. Settlement invoice adalah unpaid/partial/paid. Overdue adalah kondisi tanggal plus outstanding positif. |
| Quotation · target | draft → pending_approval → approved → sent → accepted untuk satu versi dengan bukti customer. | Revisi internal kembali draft, penawaran sent dapat rejected/expired/withdrawn. Perubahan setelah accepted melalui amendment. Reference dan bukti biaya cukup untuk M1/M2 awal. |
| Project · target | draft → planning setelah baseline commercial → active setelah start conditions → awaiting_acceptance → completed setelah handover final. | on_hold dengan alasan, rework kembali active, cancel dengan settlement review. Project completed tetap dapat punya outstanding. |
| Contract · target | draft → pending_approval → scheduled jika tanggal efektif belum tiba, lalu active. Berakhir sesuai validity. | Revision saat review, suspended/resumed, expired atau terminated. Perubahan efektif ke depan mempertahankan hak/kewajiban periode lalu. |

**Satu contoh keputusan lintas domain.** Dua WO menangani satu gangguan. Laporan WO pertama disetujui, laporan kedua perlu uji ulang. BAST atas hasil WO pertama boleh disiapkan jika scope itu dapat diterima sendiri. Hanya WO yang seluruh scope wajibnya tercakup dapat ditutup. Ticket masih in_fulfillment. Jika akhirnya dua charge disetujui untuk ditagih bersama, invoice mencantumkan dua sumber dengan satu bill-to yang valid. Bukti transfer yang baru diunggah hanya mengisi antrean submitted.

### Authorization minimum

| Aksi | Pelaksana internal / eksternal | Pemeriksaan dan bukti |
|---|---|---|
| Master customer/site/asset dan akun | Admin sesuai hak existing | Batas rute dan relasi site/customer tetap berlaku. Teknisi memperoleh data minimum terkait pekerjaan yang ditugaskan. Hak browse master umum perlu keputusan tambahan. |
| Create/assign WO | Koordinator aktif approved | Creator dari session, assignee aktif approved, status pending untuk assignment pertama, membership + status + history atomik. |
| Booking/reschedule/cancel | Koordinator berwenang | Recheck interval, eligibility, state dan scope ketika commit. Simpan slot lama, alasan serta actor. |
| Progres dan submit report | Teknisi assigned | Cek relasi WO, revisi berjalan dan state yang diizinkan. Koordinator menetapkan author untuk tim multi-teknisi bila dibutuhkan. |
| QA dan closure | Koordinator/reviewer authorized | Review versi tertentu, tolak dengan alasan. Cegah self-review pada default usulan. Exception closure default tidak tersedia sampai authority ditetapkan. |
| Keputusan BAST / perekaman bukti | PIC customer / recorder internal terotorisasi | Pisahkan external signer dari recorder dan waktu masing-masing. Akses file mengikuti record dan scope. |
| Charge dan issue invoice | Preparer, approver dan issuer bercapability | Cek source/version/limit. Setiap perubahan material membatalkan approval lama. Cek server tetap berlaku untuk pemanggilan service langsung. |
| Record, verify, reverse payment | Recorder, verifier dan approver koreksi | Default dua user untuk pencatatan dan verifikasi. Jika perusahaan mengizinkan penanganan staf tunggal, definisikan pemeriksaan independen pengganti sebelum aktivasi. Semua koreksi ditautkan ke transaksi asal. |

## Dependency, risiko dan kompatibilitas

| Permukaan existing | Kontrak yang harus dijaga | Dampak dan bukti yang diminta pada fase implementasi |
|---|---|---|
| WO-01 listing | Filter database sebelum pagination, Admin/Koordinator dan Teknisi sesuai scope | State tambahan dapat mengubah filter aktif, totals dan urutan. Verifikasi list/detail/dashboard/unduh terhadap kasus creator-only, assignee, non-assignee, legacy role dan account nonaktif. |
| WO-02 creation | Koordinator, nomor WO-ULID, actor dari session, pending awal, site sah dan asset opsional satu site | Rollout origin dan zona harus menangani form lama. Cek WO manual lama masih terbaca, WO baru dengan ticket memperoleh satu origin, ticket beda customer/site ditolak, instalasi tanpa asset tetap sah. |
| WO-03 assignment | Banyak teknisi eligible, pending → assigned, pivot unik, assigned_at dan histori atomik | Booking berdiri di atas membership. Dua submit paralel tidak menggandakan pivot/history. Pembatalan/reschedule visit tidak mencabut membership secara implisit. Reassignment saat ada booking confirmed harus menyelesaikan booking terdampak secara eksplisit. |
| Customer | Admin mengelola master, invoice punya customer_id tersendiri | Validasi bill-to terhadap pemilik site. Simpan snapshot bill-to saat issue. Perubahan alamat tidak mengubah PDF final yang sudah issued. |
| Customer Site | customer_sites mengikat lokasi ke customer, WO terikat satu site | Pertahankan scoped lookup. Site tidak boleh dipindahkan/diganti sehingga jejak WO, BAST atau charge lintas customer berubah. Pemetaan zona tidak menggeser jadwal lama diam-diam. |
| Asset | Asset per site, asset_code unik, pivot WO–asset unik | Histori penggunaan dan acceptance tidak bergantung pada stok. Asset bersejarah tetap terlacak meski data master berubah. Bukti actual usage tidak menambah atau mengurangi jumlah asset secara tersirat. |
| MySQL dan dokumen | Restrictive FK, decimal, invoice_items, invoice_payments dan histori yang sudah ada | Inventaris drift customers_sites per lingkungan. Rancang indeks untuk worklists, overlap, eligibility dan keys setelah kardinalitas diputuskan. Validasi backup/restore DB bersama private files sebelum cutover. |
| Saldo dan dashboard | Model Invoice dan DashboardSummary saat ini sama-sama menjumlah semua payment | Rencanakan perpindahan kedua pembaca dan writer secara konsisten. Verifikasi per-invoice parity, nilai historis, duplicate/ambiguity queue, rounding dan BCMath pada runtime target. |
| Uji dan operasional | CI PR #14 hijau pada audit. Frontend harness menggunakan mock | Browser/mobile navigation, download privat, hari WIB, concurrent booking/allocation dan recovery membutuhkan bukti tersendiri pada staging. Belum ada pengukuran beban produksi atas option payload atau helper saldo. |

**Urutan dependensi.** M0 menyediakan scope dan waktu yang sama untuk list serta dashboard. M1 menyusun intake/origin → assignment/visit → report review → acceptance/closure. Material usage dapat dicatat selama eksekusi. M2 menggunakan bukti otorisasi biaya, keputusan coverage dan acceptance untuk menentukan charge eligible, dilanjutkan invoice dan payment. Recurrence dan automation membaca state yang sudah otoritatif setelah domain tersebut stabil.

## Open Decision Register

Semua keputusan bisnis berikut berstatus **PROPOSED — NEEDS VALIDATION**. Fungsi pada kolom pemilik menunjukkan pihak yang perlu memberi bukti atau keputusan. Default arsitektur M1 mengikuti ratifikasi 10 November. Pertanyaan pelaksanaan bisnis dan keputusan M2 tetap terbuka. Pemilik kebijakan perusahaan tetap perlu diidentifikasi oleh PT Nusalogi.

| ID · ADR | Keputusan terbuka dan bukti untuk memutuskannya | Default workshop · konsekuensi | Pemilik dan batas waktu |
|---|---|---|---|
| OD-01 · 02 | Kanal intake dan pemakaian manual/emergency, contoh ticket remote serta multi-WO | ticket/manual baru dan legacy_unknown historis. Perubahan jumlah primary origin memengaruhi ERD M1. | Fathan putuskan cardinality internal sebelum 0D M1. Fungsi operasi Nusalogi sahkan kewenangan sebelum rilis. |
| OD-02 · 03/10 | Contoh schedule tim, kunjungan ulang, site berbeda zona, bentrok dan pergantian teknisi | Visit per WO, interval bersama, booking per resource, block overlap. Jika per-teknisi perlu jam berbeda pada visit sama, tinjau cardinality booking sebelum pembekuan ERD. | Koordinator operasional Nusalogi, Fathan untuk baseline 0D M1. |
| OD-03 · 04/05 | Contoh BAST normal/parsial/ditolak dan job sheet. Siapa signer dan siapa boleh memberi exception? | Item berbasis versi dan full required scope untuk close. Tanpa exception terotorisasi, WO menunggu customer. | Operasi dan wakil customer/komersial. Scope cardinality sebelum 0D M1, legalitas bentuk bukti sebelum penggunaan. |
| OD-04 · 07 | Contoh mutasi bank yang melunasi beberapa invoice, uang muka dan overpayment. Seberapa sering terjadi? | Satu receipt terposting per invoice sebagai baseline M2. Jika transfer gabungan wajib didukung, pilih receipt independen + M:N allocations sejak 0D M2. | Fungsi keuangan Nusalogi. Harus ada pilihan atau penundaan finance ERD secara eksplisit. |
| OD-05 · 06 | Contoh invoice konsolidasi, split charge, bill-to lain, warranty dan pembatalan | Whole-charge, same bill-to/currency, approved one-time scope. Third-party billing atau split mengubah constraint dan allocation. | Fungsi komersial/keuangan. Tetapkan batas internal sebelum 0D M2. |
| OD-06 · 06/07/08 | Siapa approve biaya, issue, verify, void dan reverse? Apa limit dan bukti independennya? | Capability minimal, reason/version, recorder dan verifier terpisah. Belum ada pemegang kewenangan Nusalogi yang dikonfirmasi. | Pihak pemberi otorisasi perusahaan. Record identitas untuk desain, mapping orang dan limit sebelum M2 aktif. |
| OD-07 · 06 | Dokumen invoice resmi, currency/scale, rounding, tax, due date, refund | Snapshot nilai dan konfigurasi per dokumen. Formula pajak dan angka kebijakan menunggu bukti. | Finance Nusalogi. Sebelum finalisasi desain nilai uang dan penerbitan dokumen nyata. |
| OD-08 · 05/08/12 | Siapa boleh melihat/unduh foto, BAST, payment proof? Berapa retensi dan target pemulihan? | Private files, otorisasi record, metadata/hash serta backup bersama DB. | Owner data Nusalogi dan operasi TI. Sebelum rilis slice terkait. |
| OD-09 · 09 | Frekuensi PM, kalender SLA, reminder recipient, worker ownership dan toleransi recovery | Worklist manual. Review automasi setelah ada plan/trigger dan operator yang disahkan. | Owner kontrak/operasi. Fase sesudah M2 kecuali prioritas diubah. |
| OD-10 · 10/12 | Arti timestamp legacy, drift tabel di lingkungan lain, asal dan bukti dana lama | Catat ambigu/provenance. Perubahan schema dan cutover perlu sampel, parity serta disposition. | Pemilik data/lingkungan. Inventory saat desain fisik, reconciliation sebelum cutover. |
| OD-11 · 11 | Contoh usage aktual, returns, unit, material customer dan batas gudang | Ledger penggunaan per WO dengan koreksi. Inventory dan procurement ditunda. | Fungsi lapangan dan material Nusalogi. Sebelum finalisasi field usage. |
| OD-12 · 01/06/08/12 | Runtime deployment PHP/BCMath, perbaikan dashboard, kemungkinan race last-active-admin, bukti QA dan restore | Internal engineering menilai aktor downgrade/disable bersamaan, service calculations/issue, range WIB dan browser asli. Audit melaporkan potensi race tanpa reproduksi multiproses. | Pemilik teknis internal. Risiko dicatat pada 0D, bukti runtime/UAT/restore pada rilis atau cutover terkait. |

## Acceptance criteria sebelum Phase 0D ERD Design

| Gate | Bukti keputusan untuk mulai ERD | Status pada akhir workshop |
|---|---|---|
| G1 · Disposisi 12 ADR | Setiap nomor punya teks keputusan, scope, alasan, reviewer dan disposition. Amendment diterima atau dicatat perubahannya. | Selesai untuk sembilan keputusan M1 pada 10 November 2026. ADR-06/07/09 berada di luar ratifikasi. |
| G2 · Scope | Pilih M0/M1/M2 seperti tabel atau catat delta, terutama batas project/PM, charge split dan multi-invoice payment. | Batas M1 diratifikasi. M2 menunggu OD-04/05. |
| G3 · Kardinalitas M1 | Setujui site per WO, origin XOR, 0..N WO per ticket, visit/booking dan membership tunggal, versioned report dan acceptance items. Walkthrough remote-only, dua WO satu ticket, satu BAST subset dan reschedule multi-teknisi menghasilkan jejak yang konsisten. | Kardinalitas M1 diratifikasi. Walkthrough dan uji kelayakan fisik dilanjutkan di 0D. |
| G4 · Tanggung jawab dan status | Untuk tiap mutasi kritis, nyatakan actor, guard, history, revisi, cancellation/rework dan terminal state. Pastikan BAST dapat disusun sebelum closure, dan payment tidak mengubah state lapangan. | Lifecycle dan prinsip authorization M1 diratifikasi. Kewenangan exception masih terbuka, jalurnya nonaktif. |
| G5 · Kardinalitas M2 | Putuskan eligible charge → invoice item, bill-to, dan receipt → invoice. Gunakan contoh dua WO satu invoice, partial receipt, transfer gabungan serta void/reversal. Bila bukti belum ada, batasi desain ke one-time/single-invoice secara eksplisit atau tunda ERD finance. | Pilihan default serta alternatif terdokumentasi. Sampel perusahaan dan ratifikasi belum tercatat. |
| G6 · Kompatibilitas dan lineage | ERD brief memuat existing/proposed/legacy, stable IDs, sumber data, dampak FK wajib, pembaca saldo, snapshot dan migrasi additive. Daftar unknown berisi owner, dampak dan titik keputusan. | Brief dan risiko tersedia. Pemeriksaan data per lingkungan dilakukan sebelum perubahan fisik. |

**Keputusan kesiapan per 10 November 2026.** Fathan memberi mandat memulai 0D M1 berdasarkan ratifikasi di atas. G6 dibawa sebagai brief kompatibilitas. G5 dan ERD finance M2 tetap terbuka. Persetujuan implementasi menunggu technical feasibility review. Kebutuhan validasi bisnis pada Open Decision Register tetap berlaku bagi pihak berwenang.

## Sumber workshop

- Blueprint Business Process v0.2, terutama §7–13 dan §16, salinan draft 10 November. [Dokumen Phase 0A](https://chatgpt.com/library?file_id=libfile_2a53b4bd9d908191a204aa98249224fe&account_id=personal)
- Technical Architecture Audit Codex, laporan baca-saja dengan kesimpulan **NEEDS DECISIONS**, §1–8. [Dokumen Phase 0B](https://chatgpt.com/library?file_id=libfile_078f20e0c1cc81919b57ddd260c32a4f&account_id=personal)
- Repository yang dibandingkan. [Commit main](https://github.com/fathxvn/nusalogi-maintenance-system/tree/9df13b6952a88d4e396a21e95dda83e66c55d801) · [WO-03 PR #14](https://github.com/fathxvn/nusalogi-maintenance-system/pull/14)

| ADR | Lokasi yang diperiksa pada commit acuan |
|---|---|
| 01/08 | [routes/web.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/routes/web.php) |
| 02/03/10 | [app/Http/Controllers/WorkOrderController.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Http/Controllers/WorkOrderController.php) |
| 03/08 | [app/Http/Controllers/WorkOrderAssignmentController.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Http/Controllers/WorkOrderAssignmentController.php) |
| 04/05 | [app/Services/WorkOrderStatusService.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Services/WorkOrderStatusService.php) |
| 06/12 | [database/migrations/2026_10_20_185452_create_invoices_table.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/database/migrations/2026_10_20_185452_create_invoices_table.php) |
| 06/08 | [app/Services/InvoiceIssuanceService.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Services/InvoiceIssuanceService.php) |
| 06/12 | [app/Services/InvoiceCalculationService.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Services/InvoiceCalculationService.php) |
| 06/12 | [composer.json](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/composer.json) |
| 07/08 | [app/Services/InvoicePaymentService.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Services/InvoicePaymentService.php) |
| 07/12 | [app/Models/Invoice.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Models/Invoice.php) |
| 07/08/10 | [app/Services/DashboardSummary.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Services/DashboardSummary.php) |
| 09 | [config/queue.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/config/queue.php) |
| 10 | [config/app.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/config/app.php) |
| 11/12 | [app/Http/Controllers/Admin/AssetController.php](https://github.com/fathxvn/nusalogi-maintenance-system/blob/9df13b6952a88d4e396a21e95dda83e66c55d801/app/Http/Controllers/Admin/AssetController.php) |
