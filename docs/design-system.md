# Nusalogi — Existing Design System Reference

Referensi implementasi frontend untuk Customer, Asset, Work Order, Service Report, dan Invoice. Dokumen ini mencatat desain yang sudah digunakan, termasuk penyelarasan halaman daftar Customer pada C01; tidak memperkenalkan redesign atau fitur bisnis.

## Status dan sumber acuan

- **Implemented:** ditemukan langsung dalam kode aktif.
- **Inferred:** interpretasi pola atau panduan preservasi berdasarkan implementasi; bukan aturan baru yang telah disahkan.
- **Proposed:** usulan yang belum disetujui dan belum diterapkan.

Sumber utama adalah [app.css](../resources/css/app.css), [AuthenticatedLayout](../resources/js/layouts/authenticated-layout.tsx), [AppSidebar](../resources/js/components/app-sidebar.tsx), [Dashboard](../resources/js/pages/dashboard.tsx), [User index](../resources/js/pages/admin/users/index.tsx), [User create](../resources/js/pages/admin/users/create.tsx), dan [Customer index](../resources/js/pages/admin/customers/index.tsx). Implementasi aktif didahulukan daripada template Blade lama atau asumsi tentang tampilan default shadcn.

## 1. Design philosophy

**Implemented:** identitas menggunakan wordmark teks “Nusalogi”, subjudul “Maintenance System”, kelompok navigasi “Operasional” dan “Sistem”, serta bahasa antarmuka dominan Indonesia. Dashboard menyajikan metrik, grafik, dan tabel pekerjaan. Permukaan utama memakai border tipis, warna semantik, dan ruang antarelemen; panel operasional tidak memakai shadow sebagai pemisah utama.

**Inferred:** karakternya adalah ruang kerja internal yang tenang, ringkas, dan berorientasi keterbacaan serta koordinasi pekerjaan. Ini interpretasi visual, bukan nama filosofi resmi.

Sumber: [AppBrand dan AppSidebar](../resources/js/components/app-sidebar.tsx#L31), [Dashboard](../resources/js/pages/dashboard.tsx#L37), [.panel](../resources/css/app.css#L223).

## 2. Brand colors dan semantic tokens

**Implemented:** [root Inertia](../resources/views/app.blade.php#L14) memakai `<body class="inertia-app">`. Override `.inertia-app, .auth-page` menentukan palet Nusalogi; token netral `:root` bukan palet akhir halaman aplikasi. Utility seperti `bg-primary`, `bg-card`, `border-border`, dan `text-muted-foreground` dipetakan ke CSS variables melalui `@theme inline`.

| Token | Light Nusalogi | Dark Nusalogi |
| --- | --- | --- |
| `background` | `#ffffff` | `#151c1e` |
| `foreground` | `#1e292c` | `#e1e8e5` |
| `card`, `popover` | `#ffffff` | `#1a2425` |
| `card-foreground`, `popover-foreground` | `#1e292c` | `#e1e8e5` |
| `primary` | `#28796e` | `#8bbeb0` |
| `primary-hover` | `#21665d` | `#a9d0c4` |
| `primary-foreground` | `#ffffff` | `#132c26` |
| `secondary` | `#e9eeec` | `#263532` |
| `secondary-foreground` | `#354643` | `#d5e1db` |
| `muted` | `#edf1ef` | `#202b2c` |
| `muted-foreground` | `#627074` | `#a1b0ab` |
| `accent` | `#e5eeea` | `#293e38` |
| `accent-foreground` | `#225e56` | `#b6d9cc` |
| `border`, `sidebar-border` | `#e5e7eb` | `#33443f` |
| `input` | `#84928e` | `#758880` |
| `ring`, `sidebar-ring` | `#28796e` | `#8bbeb0` |
| `sidebar` | `#ffffff` | `#11191a` |
| `sidebar-foreground` | `#465452` | `#c4d3cc` |
| `sidebar-primary` | `#28796e` | `#8bbeb0` |
| `sidebar-primary-foreground` | `#ffffff` | `#132c26` |
| `sidebar-accent` | `#f3f4f5` | `#23382f` |
| `sidebar-accent-foreground` | `#354643` | `#b6d9cc` |

Sumber nilai: [override light](../resources/css/app.css#L132) dan [override dark](../resources/css/app.css#L163).

**Implemented — token yang tetap diwarisi:** `destructive` memakai `oklch(0.577 0.245 27.325)` pada light dan `oklch(0.704 0.191 22.216)` pada dark. `chart-1` sampai `chart-5` tetap berupa token netral, tetapi grafik Dashboard tidak menggunakan token tersebut sebagai palet distribusinya.

**Implemented — hover sesuai konteks:** tabel `hover:bg-muted/30`; sidebar `hover:bg-sidebar-accent/60`; outline button `hover:bg-muted`; banyak primary action menambahkan `hover:bg-primary-hover`. Default primitive Button masih memakai `hover:bg-primary/80`, sehingga keduanya belum seragam di seluruh aplikasi.

**Implemented — warna status:** `statusClass()` memberi amber untuk `pending`, blue untuk `assigned`, teal untuk `in_progress`, dan muted untuk nilai lainnya. Akun aktif/nonaktif memakai emerald/red secara langsung. Prioritas tinggi/sedang memakai red/amber. Warna disertai label teks dan pasangan `dark:`.

Grafik tren memakai `var(--primary)`. Grafik distribusi memakai `#627f77`, `#738d9b`, `#a18a61`, `#8b869a`, `#8f9894` berdasarkan urutan data, bukan pemetaan status tetap.

Sumber: [presentation.ts](../resources/js/lib/presentation.ts#L32), [status akun](../resources/js/pages/admin/users/index.tsx#L148), [grafik](../resources/js/components/dashboard/work-order-charts.tsx#L118).

## 3. Typography system

**Implemented:** IBM Plex Sans adalah font aktif pada `.inertia-app` dan `.auth-page`, melalui `--font-sans: var(--font-react)`. Bobot yang diimpor adalah 400, 500, dan 600. Instrument Sans masih ada pada deklarasi tema awal, tetapi scope aplikasi menimpanya. `font-heading` mengikuti `--font-sans`.

| Peran | Class atau deklarasi aktual |
| --- | --- |
| Teks dasar aplikasi | `font-size: 0.9375rem; line-height: 1.6` |
| Judul halaman H1 | `text-2xl leading-tight font-semibold tracking-[-0.025em] sm:text-[28px]` |
| Deskripsi halaman | `text-[15px] leading-6 text-muted-foreground` |
| Judul bagian | `.section-title`: `text-base font-semibold tracking-tight` |
| Nilai metrik | `text-3xl font-semibold tracking-tight tabular-nums` |
| Tabel dan kontrol form | `text-sm` |
| Header tabel, metadata | `text-xs` |
| Breadcrumb | `text-[13px]` |
| Label kelompok sidebar | `text-[11px] font-medium tracking-[0.08em] uppercase` |

Dengan root browser 16px, teks dasar adalah 15px, `text-sm` 14px, `text-xs` 12px, dan `text-base` 16px. Utility `rem` tetap mengikuti root, bukan ukuran body 15px.

Sumber: [font dan base styles](../resources/css/app.css#L4), [heading layout](../resources/js/layouts/authenticated-layout.tsx#L102), [metrik](../resources/js/pages/dashboard.tsx#L46).

**Inferred:** hierarki dibentuk melalui bobot, spacing, dan tracking moderat. Belum ada skala heading H1–H6 formal untuk semua modul.

## 4. Layout architecture

**Implemented:** halaman React menggunakan `Page.layout` dengan `AuthenticatedLayout`. Layout menyediakan document title, breadcrumb, H1, deskripsi opsional, success message, serta area konten. Halaman tidak perlu membuat ulang shell tersebut.

| Bagian | Implementasi |
| --- | --- |
| Root | `min-h-dvh bg-background` |
| Sidebar desktop | Fixed kiri, `w-64`, tampil melalui `lg:flex` |
| Kompensasi sidebar | `lg:pl-64` |
| Header | `sticky top-0 z-30`, tinggi `h-16` |
| Header dan main | `mx-auto max-w-7xl` |
| Konten | `flex flex-col gap-8 px-5 pt-6 pb-10 sm:px-8 sm:pt-8 lg:px-10` |
| Heading container | `max-w-3xl flex-col gap-2` |
| Form create/edit User | `panel flex w-full max-w-2xl flex-col gap-5` |

Dashboard menggunakan grid metrik `grid gap-4 sm:grid-cols-2 xl:grid-cols-4`, kemudian grafik `grid gap-5 xl:grid-cols-[1.4fr_1fr]`, lalu tabel terbaru. User index memakai aksi tambah, filter, tabel, dan pagination. Customer index mempertahankan panel “Daftar Customer”, tabel, dan pagination.

Sumber: [AuthenticatedLayout](../resources/js/layouts/authenticated-layout.tsx#L45), [Dashboard](../resources/js/pages/dashboard.tsx#L37), [User create](../resources/js/pages/admin/users/create.tsx#L23), [Customer index](../resources/js/pages/admin/customers/index.tsx).

## 5. Sidebar dan navigation

**Implemented:** `AppBrand`, `AppSidebar`, dan `UserMenu` adalah komponen bersama. `AppSidebar` memisahkan Operasional dan Sistem; ikon berasal dari Lucide. Item aktif memakai `bg-sidebar-accent text-sidebar-accent-foreground` dan `aria-current="page"`. Item tanpa URL menampilkan “Belum tersedia” dengan `aria-disabled="true"`.

Navigation props berasal dari middleware Inertia, termasuk URL, status aktif, dan penanda navigasi Inertia. Integrasi Customer lokal memakai route `admin.customers.index`, pola aktif `admin.customers.*`, dan akses menu untuk admin. Ini mencatat integrasi yang ada, bukan aturan akses baru untuk modul lain.

Breadcrumb saat ini adalah “Ruang kerja / {title}”; belum berupa hierarki resource bertingkat. UserMenu menampilkan inisial, nama pada `sm` ke atas, role dalam dropdown, dan aksi keluar.

Sumber: [AppSidebar](../resources/js/components/app-sidebar.tsx#L40), [middleware navigation](../app/Http/Middleware/HandleInertiaRequests.php#L70), [UserMenu](../resources/js/components/user-menu.tsx#L21).

## 6. Panel, table, form, button, dan badge

**Implemented — fondasi library:** konfigurasi [components.json](../components.json) memakai `base-nova`, neutral base, CSS variables, dan Lucide. Primitive lokal menggunakan Base UI, bukan asumsi bahwa semua komponen berasal dari Radix. Button memakai Class Variance Authority; `cn` menggabungkan class. Animasi dibantu `tw-animate-css` dan utility lokal [shadcn.css](../resources/css/shadcn.css).

| Elemen | Pola aktual |
| --- | --- |
| Panel operasional | `.panel`: `rounded-lg border border-border bg-card p-5 sm:p-6` |
| Heading bagian | `.section-title` |
| Wrapper tabel | `.table-shell`: `overflow-x-auto rounded-lg border border-border` |
| Tabel | `.soft-table`: `w-full min-w-[44rem] text-left text-sm` |
| Header tabel | `bg-muted/60 px-4 py-3 text-xs font-medium text-muted-foreground` |
| Sel tabel | `px-4 py-3 align-middle` |
| Baris tabel | `border-t border-border transition-colors hover:bg-muted/30` |
| Empty state | `.empty-state`: deklarasi `px-4 py-12 text-center text-sm text-muted-foreground` |
| Badge | `.status-badge`: `inline-flex whitespace-nowrap rounded-sm px-2 py-1 text-xs font-medium` |
| Kontrol form | `.field-control`: tinggi `h-10`, border input, semantic background, focus ring, disabled dan invalid styles |

Pada empty state di dalam `td`, selector `.soft-table td` lebih spesifik daripada `.empty-state`, sehingga padding vertikal sel mengikuti aturan tabel. Dokumen ini mencatat deklarasi dan cascade yang ada tanpa mengubahnya.

**Implemented — form:** `FormField` menyediakan label, hint opsional, dan error `role="alert"`. Input/select tetap elemen native. User create/edit memakai `useForm`, validasi inline, disabled submit, `aria-busy`, serta teks “Menyimpan…”. Filter User memakai GET eksplisit dengan `preserveState`, `preserveScroll`, dan `replace`.

**Implemented — button:** tersedia `default`, `outline`, `secondary`, `ghost`, `destructive`, dan `link`. Default primitive bertinggi `h-8`; banyak aksi halaman menambahkan `h-10 px-4 hover:bg-primary-hover`; aksi tabel memakai `h-9`. Link yang menyerupai button memakai `buttonVariants`. Destructive variant berupa background merah transparan dan teks destructive.

**Implemented — pagination User dan Customer:** metadata memakai `flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground`. Link Previous/Next memakai `buttonVariants({ variant: 'outline', size: 'sm' })` dan `preserveScroll`; ketika URL tidak tersedia, label tetap tampil sebagai Button disabled. Rentang null menggunakan `0`, sehingga dataset kosong menampilkan `0–0 dari 0` beserta halaman dari props paginator.

**Implemented — dialog dan menu:** `UserApprovalAction` dan `UserStatusAction` memakai Dialog untuk konfirmasi, pesan dampak, error inline, dan state pemrosesan. Sheet dipakai untuk navigasi mobile; DropdownMenu dipakai oleh UserMenu. Wrapper tersebut memakai portal, semantic colors, dan animasi singkat.

**Implemented — Card berbeda dari panel:** primitive Card memiliki radius `rounded-xl`, ring, dan spacing internal. Penggunaan yang ditemukan pada halaman pratinjau menambahkan override. Dashboard dan halaman operasional yang diperiksa dominan memakai `.panel`; keduanya tidak identik secara visual.

Sumber: [shared CSS](../resources/css/app.css#L214), [Button](../resources/js/components/ui/button.tsx), [FormField](../resources/js/components/form-field.tsx), [User index](../resources/js/pages/admin/users/index.tsx#L208), [Customer index](../resources/js/pages/admin/customers/index.tsx), [approval action](../resources/js/components/user-approval-action.tsx), [Card](../resources/js/components/ui/card.tsx), [pratinjau](../resources/js/pages/migration-test.tsx#L27).

## 7. Spacing dan border radius

**Implemented:** grid dan flex memakai spacing utility Tailwind. Contoh yang berulang: `gap-2` untuk field, `gap-3` untuk filter/pagination, `gap-4` untuk metrik, `gap-5` untuk form/grafik, dan `gap-8` antarbagian halaman. Padding panel `p-5 sm:p-6`; padding sel `px-4 py-3`.

Dengan root browser 16px, nilai tersebut setara 8, 12, 16, 20, dan 32px untuk gap; padding panel 20 menjadi 24px. Utility Tailwind tetap berbasis rem dan dapat mengikuti perubahan root pengguna.

Radius dasar Nusalogi adalah `--radius: 0.5rem`, menimpa default netral `0.625rem`. `@theme inline` menghitung radius berikut:

| Token | Rumus | Nilai pada root 16px |
| --- | --- | --- |
| `sm` | radius × 0.6 | 4.8px |
| `md` | radius × 0.8 | 6.4px |
| `lg` | radius | 8px |
| `xl` | radius × 1.4 | 11.2px |

Token 2xl–4xl juga tersedia, tetapi bukan ukuran panel operasional yang dominan. Avatar menggunakan `rounded-full`.

Sumber: [radius mapping](../resources/css/app.css#L54), [override radius](../resources/css/app.css#L152), [UserMenu](../resources/js/components/user-menu.tsx#L31).

## 8. Responsive behavior

**Implemented:** tidak ada override breakpoint proyek di `app.css`; penggunaan mengikuti Tailwind v4.

| Breakpoint | Perilaku yang ditemukan |
| --- | --- |
| Base | Sidebar desktop tersembunyi, hamburger membuka Sheet kiri; metrik ditumpuk |
| `sm` — 40rem | Padding konten membesar; metrik dua kolom; nama pengguna dan “Ruang kerja” tampil; footer dialog horizontal |
| `lg` — 64rem | Sidebar desktop tampil, konten bergeser; Sheet ditutup ketika viewport beralih ke desktop |
| `xl` — 80rem | Metrik empat kolom; grafik berdampingan dengan rasio 1.4:1 |

Sheet navigasi memakai lebar `min(20rem,90vw)` dengan batas `sm:max-w-sm` dari primitive. Tabel mempertahankan lebar minimum 44rem melalui scroll horizontal, bukan berubah menjadi kartu mobile. Pagination User dan Customer memakai `flex-wrap` agar metadata dan navigasi bisa berpindah baris.

Sumber: [layout dan resize handling](../resources/js/layouts/authenticated-layout.tsx#L28), [Sheet](../resources/js/components/ui/sheet.tsx), [Dashboard grid](../resources/js/pages/dashboard.tsx#L37), [table styles](../resources/css/app.css#L231).

## 9. Accessibility dan interaction conventions

**Implemented:** dokumen memakai `lang="id"`; layout memiliki skip link, main `tabIndex={-1}`, landmark navigasi berlabel, dan current-page semantics. Ikon dekoratif umumnya memakai `aria-hidden`; hamburger memiliki label dan ukuran 44px. Sidebar memiliki focus ring dan `motion-reduce:transition-none`.

Form utama menggunakan label terkait ID, `aria-invalid`, serta `aria-describedby` untuk field yang sudah dilengkapi wiring error. Success message memakai `role="status"`; error inline memakai `role="alert"`. Grafik tren menyediakan detail melalui hover dan focus keyboard, live status, serta deskripsi SVG. Status penting disertai teks, tidak hanya warna.

**Implemented — batas konsistensi saat ini:** input konfirmasi password belum memiliki wiring ARIA error selengkap password utama; target klik tidak semuanya 44px (pagination `sm` memakai `h-7`); reduced-motion handling belum terlihat merata pada semua wrapper animasi. Tabel yang diperiksa belum memiliki caption atau `scope` eksplisit. Entry Inertia tidak mengatur loader khusus; belum ada komponen skeleton atau error halaman global khusus dalam sumber React yang diperiksa.

Pernyataan di atas merupakan bukti kode, bukan sertifikasi aksesibilitas atau hasil pengujian keyboard/contrast browser.

Sumber: [root document](../resources/views/app.blade.php), [skip link dan main](../resources/js/layouts/authenticated-layout.tsx#L48), [User create](../resources/js/pages/admin/users/create.tsx), [grafik](../resources/js/components/dashboard/work-order-charts.tsx), [entry Inertia](../resources/js/app.tsx).

## 10. Light/dark theme support

**Implemented:** custom variant adalah `@custom-variant dark (&:is(.dark *));`. Palet dark Nusalogi diterapkan melalui `.dark .inertia-app`, `.inertia-app.dark`, dan pasangan `.auth-page`. Semantic colors berubah mengikuti token; badge/status juga memakai class `dark:`. Karena root Inertia berada pada body, komponen portal tetap berada dalam scope body aplikasi.

**Implemented — batas dukungan:** belum ditemukan toggle tema, penyimpanan preferensi, atau aktivasi otomatis berdasarkan preferensi sistem dalam kode aplikasi yang diperiksa. Palet distribusi grafik tetap berupa warna literal untuk kedua tema. Dukungan styling dark tidak berarti sudah ada fitur pemilih tema.

Sumber: [dark variant](../resources/css/app.css#L8), [dark overrides](../resources/css/app.css#L163), [UserMenu](../resources/js/components/user-menu.tsx), [grafik distribusi](../resources/js/components/dashboard/work-order-charts.tsx#L118).

## 11. Do's and Don'ts pengembangan frontend

**Inferred — panduan preservasi pola yang sudah ada:** tabel berikut membantu mempertahankan implementasi saat mengembangkan modul berikutnya. Ini tidak mengesahkan redesign atau fitur baru.

| Gunakan kembali | Hindari |
| --- | --- |
| AuthenticatedLayout untuk shell, H1, breadcrumb, dan flash | Menulis ulang sidebar/header per modul |
| Semantic colors dan IBM Plex Sans dalam scope aplikasi | Mengambil primary netral shadcn sebagai warna akhir Nusalogi atau mengganti font per modul |
| `.panel` dan `.section-title` untuk bagian operasional setara | Menganggap Card bawaan sama dengan panel tanpa memeriksa tampilannya |
| `.table-shell`, `.soft-table`, `.empty-state` | Membuat sistem tabel atau hover sendiri untuk daftar sejenis |
| FormField, `.field-control`, Button, buttonVariants | Menulis ulang kontrol dan tombol visual yang sudah tersedia |
| Pola pagination User/Customer, termasuk disabled dan preserveScroll | Menghilangkan kontrol di batas halaman atau mengubah tipografi metadata tanpa kebutuhan jelas |
| Label presentasi bersama dan pasangan warna light/dark | Menganggap statusClass sudah memetakan semua status domain |
| Label, focus ring, error associations, dan pesan status | Mengandalkan warna atau ikon saja untuk informasi penting |
| Grid, flex-wrap, dan tabel scroll sesuai konteks | Mengasumsikan semua halaman harus memiliki metrik atau tabel harus berubah menjadi kartu mobile |

**Proposed — belum disetujui dan tidak diterapkan pada C01:** ekstraksi pagination menjadi komponen bersama; standardisasi seluruh ukuran/hover Button; pemetaan warna status lintas domain; mekanisme pemilih tema; dan penyelarasan gap aksesibilitas yang dicatat di atas. Semua membutuhkan keputusan lingkup tersendiri. Modul bisnis yang belum tersedia tidak dianggap sudah diimplementasikan hanya karena disebut dalam dokumen ini.
