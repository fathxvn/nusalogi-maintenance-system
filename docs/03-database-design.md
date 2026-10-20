# Database Design — Nusalogi Maintenance System

**Version:** 0.1  
**Database:** MySQL 8.4  
**ORM:** Laravel Eloquent  
**Status:** Draft — Pending Validation

## 1. Database Design Objectives

Database dirancang untuk menyimpan data customer, lokasi pelanggan, perangkat, pekerjaan teknisi, laporan maintenance, serta invoice secara terstruktur dan saling terhubung.

Desain menggunakan relational database dengan Primary Key, Foreign Key, dan aturan integritas data.

## 2. Master Data

### 2.1 Table: customers

Menyimpan identitas pelanggan NIS.

| Column | Data Type | Constraint |
|---|---|---|
| id | BIGINT UNSIGNED | Primary Key |
| name | VARCHAR(150) | NOT NULL |
| phone | VARCHAR(30) | NULL |
| email | VARCHAR(150) | NULL |
| billing_address | TEXT | NULL |
| created_at | TIMESTAMP | NULL |
| updated_at | TIMESTAMP | NULL |

### 2.2 Table: customer_sites

Menyimpan lokasi operasional customer.

| Column | Data Type | Constraint |
|---|---|---|
| id | BIGINT UNSIGNED | Primary Key |
| customer_id | BIGINT UNSIGNED | Foreign Key |
| site_name | VARCHAR(150) | NOT NULL |
| address | TEXT | NOT NULL |
| pic_name | VARCHAR(150) | NULL |
| pic_phone | VARCHAR(30) | NULL |
| created_at | TIMESTAMP | NULL |
| updated_at | TIMESTAMP | NULL |

**Relationship:** Satu customer dapat memiliki banyak customer_sites.

### 2.3 Table: assets

Menyimpan informasi perangkat yang terpasang di lokasi customer.

| Column | Data Type | Constraint |
|---|---|---|
| id | BIGINT UNSIGNED | Primary Key |
| customer_site_id | BIGINT UNSIGNED | Foreign Key |
| asset_code | VARCHAR(50) | UNIQUE |
| category | VARCHAR(100) | NOT NULL |
| brand | VARCHAR(100) | NULL |
| model | VARCHAR(100) | NULL |
| serial_number | VARCHAR(150) | NULL |
| status | VARCHAR(30) | NOT NULL |
| installed_at | DATE | NULL |
| created_at | TIMESTAMP | NULL |
| updated_at | TIMESTAMP | NULL |

**Relationship:** Satu lokasi customer dapat memiliki banyak perangkat.

## 3. Work Order

### 3.1 Table: work_orders

Menyimpan pekerjaan instalasi dan maintenance.

Kolom inti tahap awal:

- id (PK)
- wo_number (UNIQUE)
- customer_site_id (FK)
- created_by (FK ke users)
- title
- description
- service_type
- priority
- status
- scheduled_at
- created_at
- updated_at

Detail tabel akan disempurnakan pada tahap desain modul Work Order.

### 3.2 Table: work_order_assets

Menghubungkan pekerjaan dengan perangkat.

| Column | Data Type | Constraint |
|---|---|---|
| work_order_id | BIGINT UNSIGNED | FK |
| asset_id | BIGINT UNSIGNED | FK |

Gabungan `work_order_id` dan `asset_id` menjadi Composite Primary Key.

**Relationship:** Many-to-Many antara Work Order dan Asset.

## 4. Database Rules

1. Setiap customer memiliki ID unik.
2. Setiap customer_site harus terhubung ke customer.
3. Setiap asset harus terhubung ke satu customer_site.
4. Setiap Work Order harus merujuk ke lokasi customer yang valid.
5. Work Order dapat dibuat tanpa asset apabila berupa instalasi baru.
6. Asset yang ditambahkan ke Work Order harus berada di lokasi yang sesuai dengan Work Order.
7. Kombinasi Work Order dan Asset tidak boleh duplikat.
8. Data yang telah memiliki riwayat pekerjaan tidak boleh dihapus secara sembarangan.

## 5. Normalization

Pada desain awal, tabel work_orders hanya menyimpan `customer_site_id`, bukan menduplikasi `customer_id`.

Informasi customer diperoleh melalui hubungan:

work_orders → customer_sites → customers

Pendekatan ini bertujuan mengurangi duplikasi dan menjaga konsistensi data.

## 6. Next Entities

Tabel yang akan dirancang pada tahap selanjutnya:

- users
- work_order_technicians
- work_order_updates
- service_reports
- service_photos
- invoices
- invoice_items
- invoice_payments

## 7. Pending Decisions

- Validasi data perangkat wajib dan opsional bersama NIS.
- Aturan perpindahan aset antar lokasi.
- Kebutuhan histori perubahan lokasi aset.
- Penanganan perangkat yang belum memiliki serial number.
- Finalisasi hubungan Work Order dan Invoice.

## 8. Database Schema and Constraints

### 8.1 Master Data
- customers
- customer_sites
- assets

### 8.2 Work Order Management
- users
- work_orders
- work_order_technicians
- work_order_assets
- work_order_updates

### 8.3 Service Management
- service_reports
- service_photos

### 8.4 Invoice Management
- invoices
- invoice_items
- invoice_payments

### 8.5 Database Integrity
- Primary Key dan Foreign Key
- Unique Constraints
- Referential Integrity
- Status Validation
- Financial Data Protection