# HRIS MPP — PT Inovasi Eka Gemilang (Versi PHP)

Sistem **Man Power Planning (MPP), Jadwal Kerja Mingguan, Absensi Shift, dan Integrasi Order WhatsApp** berbasis **PHP & Database (SQLite / MySQL)**.

---

## 🚀 Cara Menjalankan di XAMPP

1. **Pastikan Folder Berada di `htdocs`:**
   Folder ini berada di:
   ```text
   C:\xampp\htdocs\solihin_appsrcipt\
   ```

2. **Jalankan Apache di XAMPP Control Panel:**
   - Buka aplikasi **XAMPP Control Panel**.
   - Klik tombol **Start** pada modul **Apache**.
   - *(Opsional)* Jika ingin memakai database MySQL, klik tombol **Start** pada modul **MySQL**.

3. **Buka Aplikasi di Browser:**
   Akses URL berikut:
   ```text
   http://localhost/solihin_appsrcipt/
   ```
   Atau untuk mengecek status dan pemasangan database:
   ```text
   http://localhost/solihin_appsrcipt/install.php
   ```

---

## 🔐 Akun Login Bawaan

| Role | Username | Password | Keterangan |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin` | `admin123` | Hak akses penuh (Semua menu, master data, pengaturan) |
| **Leader Shift** | `leader` | `leader123` | Akses absensi, jadwal mingguan, & share order WhatsApp |

*(Catatan: Anda dapat mengubah password atau menambah akun baru melalui menu **Pengaturan → Pengguna**).*

### Monitoring 5R

Halaman login (`/login`) punya pilihan **HRIS** atau **Monitoring 5R**. Akun 5R terpisah dari akun HRIS: masuk dengan **NIK + PIN**.

| Role 5R | NIK | PIN | Keterangan |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `123456` | Segera ganti PIN setelah login pertama (menu **Ganti PIN**) |

Pengguna, area & PIC, checklist audit, dan aturan poin diatur di **Monitoring 5R → Master Data**. Tabel 5R (`r5_*`) dibuat otomatis di database yang sama saat 5R pertama kali dibuka.

---

## 🗄️ Pilihan Database

Aplikasi ini mendukung dua jenis database melalui PDO:

### 1. SQLite (Bawaan / Default)
- **Status:** Langsung aktif tanpa konfigurasi apa pun.
- File database otomatis disimpan di `database/hris.sqlite`.
- Sangat praktis karena **tidak memerlukan MySQL dijalankan** di XAMPP.

### 2. MySQL / MariaDB (Opsional)
Bila ingin menghubungkan ke database MySQL:
1. Buka file `config.php`.
2. Ubah baris konfigurasi:
   ```php
   define('DB_DRIVER', 'mysql');
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'hris_mpp');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
3. Buka **phpMyAdmin** (`http://localhost/phpmyadmin/`), buat database baru bernama `hris_mpp`, lalu import file `database.sql`.

---

## 📁 Struktur File Sistem PHP

- **`index.php`** : Halaman utama aplikasi (SPA Shell).
- **`api.php`** : API Router yang melayani seluruh permintaan AJAX/Fetch.
- **`config.php`** : Konfigurasi database, zona waktu (WIB), dan template WhatsApp.
- **`db.php`** : Koneksi database PDO, pembuatan tabel otomatis, dan seeding data awal.
- **`auth.php`** : Modul autentikasi, token sesi 64-karakter, ganti password, dan audit log.
- **`master.php`** : Modul data bagian/divisi, operator, shift, kode jadwal, dan dashboard.
- **`absensi.php`** : Modul absensi harian per shift, BKO, lembur, dan operator pengganti.
- **`mingguan.php`** : Modul jadwal mingguan 7 hari (Senin–Minggu), kuas jadwal, validasi jam kerja & istirahat, salin minggu, dan kunci jadwal.
- **`wa.php`** : Modul Order MPP WhatsApp dengan format mention `@nomor` otomatis untuk tag akun WhatsApp dan pratinjau bubble chat.
- **`5r.php`**, **`api5r.php`**, **`lima_r.php`**, **`index5R.html`** : Monitoring 5R (halaman `/5r`, endpoint JSON, logika temuan/verifikasi/poin/audit, dan antarmuka).
- **`install.php`** : Halaman informasi status koneksi database dan tombol reset data bawaan.
- **`database.sql`** : Skema dan data awal database untuk MySQL / phpMyAdmin.
- **`App.html`**, **`Styles.html`**, **`Index.html`** : Antarmuka frontend responsif, modern, dan kompatibel ganda (Google Apps Script & PHP).

