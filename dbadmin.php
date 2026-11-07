<?php
/**
 * dbadmin.php : Penjelajah database di menu Maintenance (KHUSUS SUPER ADMIN).
 * - Hanya tabel yang terdaftar di DBA_TABLES (nama tabel tidak pernah diambil mentah dari request).
 * - Kolom sensitif disamarkan.
 * - Akun super admin & sesi super admin yang sedang dipakai tidak bisa terhapus.
 */
require_once __DIR__ . '/db.php';

const DBA_TABLES = [
    'users'          => 'Akun pengguna',
    'user_tokens'    => 'Sesi login aktif',
    'login_attempts' => 'Percobaan login gagal',
    'operator'       => 'Data operator / karyawan',
    'bagian'         => 'Bagian / divisi',
    'shift'          => 'Shift',
    'kode_jadwal'    => 'Kode jadwal',
    'jadwal'         => 'Jadwal harian',
    'absensi'        => 'Absensi',
    'order_mpp'      => 'Request OS',
    'rekap_mpp'      => 'Rekap MPP',
    'week_locks'     => 'Kunci minggu',
    'outbox_wa'      => 'Antrean WhatsApp',
    'log_wa'         => 'Log WhatsApp',
    'audit'          => 'Audit log',
    'settings'       => 'Pengaturan sistem',
];

const DBA_MASK_COLS = ['password_hash', 'token'];
const DBA_MASK_SETTINGS = ['wa_token', 'bot_token'];

function dba_table_(string $t): string {
    $t = strtolower(trim($t));
    if (!array_key_exists($t, DBA_TABLES)) throw new InvalidArgumentException('Tabel tidak dikenal.');
    return $t;
}

function dba_is_sqlite_(): bool {
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** Kolom & primary key tabel (dibaca dari skema, bukan dari request). */
function dba_schema_(string $t): array {
    $cols = []; $pk = null;
    if (dba_is_sqlite_()) {
        foreach (db()->query("PRAGMA table_info($t)")->fetchAll() as $c) {
            $cols[] = $c['name'];
            if ((int)$c['pk'] === 1 && $pk === null) $pk = $c['name'];
        }
    } else {
        foreach (db()->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) {
            $cols[] = $c['Field'];
            if ($c['Key'] === 'PRI' && $pk === null) $pk = $c['Field'];
        }
    }
    return ['cols' => $cols, 'pk' => $pk ?? ($cols[0] ?? 'id')];
}

function dba_q_(string $ident): string {
    return dba_is_sqlite_() ? '"' . $ident . '"' : '`' . $ident . '`';
}

function dba_tables(array $u): array {
    require_super($u);
    $out = [];
    foreach (DBA_TABLES as $t => $label) {
        $out[] = ['table' => $t, 'label' => $label, 'rows' => (int)db_val("SELECT COUNT(*) FROM $t", [], 0)];
    }
    return $out;
}

function dba_rows(array $p, array $u): array {
    require_super($u);
    $t = dba_table_((string)($p['table'] ?? ''));
    $sc = dba_schema_($t);
    $per = 50;
    $page = max(1, (int)($p['page'] ?? 1));
    $total = (int)db_val("SELECT COUNT(*) FROM $t", [], 0);
    $pk = dba_q_($sc['pk']);
    $rows = db_all("SELECT * FROM $t ORDER BY $pk DESC LIMIT $per OFFSET " . (($page - 1) * $per));

    $superNames = super_usernames();
    foreach ($rows as &$r) {
        foreach (DBA_MASK_COLS as $c) {
            if (isset($r[$c]) && $r[$c] !== '') $r[$c] = '•••••• (disamarkan)';
        }
        if ($t === 'settings' && in_array($r['key_name'] ?? '', DBA_MASK_SETTINGS, true) && ($r['value'] ?? '') !== '') {
            $r['value'] = '•••••• (disamarkan)';
        }
        // Baris yang dilindungi tidak menampilkan tombol hapus
        $r['__locked'] = ($t === 'users' && strtoupper((string)($r['role'] ?? '')) === ROLE_SUPER);
        if ($t === 'users' && $r['__locked']) $r['role'] = 'ADMIN'; // tetap tersembunyi
    }
    unset($r);
    return ['table' => $t, 'label' => DBA_TABLES[$t], 'pk' => $sc['pk'], 'cols' => $sc['cols'],
            'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
}

/** Hapus file foto milik baris operator/user yang akan dihapus. */
function dba_cleanup_files_(string $t, array $rows): void {
    if ($t !== 'operator' && $t !== 'users') return;
    foreach ($rows as $r) foto_delete_file($r['foto'] ?? '');
}

function dba_delete_row(array $p, array $u, string $currentToken): array {
    require_super($u);
    $t = dba_table_((string)($p['table'] ?? ''));
    $sc = dba_schema_($t);
    $id = (string)($p['id'] ?? '');
    if ($id === '') throw new InvalidArgumentException('Baris tidak valid.');
    $pk = dba_q_($sc['pk']);
    $row = db_row("SELECT * FROM $t WHERE $pk = ?", [$id]);
    if (!$row) throw new RuntimeException('Baris tidak ditemukan (mungkin sudah dihapus).');

    if ($t === 'users') {
        if (strtoupper((string)$row['role']) === ROLE_SUPER) throw new RuntimeException('Akun ini dilindungi dan tidak dapat dihapus.');
        db_query("DELETE FROM user_tokens WHERE user_id = ?", [$row['id']]);
    }
    if ($t === 'user_tokens' && hash_equals((string)$row['token'], $currentToken)) {
        throw new RuntimeException('Tidak dapat menghapus sesi yang sedang Anda pakai.');
    }
    if ($t === 'operator') db_update('users', ['operator_id' => ''], 'operator_id = ?', [$id]);

    dba_cleanup_files_($t, [$row]);
    db_query("DELETE FROM $t WHERE $pk = ?", [$id]);
    audit_log($u['username'], 'DB_HAPUS_BARIS', "$t #" . mb_substr($id, 0, 40));
    return ['ok' => true];
}

function dba_truncate(array $p, array $u, string $currentToken): array {
    require_super($u);
    $t = dba_table_((string)($p['table'] ?? ''));
    if (strtolower(trim((string)($p['confirm'] ?? ''))) !== $t) {
        throw new InvalidArgumentException('Ketik nama tabel "' . $t . '" untuk konfirmasi.');
    }
    $before = (int)db_val("SELECT COUNT(*) FROM $t", [], 0);

    if ($t === 'users') {
        // Semua akun kecuali super admin
        $victims = db_all("SELECT * FROM users WHERE UPPER(role) <> ?", [ROLE_SUPER]);
        dba_cleanup_files_($t, $victims);
        db_query("DELETE FROM user_tokens WHERE user_id IN (SELECT id FROM users WHERE UPPER(role) <> ?)", [ROLE_SUPER]);
        db_query("DELETE FROM users WHERE UPPER(role) <> ?", [ROLE_SUPER]);
    } elseif ($t === 'user_tokens') {
        // Semua sesi kecuali sesi yang sedang dipakai
        db_query("DELETE FROM user_tokens WHERE token <> ?", [$currentToken]);
    } else {
        if ($t === 'operator') {
            dba_cleanup_files_($t, db_all("SELECT foto FROM operator"));
            db_query("UPDATE users SET operator_id = '' WHERE operator_id IS NOT NULL");
        }
        db_query("DELETE FROM $t");
    }
    $after = (int)db_val("SELECT COUNT(*) FROM $t", [], 0);
    audit_log($u['username'], 'DB_KOSONGKAN_TABEL', "$t (" . ($before - $after) . " baris)");
    return ['ok' => true, 'deleted' => $before - $after];
}
