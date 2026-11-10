# F01 awal — baseline schema M1

Pemeriksaan read-only pada 10 November 2026; pembacaan metadata dimulai `2026-11-10T13:53:26+00:00` (20.53 WIB). Tidak ada kode aplikasi, migration, data domain, dependency, commit atau push yang diubah. Pembacaan dilakukan berurutan, bukan snapshot backup atomik.

## 1. Lingkungan dan batas bukti

| Sumber | Yang terverifikasi | Status dan batas |
|---|---|---|
| Repository | Main dan origin/main pada `e736ba913ed9f556ccaf2038595af61ef7643410`, merge PR #15. Branch kerja `docs/m1-design-baseline`. Delta dari desain `9df13b6` hanya M0 pada empat file aplikasi/route dan tiga test; migration tidak berubah. | VERIFIED untuk pembandingan statis prioritas. Schema yang tertulis tidak membuktikan semua lingkungan sudah sama. |
| Development | Laravel `APP_ENV=local`, koneksi default `mysql`, database `nusalogi_maintenance` melalui Sail. MySQL 8.4.11, InnoDB, REPEATABLE-READ, session timezone SYSTEM dengan system timezone UTC. Tabel prioritas memakai utf8mb4_unicode_ci dan PK/FK BIGINT UNSIGNED. | Metadata dan counts yang disebut di bawah VERIFIED; drift dicatat, bukan dihapus. Tidak ada test atau simulasi concurrency. |
| Staging | Tidak ada koneksi yang diidentifikasi sebagai staging atau akses/schema export staging dalam workspace yang diperiksa. Konfigurasi memuat nama koneksi sqlite/mysql/mariadb/pgsql/sqlsrv, bukan bukti ada staging. File environment yang ditemukan hanya .env dan .env.example; rahasianya tidak ditampilkan. | **F01 staging: NOT YET VERIFIED.** Development dan database testing bukan substitusi staging. |

Boost database-schema mengembalikan tabel kosong dan database-query gagal `could not find driver` pada proses host. Hasil kosong itu tidak dianggap bukti database kosong. Fallback memakai Laravel bootstrap di Sail dan hanya SELECT, SHOW CREATE TABLE, information_schema serta SELECT ledger/count; tidak memakai migrate, rollback, tinker mutation, DDL atau DML.

Pemeriksaan akhir mendeteksi patch lokal WorkOrderController yang terpisah dari pekerjaan dokumentasi dan dipertahankan utuh. Pembandingan repository pada laporan ini mengacu migration committed e736ba9; patch controller tidak mengubah schema atau menjadi bagian baseline dokumentasi.

## 2. Compatibility matrix tabel prioritas

PK id di bawah adalah BIGINT UNSIGNED AUTO_INCREMENT, kecuali pivot asset yang memang tanpa id. Semua kolom FK yang disebut NOT NULL kecuali dinyatakan nullable. Seluruh UPDATE_RULE FK yang diperiksa tercatat NO ACTION. IX berarti indeks non-unique; indeks pendukung seluruh FK tersedia. created_at/updated_at existing nullable kecuali tabel yang tidak memilikinya.

| Tabel dan sumber migration | PK / unique / indeks penting | FK dan DELETE_RULE aktual development | Nullability / baseline yang relevan | Rows saat baca |
|---|---|---|---|---|
| work_orders — [migration](../database/migrations/2026_10_20_132937_create_work_orders_table.php) | PK id; UK wo_number VARCHAR(50); IX(status,scheduled_at), serta indeks FK | customer_site_id → customer_sites.id NO ACTION; created_by → users.id NO ACTION; verified_by → users.id SET NULL | verified_by, scheduled_at DATETIME dan completed_at DATETIME nullable. title(150), description TEXT, service_type(30), category(50) wajib; status(30) default pending, priority(20) default medium. Origin/ticket/context/lock_version M1 belum ada. | 2 |
| work_order_technicians — [migration](../database/migrations/2026_10_20_143755_create_work_order_technicians_table.php) | PK id; UK(work_order_id,user_id); IX user_id | work_order_id → work_orders.id RESTRICT; user_id → users.id RESTRICT | Kedua FK wajib; assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP; timestamps nullable. Tidak ada interval booking. | 2 |
| work_order_assets — [migration](../database/migrations/2026_10_20_155806_create_work_order_assets_table.php) | Composite PK(work_order_id,asset_id); IX asset_id | work_order_id → work_orders.id RESTRICT; asset_id → assets.id RESTRICT | Hanya dua FK NOT NULL; tanpa surrogate id atau timestamps. | 1 |
| service_reports — [migration](../database/migrations/2026_10_20_180327_create_service_reports_table.php) | PK id; tanpa UK report/revision; IX(work_order_id,review_status), serta indeks actor | work_order_id → work_orders.id RESTRICT; created_by/reviewed_by → users.id RESTRICT | reviewed_by nullable; diagnosis/action_taken/result/recommendation/review_notes nullable TEXT; submitted_at/reviewed_at nullable TIMESTAMP. review_status VARCHAR(30) default draft. Scope, revision_no, predecessor dan snapshot M1 belum ada. | 1 |
| service_photos — [migration](../database/migrations/2026_10_20_181515_create_service_photos_table.php) | PK id; tanpa UK evidence; IX(service_report_id,phase), serta indeks uploader | service_report_id → service_reports.id RESTRICT; uploaded_by → users.id RESTRICT | phase VARCHAR(20), storage_path VARCHAR(255) dan kedua FK wajib; caption TEXT nullable. evidence_file_id M1 belum ada. | 0 |
| customer_sites — [migration](../database/migrations/2026_10_20_115400_create_customer_sites_table.php) | PK id; IX customer_id; tidak ada UK nama site | customer_id → customers.id RESTRICT | customer_id/site_name VARCHAR(150)/address TEXT wajib; pic_name(150), pic_phone(30) nullable. timezone_name belum ada. | 3 |
| assets — [migration](../database/migrations/2026_10_20_124733_create_assets_table.php) | PK id; UK asset_code VARCHAR(50); IX customer_site_id | customer_site_id → customer_sites.id NO ACTION | category(100) wajib; brand/model(100), serial_number(150), installed_at DATE nullable; status(30) default active. | 1 |
| customers_sites — **drift, tidak ada migration aktif di repository** | PK id; IX customers_id; tidak ada UK nama site | customers_id → customers.id NO ACTION | customers_id/site_name(150)/address wajib; pic_name/pic_phone dan timestamps nullable. Bukan alias customer_sites. | 0 |

Kolom, PK/UK, tipe/nullability dan indeks utama tabel yang dikelola migration cocok dengan rancangan baseline. Perbedaan literal FK pada work_orders.customer_site_id/created_by dan assets.customer_site_id dijelaskan di bawah; tidak diklaim sebagai DDL yang identik.

Database memiliki 23 tabel: 13 domain existing termasuk tiga finance yang hanya dipreservasi, sembilan infrastruktur, dan satu drift customers_sites. Sebelas nama tabel NEW M1 yang dicari belum ada: service_tickets, ticket_attachments, work_order_scope_items, work_visits, visit_bookings, material_usages, private_evidence_files, bast_documents, bast_versions, bast_items, operation_history. Tidak ditemukan naming collision pada nama tersebut di development. Ini bukan bukti schema M1 sudah diterapkan.

## 3. Drift dan disposition minimum

| ID | Bukti yang ditemukan | Dampak dan disposition |
|---|---|---|
| D1 — tabel legacy | customers_sites kosong, memakai customers_id; customer_sites aktif memiliki tiga row. FK inbound ke customers_sites tidak ditemukan dalam database yang diperiksa. View/routine/trigger juga tidak ditemukan dalam metadata yang terlihat. Pencarian app/routes/migration aktif tidak menemukan pemakaian customers_sites/customers_id. | Gunakan hanya customer_sites/customer_id untuk FK M1. Jangan rename, drop, merge data atau menganggap aman dihapus hanya karena development kosong. Inventaris dependency/integrasi dan disposition per lingkungan sebelum cleanup tersendiri; akses metadata yang terbatas tidak membuktikan tiada consumer eksternal. |
| D2 — migration ledger | Ada 22 entri applied dibanding 21 file migration repository. Semua 21 nama file aktif ada di ledger; satu entri tambahan `2026_10_20_115400_create_customers_sites_table`, batch 4, tidak memiliki file pada main. Migration customer_sites yang aktif tercatat batch 5. | Fresh install dari repository tidak menghasilkan tabel/entri tambahan tersebut. Upgrade development membawa histori berbeda. Pertahankan ledger dan file lama yang masih ada; jangan membuat migration palsu atau mengedit ledger agar terlihat parity. Catat pada inventory dan periksa staging secara terpisah. Nama ledger yang sama tidak membuktikan isi DDL historis sama. |
| D3 — literal FK action | Migration WO customer_site_id/created_by dan assets.customer_site_id memakai restrictOnDelete(); metadata aktual mencatat NO ACTION. FK lain yang diprioritaskan cocok termasuk verified_by SET NULL dan reviewed_by nullable RESTRICT. | Pada InnoDB, NO ACTION setara RESTRICT dan ditolak segera; bukan cascade tersembunyi. Catat sebagai drift literal dengan perilaku kompatibel, tidak perlu ALTER hanya untuk normalisasi nama action. Validasi ulang engine/action staging. [Referensi MySQL 8.4](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html). |

Tidak ditemukan blocker struktural baru untuk origin ticket/manual pada tabel development yang diperiksa. Ini tidak membuktikan kualitas seluruh data, absence orphan, kecepatan DDL, kompatibilitas CHECK/FK baru, atau kesiapan deployment. Tidak dilakukan orphan scan, mapping evidence legacy, audit izin storage, backup/restore atau migration rehearsal pada pemeriksaan awal ini.

## 4. Bukti yang masih diperlukan dari staging

F01 staging tetap NOT YET VERIFIED sampai tersedia bukti berikut dari lingkungan yang diidentifikasi jelas:

1. Identitas environment, commit aplikasi, versi/engine MySQL, isolation/timezone/SQL mode dan schema export read-only tanpa credentials/customer data sensitif.
2. SHOW CREATE TABLE atau metadata kolom/index/FK/CHECK tabel prioritas di atas; bandingkan tipe/sign, nullability/default, PK/UK dan actions dengan main. Sertakan tabel baru yang mungkin sudah ada agar collision terdeteksi.
3. Ledger migration dibanding file aktif, rows/dependency customers_sites serta disposition D1–D3 untuk lingkungan itu. Periksa consumer eksternal, views/routines/triggers sesuai akses yang memadai.
4. Count, orphan/duplicate/salah-site scan dan sampel legacy yang telah disanitasi. Tentukan cohort T0, provenance, writer aktif dan kasus ambigu tanpa merekayasa origin/approval/hash.
5. Sebelum expand sungguhan: rehearsal DDL persis pada salinan staging terisolasi, dampak lock/index, backup DB+file dan latihan restore. Ini pekerjaan berikutnya yang belum dieksekusi, bukan bagian pembacaan metadata saat ini.

F03 parity/regression setelah expand dan sebelum writer cutover M1 juga tetap terbuka. Full backend M0 yang dilaporkan lulus pada review sebelumnya bukan pengganti rehearsal tersebut.

## 5. Langkah terkecil Ticket → Work Order

Berikutnya susun spesifikasi/acceptance contract satu vertical slice berdasarkan Phase 0D §4–6 dan gate §8: intake ticket minimal → triage/approval → create WO dari ticket atau manual beralasan. Pertahankan ticket 0..N WO, creator/nomor WO dari server, asset satu site, scope wajib awal dan history/idempotency atomik. Retry command identik menghasilkan WO yang sama; command kedua yang sah dari ticket sama boleh menghasilkan WO kedua. Ticket remote boleh resolved tanpa WO, dan legacy_unknown hanya untuk cohort historis yang terbukti.

Tetapkan dahulu batas otorisasi, payload manual/ticket, canonical command digest dan cara menyimpan history/pointer scope. Karena desain mensyaratkan scope awal dan audit, jangan menganggap cukup menambah satu ticket_id tanpa dependency/transaction plan. Attachment privat dapat ditunda dari slice intake minimal; booking, report workflow, BAST dan finance tidak ikut dibangun di slice pertama. Finalisasi bukti F01 staging dan rencana expand nullable/cohort sebelum menjalankan migration. Tidak ada coding M1 atau migration yang dimulai pada branch dokumentasi ini.
