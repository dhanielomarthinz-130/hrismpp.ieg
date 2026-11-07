<?php
/**
 * db.php : Koneksi database PDO (SQLite / MySQL), inisialisasi tabel otomatis, dan query helper.
 */
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $driver = strtolower(DB_DRIVER);

    if ($driver === 'mysql') {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            init_db_tables($pdo, 'mysql');
            return $pdo;
        } catch (PDOException $e) {
            // Jika gagal menyambung ke MySQL, fallback otomatis ke SQLite agar sistem tetap berjalan
            error_log("Koneksi MySQL gagal (" . $e->getMessage() . "), beralih ke SQLite.");
            $driver = 'sqlite';
        }
    }

    if ($driver === 'sqlite') {
        $dbDir = dirname(DB_SQLITE_FILE);
        if (!is_dir($dbDir)) {
            @mkdir($dbDir, 0777, true);
        }
        $pdo = new PDO("sqlite:" . DB_SQLITE_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA busy_timeout = 5000;");
        init_db_tables($pdo, 'sqlite');
        return $pdo;
    }

    throw new RuntimeException("Driver database tidak didukung: " . DB_DRIVER);
}

/**
 * Membuat tabel dan mengisi data awal bila belum ada.
 */
function init_db_tables(PDO $pdo, string $driver): void {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    // 1. users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id $autoInc,
        username VARCHAR(50) NOT NULL UNIQUE,
        nama VARCHAR(100) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'VIEWER',
        bagian TEXT NULL,
        password_hash VARCHAR(255) NOT NULL,
        aktif TINYINT NOT NULL DEFAULT 1,
        must_change TINYINT NOT NULL DEFAULT 0,
        last_login VARCHAR(30) NULL
    );");

    // 2. user_tokens (session auth)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_tokens (
        token VARCHAR(64) PRIMARY KEY,
        user_id INT NOT NULL,
        created_at VARCHAR(30) NOT NULL,
        expires_at VARCHAR(30) NOT NULL
    );");

    // 3. bagian
    $pdo->exec("CREATE TABLE IF NOT EXISTS bagian (
        kode VARCHAR(50) PRIMARY KEY,
        nama VARCHAR(100) NOT NULL,
        target_s1 INT NOT NULL DEFAULT 0,
        target_s2 INT NOT NULL DEFAULT 0,
        urutan INT NOT NULL DEFAULT 99,
        aktif TINYINT NOT NULL DEFAULT 1,
        judul_wa VARCHAR(100) NULL,
        mention TEXT NULL,
        order_mpp TINYINT NOT NULL DEFAULT 1
    );");

    // 4. operator
    $pdo->exec("CREATE TABLE IF NOT EXISTS operator (
        id VARCHAR(30) PRIMARY KEY,
        nik VARCHAR(50) NULL,
        nama VARCHAR(100) NOT NULL,
        bagian VARCHAR(50) NOT NULL,
        grup VARCHAR(20) NOT NULL DEFAULT 'A',
        libur VARCHAR(50) NULL DEFAULT '',
        hp VARCHAR(30) NULL,
        aktif TINYINT NOT NULL DEFAULT 1,
        tgl_masuk VARCHAR(20) NULL,
        ket TEXT NULL,
        pola TINYINT NOT NULL DEFAULT 6,
        employer VARCHAR(100) NULL,
        role VARCHAR(100) NULL
    );");

    // 5. kode_jadwal
    $pdo->exec("CREATE TABLE IF NOT EXISTS kode_jadwal (
        kode VARCHAR(30) PRIMARY KEY,
        label VARCHAR(50) NOT NULL,
        nama VARCHAR(100) NOT NULL,
        mulai VARCHAR(10) NULL,
        selesai VARCHAR(10) NULL,
        jam DECIMAL(5,2) NOT NULL DEFAULT 8.00,
        kerja TINYINT NOT NULL DEFAULT 1,
        shift VARCHAR(20) NULL,
        warna VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',
        urutan INT NOT NULL DEFAULT 99
    );");

    // 6. shift
    $pdo->exec("CREATE TABLE IF NOT EXISTS shift (
        kode VARCHAR(20) PRIMARY KEY,
        nama VARCHAR(100) NOT NULL,
        mulai VARCHAR(10) NOT NULL,
        selesai VARCHAR(10) NOT NULL,
        kirim VARCHAR(10) NULL,
        urutan INT NOT NULL DEFAULT 1,
        kirim_req VARCHAR(10) NULL
    );");

    // 7. jadwal
    $pdo->exec("CREATE TABLE IF NOT EXISTS jadwal (
        id $autoInc,
        tanggal VARCHAR(20) NOT NULL,
        operator_id VARCHAR(30) NOT NULL,
        shift VARCHAR(30) NOT NULL,
        bagian_tugas VARCHAR(50) NULL,
        sumber VARCHAR(50) NULL,
        ket TEXT NULL,
        updated_at VARCHAR(30) NULL,
        updated_by VARCHAR(50) NULL,
        UNIQUE (tanggal, operator_id)
    );");

    // 8. absensi
    $pdo->exec("CREATE TABLE IF NOT EXISTS absensi (
        id $autoInc,
        tanggal VARCHAR(20) NOT NULL,
        shift VARCHAR(30) NOT NULL,
        operator_id VARCHAR(30) NOT NULL,
        status VARCHAR(10) NOT NULL,
        lembur DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        ket TEXT NULL,
        updated_at VARCHAR(30) NULL,
        updated_by VARCHAR(50) NULL,
        UNIQUE (tanggal, shift, operator_id)
    );");

    // 9. settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(50) PRIMARY KEY,
        value TEXT NULL,
        ket TEXT NULL
    );");

    // 10. order_mpp
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_mpp (
        id $autoInc,
        tanggal VARCHAR(20) NOT NULL,
        shift VARCHAR(30) NOT NULL,
        request INT NOT NULL DEFAULT 0,
        req_ids TEXT NULL,
        jam_extra TEXT NULL,
        real_ids TEXT NULL,
        real_auto TINYINT NOT NULL DEFAULT 1,
        req_sent_at VARCHAR(30) NULL,
        real_sent_at VARCHAR(30) NULL,
        updated_at VARCHAR(30) NULL,
        updated_by VARCHAR(50) NULL,
        req_submit_at VARCHAR(30) NULL,
        req_submit_by VARCHAR(50) NULL,
        real_submit_at VARCHAR(30) NULL,
        real_submit_by VARCHAR(50) NULL,
        approval_status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
        approved_at VARCHAR(30) NULL,
        approved_by VARCHAR(50) NULL,
        approval_note TEXT NULL,
        UNIQUE (tanggal, shift)
    );");

    // Auto-migrate kolom approval pada order_mpp jika belum ada di database lama
    try {
        $cols = $isSqlite
            ? array_column($pdo->query("PRAGMA table_info(order_mpp)")->fetchAll(), 'name')
            : array_column($pdo->query("SHOW COLUMNS FROM order_mpp")->fetchAll(), 'Field');
        if (!in_array('approval_status', $cols, true)) {
            $pdo->exec("ALTER TABLE order_mpp ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'DRAFT'");
        }
        if (!in_array('approved_at', $cols, true)) {
            $pdo->exec("ALTER TABLE order_mpp ADD COLUMN approved_at VARCHAR(30) NULL");
        }
        if (!in_array('approved_by', $cols, true)) {
            $pdo->exec("ALTER TABLE order_mpp ADD COLUMN approved_by VARCHAR(50) NULL");
        }
        if (!in_array('approval_note', $cols, true)) {
            $pdo->exec("ALTER TABLE order_mpp ADD COLUMN approval_note TEXT NULL");
        }
    } catch (Throwable $e) {
        // Abaikan bila sudah ada
    }

    // 11. rekap_mpp
    $pdo->exec("CREATE TABLE IF NOT EXISTS rekap_mpp (
        id $autoInc,
        tanggal VARCHAR(20) NOT NULL,
        hari VARCHAR(20) NULL,
        shift VARCHAR(30) NOT NULL,
        jam_masuk VARCHAR(50) NULL,
        request INT NOT NULL DEFAULT 0,
        terisi INT NOT NULL DEFAULT 0,
        vacant INT NOT NULL DEFAULT 0,
        hadir INT NOT NULL DEFAULT 0,
        kurang INT NOT NULL DEFAULT 0,
        persen INT NOT NULL DEFAULT 0,
        nama_request TEXT NULL,
        nama_hadir TEXT NULL,
        status_request VARCHAR(50) NULL,
        status_realisasi VARCHAR(50) NULL,
        updated_by VARCHAR(50) NULL,
        updated_at VARCHAR(30) NULL,
        UNIQUE (tanggal, shift)
    );");

    // 12. outbox_wa
    $pdo->exec("CREATE TABLE IF NOT EXISTS outbox_wa (
        id VARCHAR(50) PRIMARY KEY,
        waktu VARCHAR(30) NOT NULL,
        targets TEXT NULL,
        text TEXT NULL,
        mentions TEXT NULL,
        file_path VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ANTRE',
        served_at VARCHAR(30) NULL,
        done_at VARCHAR(30) NULL,
        detail TEXT NULL,
        ref VARCHAR(100) NULL
    );");

    // 13. log_wa
    $pdo->exec("CREATE TABLE IF NOT EXISTS log_wa (
        id $autoInc,
        waktu VARCHAR(30) NOT NULL,
        tanggal VARCHAR(20) NULL,
        shift VARCHAR(30) NULL,
        mode VARCHAR(50) NULL,
        status VARCHAR(50) NULL,
        detail TEXT NULL,
        oleh VARCHAR(50) NULL
    );");

    // 14. audit
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit (
        id $autoInc,
        waktu VARCHAR(30) NOT NULL,
        user VARCHAR(50) NULL,
        aksi VARCHAR(50) NOT NULL,
        detail TEXT NULL
    );");

    // 15. week_locks
    $pdo->exec("CREATE TABLE IF NOT EXISTS week_locks (
        monday VARCHAR(20) PRIMARY KEY,
        locked_at VARCHAR(30) NOT NULL,
        locked_by VARCHAR(50) NOT NULL
    );");

    // Seeding data awal bila tabel users kosong
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0) {
        seed_default_data($pdo);
    } else {
        // Pastikan akun Supervisor tersedia dan nama aplikasi terbaru diterapkan
        $spvExists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(username) = 'supervisor'")->fetchColumn();
        if ($spvExists === 0) {
            $stmtSpv = $pdo->prepare("INSERT INTO users (username, nama, role, bagian, password_hash, aktif, must_change) VALUES (?, ?, ?, ?, ?, 1, 0)");
            $stmtSpv->execute(['supervisor', 'Supervisor Inventory', 'SUPERVISOR', '', password_hash('spv123', PASSWORD_DEFAULT)]);
        }
        $pdo->exec("UPDATE settings SET value = 'HRIS PP Inventory PT.Inovasi Eka Gemilang' WHERE key_name = 'app_nama' AND value = 'HRIS MPP — IEG'");
    }
}

/**
 * Isi data default lengkap saat aplikasi pertama kali dijalankan.
 */
function seed_default_data(PDO $pdo): void {
    // 1. Akun Pengguna Bawaan (admin, supervisor & leader)
    $stmt = $pdo->prepare("INSERT INTO users (username, nama, role, bagian, password_hash, aktif, must_change) VALUES (?, ?, ?, ?, ?, 1, ?)");
    $stmt->execute(['admin', 'Administrator', 'ADMIN', '', password_hash('admin123', PASSWORD_DEFAULT), 0]);
    $stmt->execute(['supervisor', 'Supervisor Inventory', 'SUPERVISOR', '', password_hash('spv123', PASSWORD_DEFAULT), 0]);
    $stmt->execute(['leader', 'Leader / PIC Shift', 'LEADER', '', password_hash('leader123', PASSWORD_DEFAULT), 0]);

    // 2. Shift Bawaan
    $stmt = $pdo->prepare("INSERT INTO shift (kode, nama, mulai, selesai, kirim, urutan, kirim_req) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['S1', 'Shift 1', '08:00', '16:00', '08:30', 1, '']);
    $stmt->execute(['S2', 'Shift 2', '16:00', '00:00', '16:30', 2, '']);

    // 3. Kode Jadwal Bawaan
    $kodes = [
        ['NS', 'NS', 'Non Shift', '08:00', '17:00', 8.0, 1, 'S1', '#FFFFFF', 1],
        ['S1', 'Shift 1', 'Shift 1', '08:00', '16:00', 8.0, 1, 'S1', '#FFFFFF', 2],
        ['S2', 'Shift 2', 'Shift 2', '16:00', '00:00', 8.0, 1, 'S2', '#FFFFFF', 3],
        ['LS1', 'LS 1', 'Long Shift 1', '08:00', '20:00', 12.0, 1, 'S1', '#FFFF00', 4],
        ['LS2', 'LS 2', 'Long Shift 2', '12:00', '00:00', 12.0, 1, 'S2', '#FFFF00', 5],
        ['HALF', 'HALF', 'Half Day', '08:00', '12:00', 4.0, 1, 'S1', '#FCE4D6', 6],
        ['OFF', 'OFF', 'Libur', '', '', 0.0, 0, '', '#F4CCCC', 7],
        ['CUTI', 'CUTI', 'Cuti', '', '', 0.0, 0, '', '#FFF2CC', 8]
    ];
    $stmt = $pdo->prepare("INSERT INTO kode_jadwal (kode, label, nama, mulai, selesai, jam, kerja, shift, warna, urutan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($kodes as $k) {
        $stmt->execute($k);
    }

    // 4. Bagian / Divisi Bawaan
    $bagians = [
        ['INBOUND', 'Inbound', 10, 10, 1, 1, 'INBOUND', '', 1],
        ['REPLENISH', 'Replenish', 5, 5, 2, 1, 'REPLENISH', '', 1],
        ['RETURN', 'Return', 8, 8, 3, 1, 'RETURN', '', 1],
        ['ADMIN CS RETURN', 'Admin CS Return', 2, 2, 4, 1, 'ADMIN CS RETURN', '', 1],
        ['KOL', 'KOL', 4, 4, 5, 1, 'KOL', '', 1],
        ['PICKING', 'Picking', 15, 15, 6, 1, 'PICKING', '', 1],
        ['PACKING', 'Packing', 15, 15, 7, 1, 'PACKING', '', 1]
    ];
    $stmt = $pdo->prepare("INSERT INTO bagian (kode, nama, target_s1, target_s2, urutan, aktif, judul_wa, mention, order_mpp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($bagians as $b) {
        $stmt->execute($b);
    }

    // 5. Operator Bawaan
    $ops = [
        ['OP0001', '012', 'CHAIRUL BAKTI', 'INBOUND', 'S2', '6', '628120000001', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'],
        ['OP0002', '011', 'MUHAMMAD ISMAIL', 'INBOUND', 'S1', '6', '628120000002', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'],
        ['OP0003', '029', 'SAFRUL HUDA', 'INBOUND', 'A', '6,0', '628120000003', 1, 6, 'Rolling A · 6 hari', 'Rolling A'],
        ['OP0004', '001', 'DAPIT PRATAMA', 'RETURN', 'S2', '6', '628120000004', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'],
        ['OP0005', '005', 'DIMAS ANGGA SETIAWAN', 'RETURN', 'S2', '6', '628120000005', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'],
        ['OP0006', '004', 'MUHAMMAD RAIHAN PERMANA', 'RETURN', 'S1', '6', '628120000006', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'],
        ['OP0007', '003', 'SYAIFUL ANWAR', 'RETURN', 'S1', '6', '628120000007', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'],
        ['OP0008', '006', 'AMI SARMITA', 'ADMIN CS RETURN', 'S1', '6', '628120000008', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'],
        ['OP0009', '002', 'MUHAMAD FIKRI PRATAMA', 'RETURN', 'S2', '6', '628120000009', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'],
        ['OP0010', '010', 'AGUS PALENTINO', 'REPLENISH', 'S1', '6', '628120000010', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'],
        ['OP0011', '', 'VACANT', 'INBOUND', 'S1', '6', '', 1, 6, '', 'Tetap Shift 1'],
        ['OP0012', '', 'VACANT', 'INBOUND', 'S2', '6', '', 1, 6, '', 'Tetap Shift 2']
    ];
    $stmt = $pdo->prepare("INSERT INTO operator (id, nik, nama, bagian, grup, libur, hp, aktif, pola, ket, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($ops as $o) {
        $stmt->execute($o);
    }

    // 6. Settings Bawaan
    $monday = date('Y-m-d', strtotime('monday this week'));
    $settings = [
        ['app_nama', 'HRIS PP Inventory PT.Inovasi Eka Gemilang', 'Nama aplikasi di header'],
        ['max_jam_minggu', '49', 'Batas jam kerja per 7 hari berjalan'],
        ['min_istirahat', '8', 'Jeda istirahat minimal antar shift (jam)'],
        ['batas_mode', 'MINGGU', 'Batas jam per Senin-Minggu atau Berjalan'],
        ['pola6_half', '1', 'Pola 6 hari: 5 hari penuh + HALF sehari sebelum libur'],
        ['rotasi_anchor', $monday, 'Senin acuan rotasi shift'],
        ['rotasi_periode', '1', 'Periode pertukaran rotasi (minggu)'],
        ['wa_auto', '0', 'Auto send pesan WhatsApp'],
        ['wa_provider', 'MANUAL', 'Provider WhatsApp (MANUAL / BOT / FONNTE / WEBHOOK)'],
        ['wa_tim', 'Inventory', 'Nama tim di pesan request & judul tabel'],
        ['wa_judul', 'INVENTORY LPK RKA', 'Judul di pesan realisasi absensi'],
        ['wa_pic', "Pak | Erfan RKA | 6281292716771", 'PIC Vendor WhatsApp'],
        ['wa_mention', "Pak | Pak Jonathan EJI | 6282125659616\nPak | Muhamad Solihin | 6282114409132\nPak | Dhanielo Imsula Siwalette IEG | 6282210703118\nBu | Bu Kristi IEG | 6287878733743", 'Akun CC WhatsApp'],
        ['wa_tpl_req', DEFAULT_TPL_REQ, 'Template pesan Request MP'],
        ['wa_tpl_real', DEFAULT_TPL_REAL, 'Template pesan Realisasi absensi'],
        ['wa_ket_full', 'Terfullfill', 'Keterangan realisasi bila hadir >= request'],
        ['wa_ket_kurang', 'Kurang {selisih} Mp', 'Keterangan realisasi bila hadir < request'],
        ['wa_vacant', 'VACANT', 'Pengisi nama request yang belum ada orangnya'],
        ['wa_lampiran', 'GAMBAR', 'Lampiran pesan request (GAMBAR / EXCEL / TIDAK)']
    ];
    $stmt = $pdo->prepare("INSERT INTO settings (key_name, value, ket) VALUES (?, ?, ?)");
    foreach ($settings as $s) {
        $stmt->execute($s);
    }
}

/* ============================== QUERY HELPERS ============================== */

function db_query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_all(string $sql, array $params = []): array {
    return db_query($sql, $params)->fetchAll();
}

function db_row(string $sql, array $params = []): ?array {
    $row = db_query($sql, $params)->fetch();
    return $row ?: null;
}

function db_val(string $sql, array $params = [], $default = null) {
    $val = db_query($sql, $params)->fetchColumn();
    return $val !== false ? $val : $default;
}

function db_insert(string $table, array $data): int|string {
    $keys = array_keys($data);
    $fields = implode(', ', $keys);
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
    db_query($sql, array_values($data));
    return db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int {
    $sets = [];
    $params = [];
    foreach ($data as $k => $v) {
        $sets[] = "$k = ?";
        $params[] = $v;
    }
    $setString = implode(', ', $sets);
    $sql = "UPDATE $table SET $setString WHERE $where";
    $stmt = db_query($sql, array_merge($params, $whereParams));
    return $stmt->rowCount();
}

function db_delete(string $table, string $where, array $whereParams = []): int {
    $sql = "DELETE FROM $table WHERE $where";
    $stmt = db_query($sql, $whereParams);
    return $stmt->rowCount();
}

function db_transaction(callable $callback) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $res = $callback($pdo);
        $pdo->commit();
        return $res;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

