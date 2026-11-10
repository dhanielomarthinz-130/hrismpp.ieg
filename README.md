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

### Monitoring 6S

Kategori Lean 6S: **Sort** (Seiri), **Set in Order** (Seiton), **Shine** (Seiso), **Standardize** (Seiketsu), **Sustain** (Shitsuke), dan **Safety** (Keselamatan). Data 5R lama otomatis dikonversi (Ringkas → Sort, Rapi → Set in Order, Resik → Shine, Rawat → Standardize, Rajin → Sustain). Alamat lama `/5r` (termasuk QR yang sudah dicetak) otomatis dialihkan ke `/6s`.

Satu akun untuk dua aplikasi: di halaman login (`/login`) pilih **HRIS** atau **Monitoring 6S**, lalu masuk dengan **username + password HRIS** yang sama. Dari sidebar HRIS ada tautan **Monitoring 6S**, dan dari 6S ada menu **Buka HRIS** — tanpa login ulang.

Role 6S bawaan mengikuti role HRIS: Admin → Admin, Supervisor → Auditor, Leader → PIC, Viewer → Viewer. Admin 6S bisa mengubah role, tim/area, dan status aktif 6S per user.

**Pembagian tugas.** Diatur dengan aturan `TUGAS_OTOMATIS` di Master Data → Aturan poin.

**Mode bawaan: ambil bebas (`TUGAS_OTOMATIS` = 0).** Semua Leader / PIC berhak melakukan 6S dan tidak terikat ke gudang mana pun.

- **Temuan baru**: masuk ke **Tugas Saya → Tersedia untuk diambil**.
- **Mengambil**: PIC menekan **Ambil tugas** dan menentukan target. Temuan lalu terkunci dan tampil **"Sedang diproses oleh …"**. Kalau dua orang mengambil bersamaan, hanya satu yang berhasil.
- **Langsung beres**: PIC bisa langsung mengirim foto perbaikan. Temuan otomatis tercatat diproses olehnya.
- **Batalkan proses**: keterangan wajib diisi dan tercatat di riwayat. Temuan kembali ke daftar Tersedia.
- **Poin**: penemu +`POIN_LAPOR_PIC`. Yang menyelesaikan mendapat poin tepat waktu. Makin banyak yang diproses, makin banyak poinnya.

**Mode otomatis per shift (`TUGAS_OTOMATIS` = 1):**

1. **Lapor**: pelapor memilih area. Sistem memilih Leader / PIC area yang **sedang bertugas** menurut jadwal shift HRIS dan melewati yang libur atau tidak masuk di absensi. Bila belum ada yang bertugas, dipilih PIC dengan shift berikutnya, lalu PIC cadangan area. Pelapor tetap boleh memilih PIC lain.
2. **Terima & tentukan target**: PIC menerima tugas dalam `SLA_RESPON_JAM` (dihitung sejak shift-nya mulai) dan menentukan target selesai sendiri. Target yang melewati batas standar tingkat temuan wajib diberi alasan. Temuan Kritis tidak boleh melewati batas standar.
3. **Tidak direspon**: PIC dipotong `POTONG_TIDAK_RESPON`, lalu tugas otomatis dialihkan ke PIC area lain yang sedang bertugas.
4. **Perbaiki → verifikasi**: alurnya sama seperti sebelumnya.
   - Tepat waktu diukur terhadap target PIC.
   - Poin tim penuh hanya bila selesai dalam batas standar; setengahnya bila lewat standar tetapi masih sesuai target.

PIC dan auditor bisa mengalihkan tugas dengan alasan. Area tanggung jawab tiap Leader diatur di **Master Data → Pengguna**. Akun Leader harus ditautkan ke karyawan IEG di **HRIS → Pengaturan → Pengguna** supaya jadwalnya terbaca.

Tim/role pengguna, area & PIC, checklist audit, dan aturan poin diatur di **Monitoring 6S → Master Data**; akun baru & password tetap di **HRIS → Pengaturan → Pengguna**. Tabel 6S (nama lama `r5_*` dipertahankan) dibuat otomatis di database yang sama saat 6S pertama kali dibuka.

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
- **`6s.php`**, **`api6s.php`**, **`lima_r.php`**, **`App6S.html`** : Monitoring 6S (halaman `/6s`, endpoint JSON, logika temuan/verifikasi/poin/audit, dan antarmuka).
- **`install.php`** : Halaman informasi status koneksi database dan tombol reset data bawaan.
- **`database.sql`** : Skema dan data awal database untuk MySQL / phpMyAdmin.
- **`App.html`**, **`Styles.html`**, **`Index.html`** : Antarmuka frontend responsif, modern, dan kompatibel ganda (Google Apps Script & PHP).

