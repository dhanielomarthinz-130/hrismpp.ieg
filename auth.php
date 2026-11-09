<?php
/**
 * auth.php : Otentikasi pengguna, token sesi, ganti password, kelola user, dan audit trail.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/foto.php';

// Peran yang boleh dipilih/dikelola lewat menu Pengaturan. SUPERADMIN sengaja tidak termasuk.
const ROLES = ['ADMIN', 'SUPERVISOR', 'LEADER', 'VIEWER'];
const ROLE_SUPER = 'SUPERADMIN';

class AuthException extends RuntimeException {
    public bool $auth = true;
}

function public_user(array $row): array {
    $bagianStr = trim((string)($row['bagian'] ?? ''));
    $bagianList = $bagianStr !== '' ? array_values(array_filter(array_map('trim', explode(',', strtoupper($bagianStr))))) : [];

    $role = strtoupper((string)($row['role'] ?? 'VIEWER'));
    $isSuper = $role === ROLE_SUPER;

    // Super admin tampil & diperlakukan sebagai ADMIN (akses penuh) + penanda 'super'.
    // Role aslinya tidak pernah dikirim ke browser.
    return [
        'username' => (string)$row['username'],
        'nama'     => (string)($row['nama'] ?? $row['username']),
        'role'     => $isSuper ? 'ADMIN' : $role,
        'bagian'   => $isSuper ? [] : $bagianList,
        'super'    => $isSuper,
        'foto'       => foto_url(user_foto_name($row)),
        'operatorId' => $isSuper ? '' : (string)($row['operator_id'] ?? ''),
    ];
}

function is_super(array $user): bool {
    return !empty($user['super']);
}

/** Hanya Admin / Supervisor (dan super admin) yang boleh mengakses fungsi pengelolaan. */
function require_admin(array $user): void {
    if (!is_super($user) && !in_array($user['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Akses ditolak: menu ini khusus Admin / Supervisor.');
    }
}

function require_super(array $user): void {
    if (!is_super($user)) {
        throw new RuntimeException('Akses ditolak.');
    }
}

function maintenance_on(): bool {
    return (string)db_val("SELECT value FROM settings WHERE key_name = 'maintenance'", [], '0') === '1';
}

// ---------------------------------------------------------------- Control Akses Menu
// ID menu yang bisa diatur aksesnya (menu khusus super admin tidak termasuk).
const MENU_IDS = ['dashboard', 'request_os', 'req_approval', 'jadwal_ieg', 'absensi', 'data_absen', 'jadwal', 'rekap', 'operator', 'bagian', 'pengaturan'];

/** Konfigurasi akses: ['roles' => [ROLE => [menu...]], 'users' => [username => [menu...]]] */
function menu_access_config(): array {
    $raw = json_decode((string)get_setting('menu_access', ''), true);
    // id lama 'wa' -> 'request_os'
    $clean = fn($list) => array_values(array_intersect(MENU_IDS, array_map(fn($m) => $m === 'wa' ? 'request_os' : $m, is_array($list) ? $list : [])));
    $out = ['roles' => [], 'users' => [], 'ro' => ['roles' => [], 'users' => []]];
    foreach ((array)($raw['roles'] ?? []) as $r => $list) {
        $r = strtoupper((string)$r);
        if (in_array($r, ROLES, true)) $out['roles'][$r] = $clean($list);
    }
    foreach ((array)($raw['users'] ?? []) as $u => $list) {
        $out['users'][strtolower((string)$u)] = $clean($list);
    }
    // Menu "lihat saja" (tidak bisa edit) per role / per user
    foreach ((array)($raw['ro']['roles'] ?? []) as $r => $list) {
        $r = strtoupper((string)$r);
        if (in_array($r, ROLES, true)) $out['ro']['roles'][$r] = $clean($list);
    }
    foreach ((array)($raw['ro']['users'] ?? []) as $u => $list) {
        $out['ro']['users'][strtolower((string)$u)] = $clean($list);
    }
    return $out;
}

/** Menu yang hanya boleh DILIHAT (tidak bisa edit) oleh user ini. Super admin selalu bisa edit. */
function readonly_menus_for_user(array $user): array {
    if (is_super($user)) return [];
    $cfg = menu_access_config();
    $uname = strtolower($user['username']);
    if (array_key_exists($uname, $cfg['users'])) return $cfg['ro']['users'][$uname] ?? [];
    return $cfg['ro']['roles'][$user['role']] ?? [];
}

/** Tolak aksi simpan/ubah bila menu tersebut diatur "lihat saja" untuk user ini. */
function require_menu_edit(array $user, string $menu, string $label = ''): void {
    if (in_array($menu, readonly_menus_for_user($user), true)) {
        throw new RuntimeException('Akses Anda untuk menu ' . ($label !== '' ? $label : $menu) . ' hanya lihat saja (tidak bisa edit). Hubungi Super Admin.');
    }
}

/** Daftar menu untuk user ini; null = pakai bawaan sesuai role. Super admin selalu null (semua menu). */
function menus_for_user(array $user): ?array {
    if (is_super($user)) return null;
    $cfg = menu_access_config();
    $uname = strtolower($user['username']);
    if (array_key_exists($uname, $cfg['users'])) return $cfg['users'][$uname];
    if (array_key_exists($user['role'], $cfg['roles'])) return $cfg['roles'][$user['role']];
    return null;
}

function menu_access_save(array $p, array $currentUser): array {
    require_super($currentUser);
    $cfg = ['roles' => [], 'users' => []];
    foreach ((array)($p['roles'] ?? []) as $r => $list) {
        $r = strtoupper((string)$r);
        if (in_array($r, ROLES, true)) $cfg['roles'][$r] = array_values(array_intersect(MENU_IDS, array_map(fn($m) => $m === 'wa' ? 'request_os' : $m, (array)$list)));
    }
    $hide = super_usernames();
    foreach ((array)($p['users'] ?? []) as $u => $list) {
        $u = strtolower(trim((string)$u));
        if ($u === '' || in_array($u, $hide, true)) continue;
        if (!db_val("SELECT COUNT(*) FROM users WHERE LOWER(username) = ?", [$u])) continue;
        $cfg['users'][$u] = array_values(array_intersect(MENU_IDS, (array)$list));
    }
    // Menu lihat saja: hanya menu yang memang boleh dibuka
    $cfg['ro'] = ['roles' => [], 'users' => []];
    foreach ((array)($p['ro']['roles'] ?? []) as $r => $list) {
        $r = strtoupper((string)$r);
        if (isset($cfg['roles'][$r])) $cfg['ro']['roles'][$r] = array_values(array_intersect($cfg['roles'][$r], (array)$list));
    }
    foreach ((array)($p['ro']['users'] ?? []) as $u => $list) {
        $u = strtolower(trim((string)$u));
        if (isset($cfg['users'][$u])) $cfg['ro']['users'][$u] = array_values(array_intersect($cfg['users'][$u], (array)$list));
    }
    set_settings(['menu_access' => json_encode($cfg)]);
    audit_log($currentUser['username'], 'CONTROL_AKSES', count($cfg['roles']) . ' role, ' . count($cfg['users']) . ' user khusus');
    return $cfg;
}

/** Approval Supervisor otomatis: Request OS yang disubmit langsung berstatus APPROVED. */
function auto_approve_on(): bool {
    return (string)db_val("SELECT value FROM settings WHERE key_name = 'auto_approve'", [], '0') === '1';
}

/** Username semua akun super admin (untuk disembunyikan dari daftar/audit). */
function super_usernames(): array {
    return array_map('strtolower', array_column(db_all("SELECT username FROM users WHERE UPPER(role) = ?", [ROLE_SUPER]), 'username'));
}

function audit_log(string $username, string $aksi, string $detail): void {
    try {
        db_insert('audit', [
            'waktu'  => date('Y-m-d H:i:s'),
            'user'   => $username,
            'aksi'   => $aksi,
            'detail' => mb_substr($detail, 0, 500)
        ]);
    } catch (Throwable $e) {
        error_log("Gagal mencatat audit: " . $e->getMessage());
    }
}

function get_audit(array $currentUser = []): array {
    $rows = db_all("SELECT waktu, user, aksi, detail FROM audit ORDER BY id DESC LIMIT 150");
    if (!is_super($currentUser)) {
        $hide = super_usernames();
        $rows = array_values(array_filter($rows, fn($r) => !in_array(strtolower((string)$r['user']), $hide, true)));
    }
    return array_slice($rows, 0, 100);
}

function login(string $username, string $password): array {
    $username = trim(strtolower($username));
    $password = (string)$password;
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('Username dan password wajib diisi.');
    }

    if (mb_strlen($password) > 200) {
        throw new RuntimeException('Username atau password salah.');
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
    login_throttle_check_($username, $ip);

    $row = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [$username]);
    $ok = false;
    if ($row && (int)$row['aktif'] === 1) {
        $ok = password_matches_($password, (string)$row['password_hash'], (int)$row['id']);
    } else {
        // Samakan waktu respons agar tidak bisa menebak username yang ada
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuG2Jp3rEw2tVhPjm6gG1yDtd8m0uuq3m');
    }
    if (!$ok) {
        login_fail_($username, $ip);
        throw new RuntimeException('Username atau password salah.');
    }
    login_clear_($username);

    // Saat maintenance aktif, hanya super admin yang boleh masuk (dicek setelah password benar)
    if (maintenance_on() && strtoupper((string)$row['role']) !== ROLE_SUPER) {
        throw new RuntimeException('Sistem sedang dalam maintenance. Silakan coba lagi nanti.');
    }

    // Bersihkan sesi kedaluwarsa
    db_query("DELETE FROM user_tokens WHERE expires_at < ?", [date('Y-m-d H:i:s')]);

    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

    db_insert('user_tokens', [
        'token'      => $token,
        'user_id'    => $row['id'],
        'created_at' => $now,
        'expires_at' => $expires,
    ]);

    db_update('users', ['last_login' => $now], 'id = ?', [$row['id']]);
    $user = public_user($row);
    audit_log($user['username'], 'LOGIN', '');

    return [
        'token'      => $token,
        'user'       => $user,
        'mustChange' => (int)$row['must_change'] === 1
    ];
}

/**
 * Cocokkan password. Hash modern (bcrypt/argon) wajib lewat password_verify.
 * Format lama (md5 32-hex / teks biasa) hanya diterima bila kolom BUKAN hash modern —
 * mencegah login dengan mengirim string hash itu sendiri sebagai password.
 */
function password_matches_(string $password, string $hash, int $userId): bool {
    $info = password_get_info($hash);
    if (!empty($info['algo'])) {
        if (!password_verify($password, $hash)) return false;
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$userId]);
        }
        return true;
    }
    if ($hash === '' || $hash[0] === '$') return false;
    $legacyOk = (strlen($hash) === 32 && ctype_xdigit($hash))
        ? hash_equals(strtolower($hash), md5($password))
        : hash_equals($hash, $password);
    if ($legacyOk) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$userId]);
    }
    return $legacyOk;
}

const LOGIN_MAX_USER = 5;    // gagal per username per 15 menit
const LOGIN_MAX_IP   = 20;   // gagal per IP per 15 menit

function login_throttle_check_(string $username, string $ip): void {
    $since = date('Y-m-d H:i:s', time() - 900);
    db_query("DELETE FROM login_attempts WHERE waktu < ?", [date('Y-m-d H:i:s', time() - 86400)]);
    $u = (int)db_val("SELECT COUNT(*) FROM login_attempts WHERE kunci = ? AND waktu >= ?", ['u:' . $username, $since]);
    $i = (int)db_val("SELECT COUNT(*) FROM login_attempts WHERE kunci = ? AND waktu >= ?", ['ip:' . $ip, $since]);
    if ($u >= LOGIN_MAX_USER || $i >= LOGIN_MAX_IP) {
        throw new RuntimeException('Terlalu banyak percobaan login yang gagal. Silakan coba lagi dalam 15 menit.');
    }
}

function login_fail_(string $username, string $ip): void {
    $now = date('Y-m-d H:i:s');
    db_insert('login_attempts', ['kunci' => 'u:' . mb_substr($username, 0, 70), 'waktu' => $now]);
    db_insert('login_attempts', ['kunci' => 'ip:' . mb_substr($ip, 0, 70), 'waktu' => $now]);
}

function login_clear_(string $username): void {
    db_query("DELETE FROM login_attempts WHERE kunci = ?", ['u:' . $username]);
}

function auth_check(?string $token): array {
    if (!$token) {
        throw new AuthException('Sesi berakhir, silakan login ulang.');
    }

    $now = date('Y-m-d H:i:s');
    $sess = db_row("SELECT t.*, u.username, u.nama, u.role, u.bagian, u.aktif, u.must_change, u.foto, u.operator_id 
                    FROM user_tokens t 
                    JOIN users u ON t.user_id = u.id 
                    WHERE t.token = ? AND t.expires_at > ?", [$token, $now]);

    if (!$sess || (int)$sess['aktif'] !== 1) {
        throw new AuthException('Sesi berakhir, silakan login ulang.');
    }

    // Maintenance aktif: sesi selain super admin langsung diakhiri
    if (maintenance_on() && strtoupper((string)$sess['role']) !== ROLE_SUPER) {
        throw new AuthException('Sistem sedang dalam maintenance. Silakan coba lagi nanti.');
    }

    // Perpanjang masa sesi (sliding expiration)
    $newExpires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    db_update('user_tokens', ['expires_at' => $newExpires], 'token = ?', [$token]);

    $u = public_user($sess);
    $u['mustChange'] = (int)$sess['must_change'] === 1;
    return $u;
}

function logout(?string $token, array $currentUser): bool {
    if ($token) {
        db_delete('user_tokens', 'token = ?', [$token]);
    }
    audit_log($currentUser['username'], 'LOGOUT', '');
    return true;
}

function change_password(array $currentUser, string $oldPw, string $newPw): bool {
    if (mb_strlen($newPw) < 8) {
        throw new InvalidArgumentException('Password baru minimal 8 karakter.');
    }
    if (mb_strlen($newPw) > 200) {
        throw new InvalidArgumentException('Password terlalu panjang.');
    }
    if ($newPw === $oldPw) {
        throw new InvalidArgumentException('Password baru harus berbeda dari yang lama.');
    }

    $row = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [strtolower($currentUser['username'])]);
    if (!$row) throw new RuntimeException('Pengguna tidak ditemukan.');

    if (!password_verify($oldPw, $row['password_hash'])) {
        throw new RuntimeException('Password lama salah.');
    }

    db_update('users', [
        'password_hash' => password_hash($newPw, PASSWORD_DEFAULT),
        'must_change'   => 0
    ], 'id = ?', [$row['id']]);

    audit_log($currentUser['username'], 'GANTI_PASSWORD', '');
    return true;
}

function list_users(): array {
    // Akun super admin tidak pernah ditampilkan di menu Pengaturan
    $rows = db_all("SELECT * FROM users WHERE UPPER(role) <> ? ORDER BY username ASC", [ROLE_SUPER]);
    $out = [];
    foreach ($rows as $r) {
        $u = public_user($r);
        $u['aktif'] = (int)$r['aktif'] === 1;
        $u['mustChange'] = (int)$r['must_change'] === 1;
        $u['lastLogin'] = (string)($r['last_login'] ?? '');
        $u['operatorId'] = (string)($r['operator_id'] ?? '');
        $out[] = $u;
    }
    return $out;
}

function save_user(array $p, array $currentUser): bool {
    $username = strtolower(trim((string)($p['username'] ?? '')));
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
        throw new InvalidArgumentException('Username 3–30 karakter: huruf kecil, angka, titik, minus, garis bawah.');
    }
    $role = strtoupper(trim((string)($p['role'] ?? 'VIEWER')));
    if (!in_array($role, ROLES, true)) {
        throw new InvalidArgumentException('Peran tidak valid.');
    }

    $bagianArr = is_array($p['bagian'] ?? null) ? $p['bagian'] : explode(',', (string)($p['bagian'] ?? ''));
    $bagianStr = implode(',', array_filter(array_map('trim', array_map('strtoupper', $bagianArr))));

    $row = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [$username]);
    if ($row && strtoupper((string)$row['role']) === ROLE_SUPER) {
        // Akun super admin tidak bisa diubah dari menu Pengaturan (dan tidak diakui keberadaannya)
        throw new RuntimeException('Username sudah dipakai.');
    }
    $isNew = !$row;

    if ($isNew && isset($p['isNew']) && $p['isNew'] === false) throw new RuntimeException('Pengguna tidak ditemukan.');
    if (!$isNew && isset($p['isNew']) && $p['isNew'] === true) throw new RuntimeException('Username sudah dipakai.');
    if ($isNew && mb_strlen((string)($p['password'] ?? '')) < 8) throw new RuntimeException('Password awal minimal 8 karakter.');
    if (!empty($p['password']) && mb_strlen((string)$p['password']) < 8) throw new RuntimeException('Password minimal 8 karakter.');
    if (!$isNew && $username === strtolower($currentUser['username']) && $role !== 'ADMIN') {
        throw new RuntimeException('Tidak bisa menurunkan peran akun sendiri.');
    }

    $nama = trim((string)($p['nama'] ?? '')) ?: $username;

    // Tautan ke data operator (foto profil user = foto operator tersebut)
    $operatorId = trim((string)($p['operatorId'] ?? ''));
    if ($operatorId !== '' && !db_val("SELECT COUNT(*) FROM operator WHERE id = ?", [$operatorId])) {
        throw new InvalidArgumentException('Operator yang ditautkan tidak ditemukan.');
    }
    $aktif = isset($p['aktif']) && ($p['aktif'] === false || $p['aktif'] === 0 || $p['aktif'] === '0') ? 0 : 1;

    if ($isNew) {
        $newPw = (string)($p['password'] ?? 'admin123');
        db_insert('users', [
            'username'      => $username,
            'nama'          => $nama,
            'role'          => $role,
            'bagian'        => $bagianStr,
            'password_hash' => password_hash($newPw, PASSWORD_DEFAULT),
            'aktif'         => $aktif,
            'must_change'   => 1,
            'last_login'    => '',
            'operator_id'   => $operatorId
        ]);
        audit_log($currentUser['username'], 'TAMBAH_USER', "$username ($role)");
    } else {
        $data = [
            'nama'        => $nama,
            'role'        => $role,
            'bagian'      => $bagianStr,
            'aktif'       => $aktif,
            'operator_id' => $operatorId,
        ];
        if (!empty($p['password'])) {
            $data['password_hash'] = password_hash((string)$p['password'], PASSWORD_DEFAULT);
            $data['must_change'] = 1;
        }
        db_update('users', $data, 'id = ?', [$row['id']]);
        audit_log($currentUser['username'], 'UBAH_USER', "$username ($role)" . (!empty($p['password']) ? ' +reset pw' : ''));
    }

    return true;
}

function delete_user(string $username, array $currentUser): bool {
    $username = strtolower(trim($username));
    if ($username === strtolower($currentUser['username'])) {
        throw new RuntimeException('Tidak bisa menghapus akun sendiri.');
    }

    $row = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [$username]);
    if (!$row || strtoupper((string)$row['role']) === ROLE_SUPER) throw new RuntimeException('Pengguna tidak ditemukan.');

    db_delete('user_tokens', 'user_id = ?', [$row['id']]);
    db_delete('users', 'id = ?', [$row['id']]);
    foto_delete_file($row['foto'] ?? '');
    audit_log($currentUser['username'], 'HAPUS_USER', $username);
    return true;
}

function can_edit_bagian(array $currentUser, ?string $bagian): bool {
    if (is_super($currentUser) || in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) return true;
    if ($currentUser['role'] !== 'LEADER') return false;
    if (empty($currentUser['bagian'])) return true;
    return in_array(strtoupper(trim((string)$bagian)), $currentUser['bagian'], true);
}

function can_approve_mpp(array $currentUser): bool {
    return is_super($currentUser) || $currentUser['role'] === 'SUPERVISOR';
}
