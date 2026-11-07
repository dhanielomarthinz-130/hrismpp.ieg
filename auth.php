<?php
/**
 * auth.php : Otentikasi pengguna, token sesi, ganti password, kelola user, dan audit trail.
 */
require_once __DIR__ . '/db.php';

const ROLES = ['ADMIN', 'SUPERVISOR', 'LEADER', 'VIEWER'];

class AuthException extends RuntimeException {
    public bool $auth = true;
}

function public_user(array $row): array {
    $bagianStr = trim((string)($row['bagian'] ?? ''));
    $bagianList = $bagianStr !== '' ? array_values(array_filter(array_map('trim', explode(',', strtoupper($bagianStr))))) : [];

    return [
        'username' => (string)$row['username'],
        'nama'     => (string)($row['nama'] ?? $row['username']),
        'role'     => strtoupper((string)($row['role'] ?? 'VIEWER')),
        'bagian'   => $bagianList,
    ];
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

function get_audit(): array {
    $rows = db_all("SELECT waktu, user, aksi, detail FROM audit ORDER BY id DESC LIMIT 100");
    return $rows;
}

function login(string $username, string $password): array {
    $username = trim(strtolower($username));
    $password = (string)$password;
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('Username dan password wajib diisi.');
    }

    $row = db_row("SELECT * FROM users WHERE LOWER(username) = ?", [$username]);
    if (!$row || (int)$row['aktif'] !== 1) {
        throw new RuntimeException('Username atau password salah.');
    }

    // Cek password hash
    if (!password_verify($password, $row['password_hash'])) {
        // Fallback untuk password lama bila ada plain text/md5 dari sheet
        if ($password !== $row['password_hash'] && md5($password) !== $row['password_hash']) {
            throw new RuntimeException('Username atau password salah.');
        }
        // Rehash password ke bcrypt bila cocok dengan fallback
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$row['id']]);
    }

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

function auth_check(?string $token): array {
    if (!$token) {
        throw new AuthException('Sesi berakhir, silakan login ulang.');
    }

    $now = date('Y-m-d H:i:s');
    $sess = db_row("SELECT t.*, u.username, u.nama, u.role, u.bagian, u.aktif, u.must_change 
                    FROM user_tokens t 
                    JOIN users u ON t.user_id = u.id 
                    WHERE t.token = ? AND t.expires_at > ?", [$token, $now]);

    if (!$sess || (int)$sess['aktif'] !== 1) {
        throw new AuthException('Sesi berakhir, silakan login ulang.');
    }

    // Perpanjang masa sesi (sliding expiration)
    $newExpires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    db_update('user_tokens', ['expires_at' => $newExpires], 'token = ?', [$token]);

    return public_user($sess);
}

function logout(?string $token, array $currentUser): bool {
    if ($token) {
        db_delete('user_tokens', 'token = ?', [$token]);
    }
    audit_log($currentUser['username'], 'LOGOUT', '');
    return true;
}

function change_password(array $currentUser, string $oldPw, string $newPw): bool {
    if (mb_strlen($newPw) < 6) {
        throw new InvalidArgumentException('Password baru minimal 6 karakter.');
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
    $rows = db_all("SELECT * FROM users ORDER BY username ASC");
    $out = [];
    foreach ($rows as $r) {
        $u = public_user($r);
        $u['aktif'] = (int)$r['aktif'] === 1;
        $u['mustChange'] = (int)$r['must_change'] === 1;
        $u['lastLogin'] = (string)($r['last_login'] ?? '');
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
    $isNew = !$row;

    if ($isNew && isset($p['isNew']) && $p['isNew'] === false) throw new RuntimeException('Pengguna tidak ditemukan.');
    if (!$isNew && isset($p['isNew']) && $p['isNew'] === true) throw new RuntimeException('Username sudah dipakai.');
    if ($isNew && mb_strlen((string)($p['password'] ?? '')) < 6) throw new RuntimeException('Password awal minimal 6 karakter.');
    if (!$isNew && $username === strtolower($currentUser['username']) && $role !== 'ADMIN') {
        throw new RuntimeException('Tidak bisa menurunkan peran akun sendiri.');
    }

    $nama = trim((string)($p['nama'] ?? '')) ?: $username;
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
            'last_login'    => ''
        ]);
        audit_log($currentUser['username'], 'TAMBAH_USER', "$username ($role)");
    } else {
        $data = [
            'nama'   => $nama,
            'role'   => $role,
            'bagian' => $bagianStr,
            'aktif'  => $aktif,
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
    if (!$row) throw new RuntimeException('Pengguna tidak ditemukan.');

    db_delete('user_tokens', 'user_id = ?', [$row['id']]);
    db_delete('users', 'id = ?', [$row['id']]);
    audit_log($currentUser['username'], 'HAPUS_USER', $username);
    return true;
}

function can_edit_bagian(array $currentUser, ?string $bagian): bool {
    if (in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) return true;
    if ($currentUser['role'] !== 'LEADER') return false;
    if (empty($currentUser['bagian'])) return true;
    return in_array(strtoupper(trim((string)$bagian)), $currentUser['bagian'], true);
}

function can_approve_mpp(array $currentUser): bool {
    return $currentUser['role'] === 'SUPERVISOR';
}
