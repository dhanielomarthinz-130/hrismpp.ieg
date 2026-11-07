<?php
/**
 * HRIS MPP — PT Inovasi Eka Gemilang
 * config.php : Konfigurasi database, konstanta, template WhatsApp, dan pengaturan aplikasi.
 */

// Set zona waktu ke Waktu Indonesia Barat (WIB)
date_default_timezone_set('Asia/Jakarta');

// Override khusus server (tidak di-commit ke git): salin config.local.example.php -> config.local.php
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

define('APP_NAME', 'HRIS Inventory PT.Inovasi Eka Gemilang');
define('APP_VERSION', '2.0.0');

/**
 * Konfigurasi Database:
 * Pilihan DB_DRIVER:
 * - 'sqlite' : Sangat praktis, file-based, berjalan otomatis di XAMPP tanpa perlu start MySQL. (Default)
 * - 'mysql'  : Menghubungkan ke MySQL / MariaDB (XAMPP localhost).
 */
defined('DB_DRIVER') || define('DB_DRIVER', 'sqlite'); // 'sqlite' atau 'mysql'

// Pengaturan SQLite
defined('DB_SQLITE_FILE') || define('DB_SQLITE_FILE', __DIR__ . '/database/hris.sqlite');

// Pengaturan MySQL (bila DB_DRIVER = 'mysql')
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'hris_mpp');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// Kunci URL masuk saat maintenance: /maintenance/<kunci> (tidak peka huruf besar/kecil)
defined('MAINTENANCE_KEY') || define('MAINTENANCE_KEY', 'daniel');

// Session Lifetime (detik) - default 12 jam
define('SESSION_LIFETIME', 43200);

// Template WhatsApp Request MP bawaan
define('DEFAULT_TPL_REQ', "Selamat {Salam} {pic} berikut kami informasikan terkait Request MP untuk {tim} {shift} Tanggal {d} {bulan}-{yyyy}. untuk jam kerjanya Mulai *{jam_kerja}*. dan berikut kami lampirkan request Namanya.\n\n{nama_tab}\n\nCc : {cc}");

// Template WhatsApp Realisasi Absensi bawaan
define('DEFAULT_TPL_REAL', "Selamat {salam} bpk/ibu berikut :\nList absensi {judul} {shift_kecil} ({mulai}-{selesai})\nHari {hari} {dd} {bulan} {yyyy}\nRequest : {request} Mp\nHadir : {hadir} Mp\nKeterangan : {keterangan}\nBerikut nama- namanya : \n\n{nama}\n\n\nTerima kasih\n\n{mention}");

