# Baseline dokumentasi M1

Persiapan M1 per 10 November 2026, sesudah M0 merged melalui PR #15. Main lokal sudah di-fast-forward ke origin/main pada `e736ba913ed9f556ccaf2038595af61ef7643410`; branch persiapan adalah `docs/m1-design-baseline`. Baseline desain historis tetap `9df13b6952a88d4e396a21e95dda83e66c55d801`.

## Sumber utama dan urutan baca

| Dokumen | Kedudukan |
|---|---|
| [Blueprint Phase 0A](Nusalogi_Business_Process_Blueprint_v0.2_DRAFT_2026-11-10.md) | Konteks proses dan target jangka panjang. Status draft/asumsi bisnis dipertahankan; bukan bukti SOP PT Nusalogi maupun instruksi mengimplementasikan seluruh M2. |
| [Architecture Decision Register Phase 0C](Nusalogi_Architecture_Decision_Register_v0.2_Phase_0C.md) | Ratifikasi internal sembilan ADR M1 oleh Fathan. Keputusan perusahaan dan finance M2 yang masih terbuka tetap terbuka. Isi ratifikasi tidak diubah. |
| [Domain Model & Database Design M1 Phase 0D](Nusalogi_Domain_Model_Database_Design_M1_Phase_0D.md) | Desain normatif M1, 21 tabel, dengan revisi A1–A6. Status implementasi diperbarui untuk M0 PR #15; algoritma amendment dan sembilan ADR dipertahankan. |
| [Pemeriksaan awal F01](m1-schema-baseline-f01.md) | Bukti repository dan metadata development, drift serta pemeriksaan staging yang belum tersedia. Tidak menggantikan desain atau mengotorisasi migration. |

Jika uraian lama berbeda, gunakan keputusan Fathan/ratifikasi 0C dan perincian 0D yang relevan. Referensi kode pada 9df13b6 menunjukkan keadaan historis; main e736ba9 sudah memiliki visibilitas WO bersama untuk Dashboard dan WO-01. Tidak ada migration berubah pada delta M0.

## Cakupan PR dokumentasi yang direkomendasikan

Masukkan tepat lima file: index ini, Blueprint 0A, register 0C, desain 0D, dan laporan F01 di atas. Dokumen v0.1 existing tetap referensi historis; tidak ditulis ulang dalam persiapan ini. Belum ada staging Git, commit, push atau PR yang dibuat.

Saat pemeriksaan akhir, terdeteksi patch lokal baru pada `app/Http/Controllers/WorkOrderController.php` yang mengembalikan query listing ke filter inline. Patch itu dipertahankan tanpa disentuh dan **bukan bagian PR dokumentasi**. Status M0 di dokumen ini merujuk commit main e736ba9, bukan menyatakan seluruh working tree identik dengan commit tersebut. Jangan memasukkan seluruh working tree secara massal ke PR baseline.

Dua salinan lokal dipindahkan ke `docs/archive/` dengan bytes tetap, dan **tidak direkomendasikan masuk PR baseline ini**:

- `docs/archive/Nusalogi_Handoff_Codex_M1.md`: gabungan sumber/salinan 0D sebelum A1–A6; dapat bertentangan dengan desain utama.
- `docs/archive/Nusalogi_Master_Checkpoint_v0.2_2026-11-10.md`: checkpoint sebelum WO-03/M0 merged; klaim branch/fitur di dalamnya sudah historis.

Path arsip tersebut hanya penanda lokasi lokal, bukan dependency dokumen PR. Jika arsip kelak dipublikasikan, pisahkan sebagai histori dengan label yang jelas; jangan gunakan sebagai sumber normatif kedua.

## Batas kesiapan

M0 sudah merged. F01 repository telah dibandingkan dengan metadata development pada tabel prioritas, dengan drift tercatat. **F01 staging: NOT YET VERIFIED.** F03 setelah expand/cutover M1 tetap terbuka; full backend M0 yang pernah lulus tidak membuktikan kompatibilitas setelah migration M1.

Langkah terkecil menuju Ticket → Work Order adalah menyusun acceptance contract satu slice intake/origin: ticket boleh tanpa WO, satu ticket boleh menghasilkan beberapa WO, manual wajib alasan, dan retry satu command tidak menggandakan WO. Tidak perlu membangun booking, BAST atau finance pada slice pertama. DDL/cutover menunggu bukti lingkungan dan gate Phase 0D.
