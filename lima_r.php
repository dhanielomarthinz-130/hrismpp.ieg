<?php
/**
 * lima_r.php : Backend Monitoring 5R (port dari Code.gs Apps Script ke PHP + database HRIS).
 *
 * Data disimpan di tabel r5_* pada database yang sama dengan HRIS (SQLite / MySQL).
 * Login memakai akun & sesi HRIS (username + password). Role 5R (Admin / Auditor / PIC / Viewer)
 * otomatis dari role HRIS, dan bisa diubah per user di Master Data 5R.
 * Foto temuan disimpan lewat foto_save() ke uploads/foto (nama file acak).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/foto.php';

const R5_VERSION = '2.0';
const R5_LIST    = ['Ringkas', 'Rapi', 'Resik', 'Rawat', 'Rajin'];
const R5_TINGKAT = ['Minor', 'Mayor', 'Kritis'];
const R5_ROLES   = ['Admin', 'Auditor', 'PIC', 'Viewer'];
const R5_OPEN = 'Open', R5_WAIT = 'Menunggu Verifikasi', R5_REVISI = 'Revisi', R5_CLOSED = 'Closed', R5_BATAL = 'Batal';
const R5_FOTO_DIM = 1280;   // foto temuan disimpan WebP (ringan), sisi terpanjang maks 1280px

const R5_DEFAULT_CONFIG = [
    ['SKOR_AWAL_AREA', 100, 'Skor awal setiap tim per bulan'],
    ['POTONG_MINOR', 2, 'Potongan skor tim pemilik area untuk temuan Minor'],
    ['POTONG_MAYOR', 5, 'Potongan skor tim pemilik area untuk temuan Mayor'],
    ['POTONG_KRITIS', 10, 'Potongan skor tim pemilik area untuk temuan Kritis'],
    ['FAKTOR_BERULANG', 2, 'Pengali potongan bila temuan berulang'],
    ['HARI_BERULANG', 30, 'Rentang hari untuk menilai temuan berulang'],
    ['FAKTOR_MANDIRI', 0.5, 'Pengali potongan bila dilaporkan sendiri oleh tim area (0 = tanpa potongan)'],
    ['DEADLINE_JAM_MINOR', 72, 'Batas waktu perbaikan Minor (jam)'],
    ['DEADLINE_JAM_MAYOR', 48, 'Batas waktu perbaikan Mayor (jam)'],
    ['DEADLINE_JAM_KRITIS', 24, 'Batas waktu perbaikan Kritis (jam)'],
    ['POIN_TEPAT', 5, 'Poin untuk tim pemilik area bila perbaikan tepat waktu (Minor/Mayor)'],
    ['POIN_TEPAT_KRITIS', 10, 'Poin untuk tim pemilik area bila perbaikan Kritis tepat waktu'],
    ['BONUS_CEPAT', 2, 'Bonus bila selesai sebelum separuh batas waktu'],
    ['POTONG_TELAT_PER_HARI', 1, 'Potongan skor tim per hari keterlambatan perbaikan'],
    ['POIN_TIM_PELAPOR', 0, 'Poin untuk tim pelapor bila temuannya di area tim lain terbukti valid (0 = tidak aktif)'],
];

const R5_AREAS_SEED = [
    ['A01', 'Gudang Besar'], ['A02', 'Gudang Kecil (Bin & PL)'], ['A03', 'Gudang Kemas'],
    ['A04', 'Gudang Retur Ekspedisi'], ['A05', 'Gudang KOL & Affiliate'], ['A06', 'Meja Kerja Retur'],
    ['A07', 'Meja Kerja KOL & Affiliate'], ['A08', 'Rak Gimmick'], ['A09', 'Mesin Penempelan Gimmick'],
];

const R5_CHECK_UMUM = [
    ['Ringkas', 'Tidak ada barang/alat yang tidak diperlukan di area'],
    ['Rapi', 'Semua barang berada di lokasi yang ditentukan dan berlabel'],
    ['Resik', 'Lantai/permukaan bersih dari debu, sampah, dan sisa lakban'],
    ['Rawat', 'Standar visual (marka, label, foto kondisi ideal) terpasang dan terbaca'],
    ['Rajin', 'Checklist kebersihan harian terisi lengkap'],
];

const R5_CHECK_KHUSUS = [
    'A01' => [['Ringkas', 'Jalur hand pallet/forklift bebas hambatan'], ['Rapi', 'Palet tersusun sesuai marka lantai, tidak melewati garis'],
              ['Resik', 'Tidak ada stretch film/kardus bekas tercecer'], ['Rawat', 'Rak tidak rusak/bengkok dan label lokasi rak lengkap']],
    'A02' => [['Ringkas', 'Tidak ada campur SKU dalam satu bin'], ['Rapi', 'Label lokasi Bin & PL lengkap dan sesuai isi'],
              ['Resik', 'Bin bersih dari debu dan sobekan kardus'], ['Rawat', 'Tangga/step ladder dalam kondisi baik']],
    'A03' => [['Ringkas', 'Bahan kemas di meja hanya untuk kebutuhan shift berjalan'], ['Rapi', 'Lakban, cutter, printer label ada di posisi standar'],
              ['Resik', 'Sisa potongan kardus/bubble dibuang tiap akhir shift'], ['Rawat', 'Alat kemas bersih dan berfungsi']],
    'A04' => [['Ringkas', 'Tidak ada paket retur tertahan melewati SLA di lantai'], ['Rapi', 'Paket retur ditempatkan per ekspedisi/status'],
              ['Rapi', 'Zona karantina/barang rusak terpisah dan berlabel'], ['Resik', 'Tidak ada sisa plastik/kardus retur tercecer']],
    'A05' => [['Ringkas', 'Tidak ada sample tanpa identitas'], ['Rapi', 'Sample dikelompokkan per brand/campaign dan berlabel'],
              ['Resik', 'Rak dan lantai bersih'], ['Rawat', 'Stok sample sesuai daftar']],
    'A06' => [['Ringkas', 'Meja hanya berisi barang retur yang sedang diproses'], ['Rapi', 'Cutter, scanner, kamera di posisi standar'],
              ['Resik', 'Meja bersih dari sisa lakban/plastik'], ['Rawat', 'Kabel scanner/kamera rapi dan berfungsi']],
    'A07' => [['Ringkas', 'Meja hanya berisi order KOL yang sedang diproses'], ['Rapi', 'Alat rekam video & packing di posisi standar'],
              ['Resik', 'Meja bersih dari sisa lakban/plastik'], ['Rawat', 'Kamera/HP rekam berfungsi, kabel rapi']],
    'A08' => [['Ringkas', 'Tidak ada gimmick rusak/kedaluwarsa di rak'], ['Rapi', 'Gimmick tersusun per brand/jenis dan berlabel'],
              ['Resik', 'Rak bebas debu'], ['Rawat', 'FIFO berjalan, label tanggal terlihat']],
    'A09' => [['Ringkas', 'Tidak ada sampah liner/backing stiker di sekitar mesin'], ['Rapi', 'Gimmick belum/sudah tempel dipisah dan berlabel'],
              ['Resik', 'Mesin bersih dari sisa lem/stiker'], ['Rawat', 'Pengecekan harian mesin terisi']],
];

/* =====================================================================
 *  SKEMA & DATA AWAL
 * ===================================================================== */
function r5_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $big = $isSqlite ? 'INTEGER' : 'BIGINT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_config (
        key_name VARCHAR(50) PRIMARY KEY, value VARCHAR(100) NULL, ket TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_area (
        area_id VARCHAR(12) PRIMARY KEY, nama VARCHAR(120) NOT NULL, pic_nik VARCHAR(40) NULL,
        aktif CHAR(1) NOT NULL DEFAULT 'Y', tim VARCHAR(120) NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_users (
        nik VARCHAR(40) PRIMARY KEY, nama VARCHAR(120) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'PIC',
        area_id VARCHAR(12) NULL, pin_hash VARCHAR(255) NULL, aktif CHAR(1) NOT NULL DEFAULT 'Y')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_temuan (
        id VARCHAR(20) PRIMARY KEY, tanggal $big NOT NULL, area_id VARCHAR(12) NOT NULL, nama_area VARCHAR(120) NULL,
        lokasi VARCHAR(200) NULL, kategori_r VARCHAR(20) NULL, tingkat VARCHAR(20) NULL, deskripsi TEXT NULL,
        foto_before VARCHAR(80) NULL, pelapor_nik VARCHAR(40) NULL, pelapor_nama VARCHAR(120) NULL,
        mandiri CHAR(1) NOT NULL DEFAULT 'N', berulang CHAR(1) NOT NULL DEFAULT 'N',
        pic_nik VARCHAR(40) NULL, pic_nama VARCHAR(120) NULL, deadline $big NULL, status VARCHAR(30) NOT NULL,
        foto_after VARCHAR(80) NULL, catatan_perbaikan TEXT NULL, tgl_perbaikan $big NULL,
        verifikator_nik VARCHAR(40) NULL, verifikator_nama VARCHAR(120) NULL, tgl_verifikasi $big NULL,
        catatan_verifikasi TEXT NULL, poin_area DOUBLE NOT NULL DEFAULT 0, poin_pic DOUBLE NULL,
        revisi INT NOT NULL DEFAULT 0, pelapor_area_id VARCHAR(12) NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_poin (
        id $autoInc, ts $big NOT NULL, bulan VARCHAR(7) NOT NULL, jenis VARCHAR(10) NOT NULL,
        ref_id VARCHAR(40) NOT NULL, nama VARCHAR(120) NULL, poin DOUBLE NOT NULL DEFAULT 0,
        temuan_id VARCHAR(20) NULL, ket TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_checklist (
        id $autoInc, area_id VARCHAR(12) NOT NULL, kategori_r VARCHAR(20) NOT NULL, item VARCHAR(255) NOT NULL,
        aktif CHAR(1) NOT NULL DEFAULT 'Y')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_audit (
        audit_id VARCHAR(40) PRIMARY KEY, tanggal $big NOT NULL, area_id VARCHAR(12) NOT NULL, nama_area VARCHAR(120) NULL,
        auditor_nik VARCHAR(40) NULL, auditor_nama VARCHAR(120) NULL, ringkas DOUBLE NULL, rapi DOUBLE NULL,
        resik DOUBLE NULL, rawat DOUBLE NULL, rajin DOUBLE NULL, total_persen DOUBLE NOT NULL DEFAULT 0, catatan TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS r5_audit_detail (
        id $autoInc, audit_id VARCHAR(40) NOT NULL, kategori_r VARCHAR(20) NOT NULL, item VARCHAR(255) NULL,
        skor INT NOT NULL DEFAULT 0, catatan TEXT NULL)");

    // Config bawaan (yang belum ada saja)
    $have = array_column(db_all("SELECT key_name FROM r5_config"), 'key_name');
    foreach (R5_DEFAULT_CONFIG as $c) {
        if (!in_array($c[0], $have, true)) db_insert('r5_config', ['key_name' => $c[0], 'value' => (string)$c[1], 'ket' => $c[2]]);
    }

    // Sesi kini mengikuti sesi HRIS; akun NIK/PIN lama tidak dipakai lagi
    if (!(int)db_val("SELECT COUNT(*) FROM settings WHERE key_name = 'migr_r5_hris_login'")) {
        db_query("DELETE FROM r5_config WHERE key_name = 'SESI_JAM'");
        db_query("DELETE FROM r5_users WHERE LOWER(nik) NOT IN (SELECT LOWER(username) FROM users)");
        db_query("UPDATE r5_users SET pin_hash = NULL");
        db_insert('settings', ['key_name' => 'migr_r5_hris_login', 'value' => '1', 'ket' => 'Monitoring 5R memakai login HRIS']);
    }

    // Data awal sekali saja (penanda di settings HRIS)
    if (!(int)db_val("SELECT COUNT(*) FROM settings WHERE key_name = 'migr_r5_seed'")) {
        db_transaction(function () {
            if (!(int)db_val("SELECT COUNT(*) FROM r5_area")) {
                foreach (R5_AREAS_SEED as $a) {
                    db_insert('r5_area', ['area_id' => $a[0], 'nama' => $a[1], 'pic_nik' => '', 'aktif' => 'Y', 'tim' => $a[1]]);
                }
            }
            if (!(int)db_val("SELECT COUNT(*) FROM r5_checklist")) {
                foreach (R5_AREAS_SEED as $a) {
                    foreach (array_merge(R5_CHECK_UMUM, R5_CHECK_KHUSUS[$a[0]] ?? []) as $c) {
                        db_insert('r5_checklist', ['area_id' => $a[0], 'kategori_r' => $c[0], 'item' => $c[1], 'aktif' => 'Y']);
                    }
                }
            }
            db_insert('settings', ['key_name' => 'migr_r5_seed', 'value' => '1', 'ket' => 'Data awal Monitoring 5R']);
        });
    }
}

/* =====================================================================
 *  UTIL
 * ===================================================================== */
function r5_str($v): string { return $v === null ? '' : trim((string)$v); }
function r5_norm($s): string { return preg_replace('/\s+/', ' ', mb_strtolower(r5_str($s))); }
function r5_round1($n): float { return round((float)$n * 10) / 10; }
function r5_now(): int { return (int)floor(microtime(true) * 1000); }
function r5_fmt(?int $ms, string $f): string {
    if (!$ms) return '';
    $d = new DateTime('@' . intdiv($ms, 1000));
    $d->setTimezone(new DateTimeZone('Asia/Jakarta'));
    return $d->format($f);
}
function r5_bulan(?int $ms): string { return r5_fmt($ms, 'Y-m'); }
function r5_ms($v): ?int { return ($v === null || $v === '') ? null : (int)$v; }

function r5_cfg(): array {
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach (db_all("SELECT key_name, value FROM r5_config") as $r) $c[$r['key_name']] = $r['value'];
    }
    return $c;
}
function r5_num(string $k, float $d): float {
    $raw = r5_cfg()[$k] ?? '';
    if ($raw === '' || $raw === null || !is_numeric($raw)) return $d;
    return (float)$raw;
}

/* =====================================================================
 *  AUTH — memakai akun & sesi HRIS (satu login untuk HRIS dan 5R)
 *
 *  Tabel r5_users hanya menyimpan pengaturan 5R per user HRIS (nik = username HRIS):
 *  role 5R khusus (kosong = ikut role HRIS), tim/area utama, dan aktif di 5R.
 * ===================================================================== */

/** Role HRIS -> role 5R bawaan. */
function r5_role_map(string $hrisRole): string {
    $r = strtoupper($hrisRole);
    if ($r === 'ADMIN' || $r === ROLE_SUPER) return 'Admin';
    if ($r === 'SUPERVISOR') return 'Auditor';
    if ($r === 'LEADER') return 'PIC';
    return 'Viewer';
}

/** Gabungkan baris users (HRIS) dengan pengaturan r5_users. */
function r5_merge_user(array $h, ?array $p): array {
    $custom = $p && in_array(r5_str($p['role']), R5_ROLES, true);
    return [
        'nik'       => (string)$h['username'],
        'nama'      => r5_str($h['nama']) !== '' ? r5_str($h['nama']) : (string)$h['username'],
        'role'      => $custom ? r5_str($p['role']) : r5_role_map((string)$h['role']),
        'area_id'   => $p ? r5_str($p['area_id']) : '',
        'aktif'     => ((int)$h['aktif'] === 1 && (!$p || $p['aktif'] !== 'N')) ? 'Y' : 'N',
        'hris_role' => strtoupper((string)$h['role']) === ROLE_SUPER ? 'ADMIN' : strtoupper((string)$h['role']),
        'custom'    => $custom,
        'super'     => strtoupper((string)$h['role']) === ROLE_SUPER,
    ];
}

/** Semua user HRIS (tanpa super admin) beserta pengaturan 5R-nya. */
function r5_users_all(): array {
    $prof = [];
    foreach (db_all("SELECT * FROM r5_users") as $p) $prof[mb_strtolower($p['nik'])] = $p;
    $out = [];
    foreach (db_all("SELECT username, nama, role, aktif FROM users WHERE UPPER(role) <> ? ORDER BY nama", [ROLE_SUPER]) as $h) {
        $out[] = r5_merge_user($h, $prof[mb_strtolower($h['username'])] ?? null);
    }
    return $out;
}

function r5_find_user($nik): ?array {
    $k = mb_strtolower(r5_str($nik));
    if ($k === '') return null;
    $h = db_row("SELECT username, nama, role, aktif FROM users WHERE LOWER(username) = ?", [$k]);
    if (!$h) return null;
    return r5_merge_user($h, db_row("SELECT * FROM r5_users WHERE LOWER(nik) = ?", [$k]));
}
function r5_find_area($id): ?array {
    return db_row("SELECT * FROM r5_area WHERE area_id = ?", [r5_str($id)]);
}
/** Peta area_id -> nama tim. Tim kosong = nama area. */
function r5_timmap(?array $areas = null): array {
    $m = [];
    foreach ($areas ?? db_all("SELECT * FROM r5_area") as $a) $m[r5_str($a['area_id'])] = r5_str($a['tim']) !== '' ? r5_str($a['tim']) : r5_str($a['nama']);
    return $m;
}

/** Token = token sesi HRIS (dari login HRIS). */
function r5_auth($token, ?array $roles = null): array {
    try {
        $hu = auth_check((string)$token);
    } catch (AuthException $e) {
        throw new RuntimeException('SESI_HABIS');
    }
    if (!empty($hu['mustChange'])) throw new RuntimeException('GANTI_PASSWORD');
    $u = r5_find_user($hu['username']);
    if (!$u) throw new RuntimeException('SESI_HABIS');
    if ($u['aktif'] === 'N') throw new RuntimeException('Akun Anda tidak aktif di Monitoring 5R. Hubungi Admin 5R.');
    if ($roles && !in_array($u['role'], $roles, true)) throw new RuntimeException('Menu ini tidak tersedia untuk role ' . $u['role'] . '.');
    return $u;
}

function r5_uout(array $u, ?array $tm = null): array {
    $o = ['nik' => r5_str($u['nik']), 'nama' => r5_str($u['nama']), 'role' => r5_str($u['role']),
          'areaId' => r5_str($u['area_id']), 'aktif' => $u['aktif'] !== 'N',
          'hrisRole' => $u['hris_role'] ?? '', 'custom' => !empty($u['custom'])];
    if ($tm !== null) $o['tim'] = $tm[$o['areaId']] ?? '';
    return $o;
}

/* =====================================================================
 *  BOOTSTRAP
 * ===================================================================== */
function apiBootstrap($token): array {
    $u = r5_auth($token);
    $users = array_values(array_filter(r5_users_all(), fn($x) => $x['aktif'] !== 'N'));
    $byNik = [];
    foreach ($users as $x) $byNik[mb_strtolower($x['nik'])] = $x;
    $areaRows = db_all("SELECT * FROM r5_area ORDER BY area_id");
    $tm = r5_timmap($areaRows);
    $areas = [];
    foreach ($areaRows as $a) {
        if ($a['aktif'] === 'N') continue;
        $p = $byNik[mb_strtolower(r5_str($a['pic_nik']))] ?? null;
        $areas[] = ['id' => $a['area_id'], 'nama' => $a['nama'], 'tim' => $tm[$a['area_id']],
                    'picNik' => r5_str($a['pic_nik']), 'picNama' => $p ? $p['nama'] : ''];
    }
    $me = r5_uout($u, $tm);
    $hrow = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [mb_strtolower($u['nik'])]);
    $me['foto'] = $hrow ? foto_url(user_foto_name($hrow)) : '';
    return [
        'user'  => $me,
        'areas' => $areas,
        'users' => array_values(array_map(fn($x) => r5_uout($x, $tm), array_filter($users, fn($x) => $x['role'] !== 'Viewer'))),
        'rList' => R5_LIST, 'tingkat' => R5_TINGKAT, 'roles' => R5_ROLES,
        'config' => [
            'skorAwal' => r5_num('SKOR_AWAL_AREA', 100),
            'deadline' => ['Minor' => r5_num('DEADLINE_JAM_MINOR', 72), 'Mayor' => r5_num('DEADLINE_JAM_MAYOR', 48), 'Kritis' => r5_num('DEADLINE_JAM_KRITIS', 24)],
            'potong'   => ['Minor' => r5_num('POTONG_MINOR', 2), 'Mayor' => r5_num('POTONG_MAYOR', 5), 'Kritis' => r5_num('POTONG_KRITIS', 10)],
            'faktorMandiri' => r5_num('FAKTOR_MANDIRI', 0.5), 'faktorBerulang' => r5_num('FAKTOR_BERULANG', 2),
            'poinTepat' => r5_num('POIN_TEPAT', 5), 'poinTepatKritis' => r5_num('POIN_TEPAT_KRITIS', 10),
            'bonusCepat' => r5_num('BONUS_CEPAT', 2), 'potongTelat' => r5_num('POTONG_TELAT_PER_HARI', 1),
            'poinPelapor' => r5_num('POIN_TIM_PELAPOR', 0),
            'hariBerulang' => r5_num('HARI_BERULANG', 30),
        ],
        'bulanIni' => r5_bulan(r5_now()),
        'version'  => R5_VERSION,
    ];
}

/** Foto disajikan langsung dari uploads/foto; fungsi ini hanya cadangan (mengembalikan URL). */
function apiFoto($token, $fileId): string {
    r5_auth($token);
    $url = foto_url((string)$fileId);
    if ($url === '') throw new RuntimeException('Foto tidak ditemukan.');
    return $url;
}

/* =====================================================================
 *  TEMUAN
 * ===================================================================== */
function r5_tout(array $t, ?array $tm = null): array {
    $now = r5_now();
    $dl = r5_ms($t['deadline']);
    $tp = r5_ms($t['tgl_perbaikan']);
    $late = false;
    if ($t['status'] === R5_OPEN || $t['status'] === R5_REVISI) $late = $dl && $now > $dl;
    elseif ($t['status'] === R5_WAIT || $t['status'] === R5_CLOSED) $late = $tp && $dl && $tp > $dl;
    $aid = r5_str($t['area_id']);
    $pa = r5_str($t['pelapor_area_id']);
    return [
        'id' => $t['id'], 'tanggal' => r5_ms($t['tanggal']), 'areaId' => $aid, 'area' => r5_str($t['nama_area']),
        'lokasi' => r5_str($t['lokasi']), 'r' => r5_str($t['kategori_r']), 'tingkat' => r5_str($t['tingkat']),
        'deskripsi' => r5_str($t['deskripsi']), 'fotoBefore' => r5_str($t['foto_before']),
        'pelaporNik' => r5_str($t['pelapor_nik']), 'pelapor' => r5_str($t['pelapor_nama']),
        'mandiri' => $t['mandiri'] === 'Y', 'berulang' => $t['berulang'] === 'Y',
        'picNik' => r5_str($t['pic_nik']), 'pic' => r5_str($t['pic_nama']), 'deadline' => $dl, 'status' => $t['status'],
        'fotoAfter' => r5_str($t['foto_after']), 'catatanPerbaikan' => r5_str($t['catatan_perbaikan']), 'tglPerbaikan' => $tp,
        'verifikator' => r5_str($t['verifikator_nama']), 'tglVerifikasi' => r5_ms($t['tgl_verifikasi']),
        'catatanVerifikasi' => r5_str($t['catatan_verifikasi']),
        'poinArea' => (float)$t['poin_area'],
        'poinPic' => $t['poin_pic'] === null || $t['poin_pic'] === '' ? null : (float)$t['poin_pic'],
        'revisi' => (int)$t['revisi'], 'terlambat' => (bool)$late,
        'tim' => $tm !== null ? ($tm[$aid] ?? r5_str($t['nama_area'])) : '',
        'timPelapor' => ($tm !== null && $pa !== '') ? ($tm[$pa] ?? '') : '',
    ];
}

function r5_all_temuan(?array $tm = null): array {
    $tm = $tm ?? r5_timmap();
    return array_map(fn($t) => r5_tout($t, $tm), db_all("SELECT * FROM r5_temuan ORDER BY tanggal DESC"));
}

function r5_find_temuan($id): ?array {
    return db_row("SELECT * FROM r5_temuan WHERE id = ?", [r5_str($id)]);
}

function r5_next_id(int $now): string {
    $prefix = 'T' . r5_fmt($now, 'ym') . '-';
    $max = 0;
    foreach (db_all("SELECT id FROM r5_temuan WHERE id LIKE ?", [$prefix . '%']) as $r) {
        $max = max($max, (int)substr($r['id'], strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function r5_is_berulang(string $areaId, string $r, string $lokasi, int $now): bool {
    $lk = r5_norm($lokasi);
    if ($lk === '') return false;
    $batas = $now - (int)(r5_num('HARI_BERULANG', 30) * 86400000);
    $rows = db_all("SELECT lokasi FROM r5_temuan WHERE area_id = ? AND kategori_r = ? AND status <> ? AND tanggal >= ?",
        [$areaId, $r, R5_BATAL, $batas]);
    foreach ($rows as $x) if (r5_norm($x['lokasi']) === $lk) return true;
    return false;
}

function r5_log_poin(string $jenis, string $refId, string $nama, float $poin, string $temuanId, string $ket, ?int $when = null): void {
    $when = $when ?? r5_now();
    db_insert('r5_poin', ['ts' => $when, 'bulan' => r5_bulan($when), 'jenis' => $jenis, 'ref_id' => $refId,
        'nama' => $nama, 'poin' => $poin, 'temuan_id' => $temuanId, 'ket' => $ket]);
}

function apiCreateTemuan($token, $d): array {
    $u = r5_auth($token, ['Admin', 'Auditor', 'PIC']);
    $d = is_array($d) ? $d : [];
    $area = r5_find_area($d['areaId'] ?? '');
    if (!$area) throw new RuntimeException('Area tidak ditemukan.');
    $kat = (string)($d['kategoriR'] ?? '');
    $ting = (string)($d['tingkat'] ?? '');
    if (!in_array($kat, R5_LIST, true)) throw new RuntimeException('Pilih kategori R.');
    if (!in_array($ting, R5_TINGKAT, true)) throw new RuntimeException('Pilih tingkat temuan.');
    if (r5_str($d['deskripsi'] ?? '') === '') throw new RuntimeException('Deskripsi temuan wajib diisi.');
    if (empty($d['foto'])) throw new RuntimeException('Foto sebelum (bukti temuan) wajib diunggah.');
    $pic = r5_find_user(r5_str($d['picNik'] ?? '') !== '' ? $d['picNik'] : $area['pic_nik']);
    if (!$pic || $pic['aktif'] === 'N') throw new RuntimeException('PIC belum dipilih, atau area ini belum punya PIC.');

    $fotoId = foto_save((string)$d['foto'], R5_FOTO_DIM, true);
    try {
        return db_transaction(function () use ($u, $d, $area, $kat, $ting, $pic, $fotoId) {
            $now = r5_now();
            $id = r5_next_id($now);
            $tm = r5_timmap();
            $timArea = $tm[$area['area_id']] ?? '';
            $timPelapor = r5_str($u['area_id']) !== '' ? ($tm[$u['area_id']] ?? '') : '';
            $mandiri = $timPelapor !== '' && $timPelapor === $timArea;
            $berulang = !empty($d['berulang']) || r5_is_berulang($area['area_id'], $kat, r5_str($d['lokasi'] ?? ''), $now);
            $potong = r5_num('POTONG_' . strtoupper($ting), 2);
            $ket = [$ting];
            if ($berulang) { $potong *= r5_num('FAKTOR_BERULANG', 2); $ket[] = 'berulang'; }
            if ($mandiri) { $potong *= r5_num('FAKTOR_MANDIRI', 0.5); $ket[] = 'lapor mandiri'; }
            $potong = -r5_round1($potong);
            $deadline = $now + (int)(r5_num('DEADLINE_JAM_' . strtoupper($ting), 72) * 3600000);
            $row = [
                'id' => $id, 'tanggal' => $now, 'area_id' => $area['area_id'], 'nama_area' => $area['nama'],
                'lokasi' => mb_substr(r5_str($d['lokasi'] ?? ''), 0, 200), 'kategori_r' => $kat, 'tingkat' => $ting,
                'deskripsi' => r5_str($d['deskripsi']), 'foto_before' => $fotoId,
                'pelapor_nik' => $u['nik'], 'pelapor_nama' => $u['nama'], 'mandiri' => $mandiri ? 'Y' : 'N',
                'berulang' => $berulang ? 'Y' : 'N', 'pic_nik' => $pic['nik'], 'pic_nama' => $pic['nama'],
                'deadline' => $deadline, 'status' => R5_OPEN, 'poin_area' => $potong, 'poin_pic' => null,
                'revisi' => 0, 'pelapor_area_id' => r5_str($u['area_id']),
            ];
            db_insert('r5_temuan', $row);
            if ($potong != 0) {
                r5_log_poin('AREA', $area['area_id'], $area['nama'], $potong, $id,
                    'Temuan ' . implode(', ', $ket) . ($timPelapor !== '' && !$mandiri ? ' · dilaporkan tim ' . $timPelapor : ''), $now);
            }
            return r5_tout(r5_find_temuan($id), $tm);
        });
    } catch (Throwable $e) {
        foto_delete_file($fotoId);
        throw $e;
    }
}

function apiListTemuan($token, $f): array {
    $u = r5_auth($token);
    $f = is_array($f) ? $f : [];
    $q = r5_norm($f['q'] ?? '');
    $rows = r5_all_temuan();
    $me = mb_strtolower($u['nik']);
    $scope = (string)($f['scope'] ?? '');
    $st = (string)($f['status'] ?? '');
    $rows = array_filter($rows, function ($t) use ($f, $q, $me, $scope, $st) {
        if ($scope === 'mine' && (mb_strtolower($t['picNik']) !== $me || $t['status'] === R5_BATAL)) return false;
        if ($scope === 'verifikasi' && $t['status'] !== R5_WAIT) return false;
        if (!empty($f['bulan']) && (!$t['tanggal'] || r5_bulan($t['tanggal']) !== $f['bulan'])) return false;
        if (!empty($f['areaId']) && $t['areaId'] !== $f['areaId']) return false;
        if ($st === 'Terlambat') { if (!($t['terlambat'] && in_array($t['status'], [R5_OPEN, R5_REVISI], true))) return false; }
        elseif ($st === 'Perbaikan') { if (!in_array($t['status'], [R5_OPEN, R5_REVISI], true)) return false; }
        elseif ($st === 'Aktif') { if (!in_array($t['status'], [R5_OPEN, R5_REVISI, R5_WAIT], true)) return false; }
        elseif ($st !== '' && $t['status'] !== $st) return false;
        if (!empty($f['tim']) && $t['tim'] !== $f['tim']) return false;
        if ($q !== '' && strpos(r5_norm(implode(' ', [$t['id'], $t['area'], $t['tim'], $t['lokasi'], $t['deskripsi'], $t['pic'], $t['pelapor']])), $q) === false) return false;
        return true;
    });
    return array_slice(array_values($rows), 0, 500);
}

function apiGetTemuan($token, $id): array {
    r5_auth($token);
    $t = r5_find_temuan($id);
    if (!$t) throw new RuntimeException('Temuan tidak ditemukan.');
    return r5_tout($t, r5_timmap());
}

function apiSubmitPerbaikan($token, $id, $catatan, $foto): array {
    $u = r5_auth($token, ['Admin', 'Auditor', 'PIC']);
    if (empty($foto)) throw new RuntimeException('Foto sesudah perbaikan wajib diunggah.');
    $t0 = r5_find_temuan($id);
    if (!$t0) throw new RuntimeException('Temuan tidak ditemukan.');
    if (mb_strtolower(r5_str($t0['pic_nik'])) !== mb_strtolower($u['nik']) && $u['role'] !== 'Admin')
        throw new RuntimeException('Hanya PIC temuan ini yang bisa mengirim perbaikan.');
    if (!in_array($t0['status'], [R5_OPEN, R5_REVISI], true)) throw new RuntimeException('Status temuan sudah ' . $t0['status'] . '.');
    $fotoId = foto_save((string)$foto, R5_FOTO_DIM, true);
    try {
        return db_transaction(function () use ($id, $catatan, $fotoId) {
            $t = r5_find_temuan($id);
            if (!in_array($t['status'], [R5_OPEN, R5_REVISI], true)) throw new RuntimeException('Status temuan sudah ' . $t['status'] . '.');
            $old = r5_str($t['foto_after']);
            db_update('r5_temuan', ['foto_after' => $fotoId, 'catatan_perbaikan' => r5_str($catatan),
                'tgl_perbaikan' => r5_now(), 'status' => R5_WAIT], 'id = ?', [$t['id']]);
            if ($old !== '') foto_delete_file($old);
            return r5_tout(r5_find_temuan($id), r5_timmap());
        });
    } catch (Throwable $e) {
        foto_delete_file($fotoId);
        throw $e;
    }
}

function r5_poin_pic(array $t): array {
    $submit = (int)$t['tgl_perbaikan']; $dl = (int)$t['deadline']; $cr = (int)$t['tanggal'];
    if ($submit <= $dl) {
        $p = $t['tingkat'] === 'Kritis' ? r5_num('POIN_TEPAT_KRITIS', 10) : r5_num('POIN_TEPAT', 5);
        $ket = 'Perbaikan tepat waktu';
        if ($submit - $cr <= ($dl - $cr) / 2) { $p += r5_num('BONUS_CEPAT', 2); $ket .= ' + bonus cepat'; }
        return ['poin' => $p, 'ket' => $ket];
    }
    $hari = (int)ceil(($submit - $dl) / 86400000);
    return ['poin' => -$hari * r5_num('POTONG_TELAT_PER_HARI', 1), 'ket' => 'Perbaikan terlambat ' . $hari . ' hari'];
}

function apiVerifikasi($token, $id, $setuju, $catatan): array {
    $u = r5_auth($token, ['Admin', 'Auditor']);
    return db_transaction(function () use ($u, $id, $setuju, $catatan) {
        $t = r5_find_temuan($id);
        if (!$t) throw new RuntimeException('Temuan tidak ditemukan.');
        if ($t['status'] !== R5_WAIT) throw new RuntimeException('Temuan tidak sedang menunggu verifikasi.');
        if (mb_strtolower(r5_str($t['pic_nik'])) === mb_strtolower($u['nik'])) throw new RuntimeException('Anda tidak bisa memverifikasi perbaikan Anda sendiri.');
        $now = r5_now();
        if ($setuju) {
            $hp = r5_poin_pic($t);
            db_update('r5_temuan', ['status' => R5_CLOSED, 'verifikator_nik' => $u['nik'], 'verifikator_nama' => $u['nama'],
                'tgl_verifikasi' => $now, 'catatan_verifikasi' => r5_str($catatan), 'poin_pic' => $hp['poin']], 'id = ?', [$t['id']]);
            // Poin perbaikan masuk ke skor TIM pemilik area; baris PIC mencatat kontribusi individunya.
            r5_log_poin('AREA', $t['area_id'], r5_str($t['nama_area']), $hp['poin'], $t['id'], $hp['ket'] . ' oleh ' . r5_str($t['pic_nama']), $now);
            r5_log_poin('PIC', r5_str($t['pic_nik']), r5_str($t['pic_nama']), $hp['poin'], $t['id'], $hp['ket'], $now);
            $bonus = r5_num('POIN_TIM_PELAPOR', 0);
            $tm = r5_timmap();
            $pa = r5_str($t['pelapor_area_id']);
            if ($bonus > 0 && $pa !== '' && !empty($tm[$pa]) && $tm[$pa] !== ($tm[$t['area_id']] ?? '')) {
                $ar = r5_find_area($pa);
                r5_log_poin('AREA', $pa, $ar ? $ar['nama'] : $pa, $bonus, $t['id'], 'Temuan valid di area tim ' . ($tm[$t['area_id']] ?? ''), $now);
            }
        } else {
            if (r5_str($catatan) === '') throw new RuntimeException('Tulis alasan penolakan agar PIC tahu apa yang harus diperbaiki.');
            db_update('r5_temuan', ['status' => R5_REVISI, 'verifikator_nik' => $u['nik'], 'verifikator_nama' => $u['nama'],
                'tgl_verifikasi' => $now, 'catatan_verifikasi' => r5_str($catatan), 'revisi' => (int)$t['revisi'] + 1], 'id = ?', [$t['id']]);
        }
        return r5_tout(r5_find_temuan($id), r5_timmap());
    });
}

function apiBatalkanTemuan($token, $id, $alasan): array {
    $u = r5_auth($token, ['Admin']);
    if (r5_str($alasan) === '') throw new RuntimeException('Alasan pembatalan wajib diisi.');
    return db_transaction(function () use ($u, $id, $alasan) {
        $t = r5_find_temuan($id);
        if (!$t) throw new RuntimeException('Temuan tidak ditemukan.');
        if ($t['status'] === R5_BATAL) throw new RuntimeException('Temuan sudah dibatalkan.');
        $now = r5_now();
        $sum = [];
        foreach (db_all("SELECT * FROM r5_poin WHERE temuan_id = ?", [$t['id']]) as $p) {
            $k = $p['jenis'] . '|' . $p['ref_id'];
            if (!isset($sum[$k])) $sum[$k] = ['jenis' => $p['jenis'], 'ref' => $p['ref_id'], 'nama' => r5_str($p['nama']), 'poin' => 0.0];
            $sum[$k]['poin'] += (float)$p['poin'];
        }
        foreach ($sum as $s) {
            if (abs($s['poin']) > 1e-9) r5_log_poin($s['jenis'], $s['ref'], $s['nama'], -r5_round1($s['poin']), $t['id'], 'Pembatalan temuan oleh ' . $u['nama'], $now);
        }
        db_update('r5_temuan', ['status' => R5_BATAL, 'catatan_verifikasi' => 'DIBATALKAN: ' . r5_str($alasan),
            'verifikator_nama' => $u['nama'], 'tgl_verifikasi' => $now], 'id = ?', [$t['id']]);
        return r5_tout(r5_find_temuan($id), r5_timmap());
    });
}

/* =====================================================================
 *  DASHBOARD & PAPAN POIN
 * ===================================================================== */
function r5_audit_avg(string $bulan): array {
    $m = [];
    foreach (db_all("SELECT * FROM r5_audit") as $a) {
        if (r5_bulan((int)$a['tanggal']) !== $bulan) continue;
        $k = $a['area_id'];
        if (!isset($m[$k])) $m[$k] = ['n' => 0, 'total' => 0.0, 'r' => []];
        $m[$k]['n']++;
        $m[$k]['total'] += (float)$a['total_persen'];
    }
    return $m;
}

/** Rekap per tim untuk satu bulan. Satu tim bisa memiliki beberapa area. */
function r5_rekap_tim(string $bulan, array $areas, array $poinRows, array $temuanAll): array {
    $awal = r5_num('SKOR_AWAL_AREA', 100);
    $tm = r5_timmap($areas);
    $audit = r5_audit_avg($bulan);
    $T = [];
    $get = function (string $nama) use (&$T, $awal) {
        if (!isset($T[$nama])) $T[$nama] = ['tim' => $nama, 'areas' => [], 'skor' => $awal, 'potongan' => 0.0, 'perbaikan' => 0.0,
            'pelapor' => 0.0, 'temuan' => 0, 'aktif' => 0, 'berulang' => 0, 'mandiri' => 0, 'dilaporkan' => 0, 'auditN' => 0, 'auditSum' => 0.0];
        return $nama;
    };
    foreach ($areas as $a) if ($a['aktif'] !== 'N') { $k = $get($tm[$a['area_id']]); $T[$k]['areas'][] = $a['nama']; }
    foreach ($poinRows as $p) {
        if ($p['jenis'] !== 'AREA' || $p['bulan'] !== $bulan) continue;
        $nama = $tm[$p['ref_id']] ?? null; if (!$nama) continue;
        $k = $get($nama); $v = (float)$p['poin']; $ket = r5_str($p['ket']);
        $T[$k]['skor'] += $v;
        if (preg_match('/^Temuan valid/', $ket)) $T[$k]['pelapor'] += $v;
        elseif (preg_match('/^Perbaikan/', $ket)) $T[$k]['perbaikan'] += $v;
        else $T[$k]['potongan'] += $v;   // potongan temuan & pembatalan
    }
    foreach ($temuanAll as $t) {
        $nama = $tm[$t['areaId']] ?? null; if (!$nama) continue;
        $k = $get($nama);
        if (in_array($t['status'], [R5_OPEN, R5_REVISI], true)) $T[$k]['aktif']++;
        if (!$t['tanggal'] || r5_bulan($t['tanggal']) !== $bulan) continue;
        $T[$k]['temuan']++;
        if ($t['berulang']) $T[$k]['berulang']++;
        if ($t['mandiri']) $T[$k]['mandiri']++;
        if ($t['timPelapor'] !== '' && $t['timPelapor'] !== $nama) { $kp = $get($t['timPelapor']); $T[$kp]['dilaporkan']++; }
    }
    foreach ($audit as $aid => $x) {
        $nama = $tm[$aid] ?? null; if (!$nama) continue;
        $k = $get($nama); $T[$k]['auditN'] += $x['n']; $T[$k]['auditSum'] += $x['total'];
    }
    $out = [];
    foreach ($T as $r) {
        $r['skor'] = r5_round1($r['skor']); $r['potongan'] = r5_round1($r['potongan']);
        $r['perbaikan'] = r5_round1($r['perbaikan']); $r['pelapor'] = r5_round1($r['pelapor']);
        $r['audit'] = $r['auditN'] ? r5_round1($r['auditSum'] / $r['auditN']) : null;
        unset($r['auditSum']);
        $out[] = $r;
    }
    usort($out, fn($x, $y) => ($y['skor'] <=> $x['skor']) ?: (($y['audit'] ?? 0) <=> ($x['audit'] ?? 0)) ?: strcmp($x['tim'], $y['tim']));
    return $out;
}

function r5_leader_pic(string $bulan, array $poin, array $temuanAll): array {
    $m = [];
    foreach ($poin as $p) {
        if ($p['jenis'] !== 'PIC' || $p['bulan'] !== $bulan) continue;
        $k = mb_strtolower($p['ref_id']);
        if (!isset($m[$k])) $m[$k] = ['nik' => $p['ref_id'], 'nama' => r5_str($p['nama']), 'poin' => 0.0, 'tepat' => 0, 'telat' => 0, 'overdue' => 0];
        $m[$k]['poin'] += (float)$p['poin'];
        if (preg_match('/tepat waktu/i', (string)$p['ket'])) $m[$k]['tepat']++;
        if (preg_match('/terlambat/i', (string)$p['ket'])) $m[$k]['telat']++;
    }
    foreach ($temuanAll as $t) {
        if (!$t['terlambat'] || !in_array($t['status'], [R5_OPEN, R5_REVISI], true)) continue;
        $k = mb_strtolower($t['picNik']);
        if (!isset($m[$k])) $m[$k] = ['nik' => $t['picNik'], 'nama' => $t['pic'], 'poin' => 0.0, 'tepat' => 0, 'telat' => 0, 'overdue' => 0];
        $m[$k]['overdue']++;
    }
    $out = array_values(array_map(function ($x) { $x['poin'] = r5_round1($x['poin']); return $x; }, $m));
    usort($out, fn($a, $b) => ($b['poin'] <=> $a['poin']) ?: ($a['overdue'] <=> $b['overdue']) ?: strcmp($a['nama'], $b['nama']));
    return $out;
}

function r5_valid_bulan($b): string {
    $b = (string)$b;
    return preg_match('/^\d{4}-\d{2}$/', $b) ? $b : r5_bulan(r5_now());
}

function apiDashboard($token, $bulan): array {
    r5_auth($token);
    $bulan = r5_valid_bulan($bulan);
    $areas = db_all("SELECT * FROM r5_area");
    $tm = r5_timmap($areas);
    $all = array_values(array_filter(r5_all_temuan($tm), fn($t) => $t['status'] !== R5_BATAL));
    $bln = array_values(array_filter($all, fn($t) => $t['tanggal'] && r5_bulan($t['tanggal']) === $bulan));
    $poin = db_all("SELECT * FROM r5_poin");

    $aktif = array_values(array_filter($all, fn($t) => in_array($t['status'], [R5_OPEN, R5_REVISI], true)));
    $closedBln = array_values(array_filter($bln, fn($t) => $t['status'] === R5_CLOSED));
    $tepat = count(array_filter($closedBln, fn($t) => !$t['terlambat']));

    $byR = array_fill_keys(R5_LIST, 0);
    $byTingkat = array_fill_keys(R5_TINGKAT, 0);
    foreach ($bln as $t) {
        if (isset($byR[$t['r']])) $byR[$t['r']]++;
        if (isset($byTingkat[$t['tingkat']])) $byTingkat[$t['tingkat']]++;
    }

    // Tren 8 minggu terakhir (Senin–Minggu, WIB)
    $tz = new DateTimeZone('Asia/Jakarta');
    $senin = new DateTime('today', $tz);
    $senin->modify('-' . (((int)$senin->format('N')) - 1) . ' days');
    $seninMs = $senin->getTimestamp() * 1000;
    $tren = [];
    for ($i = 7; $i >= 0; $i--) {
        $a = $seninMs - $i * 7 * 86400000; $b = $a + 7 * 86400000;
        $tren[] = [
            'label' => r5_fmt($a, 'd/m'),
            'baru' => count(array_filter($all, fn($t) => $t['tanggal'] >= $a && $t['tanggal'] < $b)),
            'selesai' => count(array_filter($all, fn($t) => $t['status'] === R5_CLOSED && $t['tglVerifikasi'] >= $a && $t['tglVerifikasi'] < $b)),
        ];
    }

    $terlambat = array_values(array_filter($aktif, fn($t) => $t['terlambat']));
    usort($terlambat, fn($a, $b) => $a['deadline'] <=> $b['deadline']);

    return [
        'bulan' => $bulan,
        'kpi' => [
            'total' => count($bln),
            'aktif' => count($aktif),
            'menunggu' => count(array_filter($all, fn($t) => $t['status'] === R5_WAIT)),
            'terlambat' => count($terlambat),
            'closed' => count($closedBln),
            'tepatPct' => count($closedBln) ? (int)round($tepat / count($closedBln) * 100) : null,
        ],
        'byR' => $byR, 'byTingkat' => $byTingkat, 'tims' => r5_rekap_tim($bulan, $areas, $poin, $all), 'tren' => $tren,
        'terlambat' => array_slice($terlambat, 0, 10),
        'topPic' => array_slice(r5_leader_pic($bulan, $poin, $all), 0, 5),
    ];
}

function apiLeaderboard($token, $bulan): array {
    r5_auth($token);
    $bulan = r5_valid_bulan($bulan);
    $areas = db_all("SELECT * FROM r5_area");
    $tm = r5_timmap($areas);
    $poin = db_all("SELECT * FROM r5_poin");
    $all = array_values(array_filter(r5_all_temuan($tm), fn($t) => $t['status'] !== R5_BATAL));
    $riwayat = [];
    foreach (db_all("SELECT * FROM r5_poin WHERE bulan = ? ORDER BY ts DESC, id DESC LIMIT 150", [$bulan]) as $p) {
        $riwayat[] = [
            'ts' => (int)$p['ts'], 'jenis' => $p['jenis'] === 'AREA' ? 'TIM' : $p['jenis'],
            'nama' => $p['jenis'] === 'AREA' ? ($tm[$p['ref_id']] ?? r5_str($p['nama'])) : r5_str($p['nama']),
            'poin' => (float)$p['poin'], 'temuanId' => r5_str($p['temuan_id']), 'ket' => r5_str($p['ket']),
        ];
    }
    $userArea = [];
    foreach (r5_users_all() as $x) $userArea[mb_strtolower($x['nik'])] = r5_str($x['area_id']);
    $pics = array_map(function ($p) use ($userArea, $tm) {
        $aid = $userArea[mb_strtolower($p['nik'])] ?? '';
        $p['tim'] = $aid !== '' ? ($tm[$aid] ?? '') : '';
        return $p;
    }, r5_leader_pic($bulan, $poin, $all));
    return ['bulan' => $bulan, 'tims' => r5_rekap_tim($bulan, $areas, $poin, $all), 'pics' => $pics,
            'riwayat' => $riwayat, 'skorAwal' => r5_num('SKOR_AWAL_AREA', 100)];
}

/* =====================================================================
 *  AUDIT CHECKLIST
 * ===================================================================== */
function r5_checklist_rows(string $areaId, bool $onlyActive = true): array {
    $rows = db_all("SELECT kategori_r, item FROM r5_checklist WHERE area_id = ?" . ($onlyActive ? " AND aktif <> 'N'" : '') . " ORDER BY id", [$areaId]);
    $out = array_map(fn($c) => ['r' => $c['kategori_r'], 'item' => $c['item']], $rows);
    usort($out, fn($a, $b) => array_search($a['r'], R5_LIST, true) <=> array_search($b['r'], R5_LIST, true));
    return $out;
}

function apiChecklist($token, $areaId): array {
    r5_auth($token, ['Admin', 'Auditor']);
    return r5_checklist_rows(r5_str($areaId));
}

function apiSaveAudit($token, $d): array {
    $u = r5_auth($token, ['Admin', 'Auditor']);
    $d = is_array($d) ? $d : [];
    $area = r5_find_area($d['areaId'] ?? '');
    if (!$area) throw new RuntimeException('Area tidak ditemukan.');
    $items = array_values(array_filter((array)($d['items'] ?? []), fn($i) => is_array($i) && in_array($i['r'] ?? '', R5_LIST, true)));
    if (!$items) throw new RuntimeException('Checklist kosong.');
    foreach ($items as $i) {
        $s = (float)($i['skor'] ?? 0);
        if (!($s >= 1 && $s <= 5)) throw new RuntimeException('Semua item harus diberi skor 1–5.');
    }
    $now = r5_now();
    $id = 'AU' . r5_fmt($now, 'ymdHis') . '-' . $area['area_id'];
    $perR = []; $tot = 0;
    foreach ($items as $i) {
        $perR[$i['r']] = $perR[$i['r']] ?? ['s' => 0, 'n' => 0];
        $perR[$i['r']]['s'] += (int)$i['skor']; $perR[$i['r']]['n']++; $tot += (int)$i['skor'];
    }
    $row = ['audit_id' => $id, 'tanggal' => $now, 'area_id' => $area['area_id'], 'nama_area' => $area['nama'],
            'auditor_nik' => $u['nik'], 'auditor_nama' => $u['nama'],
            'total_persen' => r5_round1($tot / (count($items) * 5) * 100), 'catatan' => r5_str($d['catatan'] ?? '')];
    foreach (R5_LIST as $r) $row[strtolower($r)] = isset($perR[$r]) ? r5_round1($perR[$r]['s'] / ($perR[$r]['n'] * 5) * 100) : null;
    db_transaction(function () use ($row, $items, $id) {
        db_insert('r5_audit', $row);
        foreach ($items as $i) {
            db_insert('r5_audit_detail', ['audit_id' => $id, 'kategori_r' => $i['r'], 'item' => mb_substr(r5_str($i['item'] ?? ''), 0, 255),
                'skor' => (int)$i['skor'], 'catatan' => r5_str($i['catatan'] ?? '')]);
        }
    });
    return ['id' => $id, 'total' => $row['total_persen']];
}

function apiListAudit($token, $bulan): array {
    r5_auth($token);
    $bulan = r5_valid_bulan($bulan);
    $out = [];
    foreach (db_all("SELECT * FROM r5_audit ORDER BY tanggal DESC") as $a) {
        if (r5_bulan((int)$a['tanggal']) !== $bulan) continue;
        $o = ['id' => $a['audit_id'], 'tanggal' => (int)$a['tanggal'], 'areaId' => $a['area_id'], 'area' => r5_str($a['nama_area']),
              'auditor' => r5_str($a['auditor_nama']), 'total' => (float)$a['total_persen'], 'catatan' => r5_str($a['catatan'])];
        foreach (R5_LIST as $r) { $v = $a[strtolower($r)]; $o[$r] = $v === null || $v === '' ? null : (float)$v; }
        $out[] = $o;
    }
    return $out;
}

/* =====================================================================
 *  ADMIN
 * ===================================================================== */
function apiAdminData($token): array {
    r5_auth($token, ['Admin']);
    $tm = r5_timmap();
    $chk = [];
    foreach (db_all("SELECT area_id, kategori_r, item FROM r5_checklist WHERE aktif <> 'N' ORDER BY id") as $c) {
        $chk[$c['area_id']][] = ['r' => $c['kategori_r'], 'item' => $c['item']];
    }
    return [
        'users' => array_map(fn($u) => r5_uout($u, $tm), r5_users_all()),
        'areas' => array_map(fn($a) => ['id' => $a['area_id'], 'nama' => $a['nama'], 'tim' => r5_str($a['tim']) !== '' ? r5_str($a['tim']) : $a['nama'],
            'picNik' => r5_str($a['pic_nik']), 'aktif' => $a['aktif'] !== 'N'], db_all("SELECT * FROM r5_area ORDER BY area_id")),
        'config' => array_map(fn($c) => ['key' => $c['key_name'], 'value' => r5_str($c['value']), 'ket' => r5_str($c['ket'])],
            db_all("SELECT * FROM r5_config ORDER BY key_name")),
        'checklist' => $chk,
    ];
}

/** Simpan pengaturan 5R untuk satu user HRIS. Role '' = ikut role HRIS. */
function r5_set_profile(array $u, string $role, string $areaId, bool $aktif): void {
    $row = ['nama' => $u['nama'], 'role' => $role, 'area_id' => $areaId, 'aktif' => $aktif ? 'Y' : 'N'];
    if (db_val("SELECT COUNT(*) FROM r5_users WHERE LOWER(nik) = ?", [mb_strtolower($u['nik'])])) {
        db_update('r5_users', $row, 'LOWER(nik) = ?', [mb_strtolower($u['nik'])]);
    } else {
        db_insert('r5_users', $row + ['nik' => $u['nik'], 'pin_hash' => null]);
    }
}

/** Ubah role / tim / status 5R user HRIS. Akun baru & password dikelola di HRIS → Pengaturan → Pengguna. */
function apiSaveUser($token, $d): array {
    $me = r5_auth($token, ['Admin']);
    $d = is_array($d) ? $d : [];
    $u = r5_find_user($d['nik'] ?? '');
    if (!$u) throw new RuntimeException('User tidak ditemukan di HRIS. Tambahkan akun di HRIS → Pengaturan → Pengguna.');
    $role = r5_str($d['role'] ?? '');
    if ($role !== '' && !in_array($role, R5_ROLES, true)) throw new RuntimeException('Role tidak valid.');
    $areaId = r5_str($d['areaId'] ?? '');
    if ($areaId !== '' && !r5_find_area($areaId)) throw new RuntimeException('Area tidak valid.');
    $aktif = ($d['aktif'] ?? true) !== false;
    if (mb_strtolower($u['nik']) === mb_strtolower($me['nik']) && (!$aktif || ($role !== '' && $role !== 'Admin') || ($role === '' && r5_role_map($u['hris_role']) !== 'Admin'))) {
        throw new RuntimeException('Anda tidak bisa menurunkan role atau menonaktifkan akun Anda sendiri.');
    }
    r5_set_profile($u, $role, $areaId, $aktif);
    return r5_uout(r5_find_user($u['nik']));
}

function apiSaveArea($token, $d): bool {
    r5_auth($token, ['Admin']);
    $d = is_array($d) ? $d : [];
    $a = r5_find_area($d['id'] ?? '');
    $picNik = r5_str($d['picNik'] ?? '');
    if ($picNik !== '' && !r5_find_user($picNik)) throw new RuntimeException('PIC (username ' . $picNik . ') tidak terdaftar di HRIS.');
    if ($a) {
        $nama = r5_str($d['nama'] ?? '') !== '' ? r5_str($d['nama']) : $a['nama'];
        db_update('r5_area', ['nama' => $nama, 'pic_nik' => $picNik, 'aktif' => ($d['aktif'] ?? true) === false ? 'N' : 'Y',
            'tim' => r5_str($d['tim'] ?? '') !== '' ? r5_str($d['tim']) : $nama], 'area_id = ?', [$a['area_id']]);
    } else {
        $id = r5_str($d['id'] ?? ''); $nama = r5_str($d['nama'] ?? '');
        if ($id === '' || $nama === '') throw new RuntimeException('Kode dan nama area wajib diisi.');
        if (!preg_match('/^[A-Za-z0-9_-]{1,12}$/', $id)) throw new RuntimeException('Kode area hanya huruf/angka, maks 12 karakter.');
        db_transaction(function () use ($id, $nama, $picNik, $d) {
            db_insert('r5_area', ['area_id' => $id, 'nama' => $nama, 'pic_nik' => $picNik, 'aktif' => 'Y',
                'tim' => r5_str($d['tim'] ?? '') !== '' ? r5_str($d['tim']) : $nama]);
            // Area baru langsung punya checklist umum 5R
            foreach (R5_CHECK_UMUM as $c) db_insert('r5_checklist', ['area_id' => $id, 'kategori_r' => $c[0], 'item' => $c[1], 'aktif' => 'Y']);
        });
    }
    return true;
}

/** Ganti seluruh item checklist audit satu area. */
function apiSaveChecklist($token, $areaId, $items): array {
    r5_auth($token, ['Admin']);
    $area = r5_find_area($areaId);
    if (!$area) throw new RuntimeException('Area tidak ditemukan.');
    $clean = [];
    foreach ((array)$items as $i) {
        if (!is_array($i)) continue;
        $r = r5_str($i['r'] ?? ''); $item = r5_str($i['item'] ?? '');
        if ($item === '') continue;
        if (!in_array($r, R5_LIST, true)) throw new RuntimeException('Kategori "' . $r . '" tidak dikenal. Pakai: ' . implode(', ', R5_LIST) . '.');
        $clean[] = [$r, mb_substr($item, 0, 255)];
    }
    if (!$clean) throw new RuntimeException('Checklist minimal satu item.');
    db_transaction(function () use ($area, $clean) {
        db_delete('r5_checklist', 'area_id = ?', [$area['area_id']]);
        foreach ($clean as $c) db_insert('r5_checklist', ['area_id' => $area['area_id'], 'kategori_r' => $c[0], 'item' => $c[1], 'aktif' => 'Y']);
    });
    return r5_checklist_rows($area['area_id']);
}

/**
 * Atur tim/role 5R banyak user sekaligus (tempel dari Excel).
 * Tiap baris: Username HRIS, Kode area / nama area / nama tim, Role 5R (opsional, kosong = ikut role HRIS).
 */
function apiImportUsers($token, $rows): array {
    r5_auth($token, ['Admin']);
    $areas = db_all("SELECT * FROM r5_area");
    $tm = r5_timmap($areas);
    $findAreaId = function ($v) use ($areas, $tm) {
        $k = r5_norm($v); if ($k === '') return '';
        foreach ($areas as $x) if (r5_norm($x['area_id']) === $k || r5_norm($x['nama']) === $k) return $x['area_id'];
        foreach ($areas as $x) if (r5_norm($tm[$x['area_id']]) === $k) return $x['area_id'];
        return null;
    };
    return db_transaction(function () use ($rows, $findAreaId) {
        $seen = []; $diubah = 0; $gagal = [];
        foreach ((array)$rows as $i => $r) {
            $r = array_values((array)$r);
            $uname = r5_str($r[0] ?? '');
            if ($uname === '' && r5_str($r[1] ?? '') === '') continue;
            $no = 'Baris ' . ($i + 1);
            $u = r5_find_user($uname);
            if (!$u) { $gagal[] = $no . ': username "' . $uname . '" tidak ada di HRIS'; continue; }
            if (isset($seen[mb_strtolower($u['nik'])])) { $gagal[] = $no . ': username ' . $uname . ' ganda'; continue; }
            $areaId = $findAreaId($r[1] ?? '');
            if ($areaId === null) { $gagal[] = $no . ': area/tim "' . r5_str($r[1] ?? '') . '" tidak dikenal'; continue; }
            $roleIn = r5_str($r[2] ?? '');
            $role = '';
            if ($roleIn !== '') {
                foreach (R5_ROLES as $x) if (strtolower($x) === strtolower($roleIn)) $role = $x;
                if ($role === '') { $gagal[] = $no . ': role "' . $roleIn . '" tidak valid'; continue; }
            }
            r5_set_profile($u, $role, $areaId, true);
            $seen[mb_strtolower($u['nik'])] = 1;
            $diubah++;
        }
        return ['baru' => 0, 'diubah' => $diubah, 'gagal' => $gagal];
    });
}

function apiSaveConfig($token, $list): bool {
    r5_auth($token, ['Admin']);
    $keys = array_column(db_all("SELECT key_name FROM r5_config"), 'key_name');
    db_transaction(function () use ($list, $keys) {
        foreach ((array)$list as $c) {
            $k = (string)($c['key'] ?? '');
            if (!in_array($k, $keys, true)) continue;
            $v = $c['value'] ?? '';
            if (!is_numeric($v) || (float)$v < 0) throw new RuntimeException('Nilai ' . $k . ' harus angka ≥ 0.');
            db_update('r5_config', ['value' => (string)(float)$v], 'key_name = ?', [$k]);
        }
    });
    return true;
}

/** Daftar fungsi yang boleh dipanggil dari frontend (api5r.php). */
const R5_API = [
    'apiBootstrap', 'apiFoto', 'apiCreateTemuan', 'apiListTemuan', 'apiGetTemuan',
    'apiSubmitPerbaikan', 'apiVerifikasi', 'apiBatalkanTemuan', 'apiDashboard', 'apiLeaderboard', 'apiChecklist',
    'apiSaveAudit', 'apiListAudit', 'apiAdminData', 'apiSaveUser', 'apiSaveArea', 'apiSaveChecklist',
    'apiImportUsers', 'apiSaveConfig',
];
