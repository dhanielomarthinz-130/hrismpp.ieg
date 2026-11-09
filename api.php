<?php
/**
 * api.php : Router API pusat untuk request JSON dari frontend.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
// Tanpa header CORS: API hanya untuk halaman aplikasi ini sendiri (same-origin)

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';
require_once __DIR__ . '/absensi.php';
require_once __DIR__ . '/mingguan.php';
require_once __DIR__ . '/wa.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/dbadmin.php';

// Tangkap input JSON
$rawInput = file_get_contents('php://input');
if ($rawInput === '' && php_sapi_name() === 'cli') {
    $rawInput = file_get_contents('php://stdin');
}
$payload = json_decode($rawInput, true) ?: [];

if (empty($_GET) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $_GET);
}

// Action bisa dari query parameter GET atau body POST
$action = $_GET['action'] ?? $payload['action'] ?? '';

// Token otentikasi dari Header atau Payload
$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $token = $m[1];
    } else {
        $token = $_SERVER['HTTP_AUTHORIZATION'];
    }
}
if (!$token) {
    $token = (string)($payload['token'] ?? '');
}

try {
    if ($action === 'login') {
        $data = login((string)($payload['username'] ?? ''), (string)($payload['password'] ?? ''));
        echo json_encode(['ok' => true, 'data' => $data]);
        exit;
    }

    if ($action === 'publicInfo') {
        echo json_encode([
            'ok'   => true,
            'data' => [
                'appName'     => get_setting('app_nama', APP_NAME),
                'version'     => APP_VERSION,
                'maintenance' => maintenance_on()
            ]
        ]);
        exit;
    }

    // Aksi di bawah ini memerlukan otentikasi login
    $user = auth_check($token);

    // Akun yang wajib ganti password hanya boleh mengakses aksi berikut
    if (!empty($user['mustChange']) && !in_array($action, ['changePassword', 'logout', 'me', 'master'], true)) {
        throw new RuntimeException('Silakan ganti password Anda terlebih dahulu.');
    }

    $admin = function (callable $fn) use ($user) {
        return function () use ($fn, $user) { require_admin($user); return $fn(); };
    };

    $routes = [
        // Auth
        'logout'          => fn() => logout($token, $user),
        'me'              => fn() => $user,
        'changePassword'  => fn() => change_password($user, (string)($payload['oldPassword'] ?? ''), (string)($payload['newPassword'] ?? '')),

        // Master & Dashboard
        'master'          => fn() => get_master($user),
        'dashboard'       => fn() => get_dashboard($payload),

        // Absensi
        'absensi'         => fn() => get_absensi($payload, $user),
        'setAbsensi'      => fn() => set_absensi($payload, $user),
        'addToShift'      => fn() => add_to_shift($payload, $user),
        'removeFromShift' => fn() => remove_from_shift($payload, $user),
        'fillVacant'      => fn() => fill_vacant_slot($payload, $user),
        'absensiList'     => fn() => absensi_list($payload, $user),
        'absensiListXls'  => fn() => absensi_list_export($payload, $user),

        // Jadwal Employee
        'jadwal'          => fn() => jadwal_minggu($payload, $user),
        'minggu'          => fn() => jadwal_minggu($payload, $user),
        'mingguSimpan'    => fn() => jadwal_simpan($payload, $user),
        'mingguIsi'       => fn() => jadwal_isi($payload, $user),
        'mingguSalin'     => fn() => jadwal_salin($payload, $user),
        'mingguKunci'     => fn() => jadwal_kunci($payload, $user),
        'setJadwal'       => fn() => set_jadwal_cell($payload, $user),
        'kodeSimpan'      => $admin(fn() => kode_simpan($payload, $user)),
        'mingguExcel'     => fn() => minggu_export($payload, $user),
        'mingguImport'    => fn() => minggu_import($payload, $user),

        // Master Operator & Bagian
        'saveOperator'    => $admin(fn() => save_operator($payload, $user)),
        'deleteOperator'  => $admin(fn() => delete_operator($payload, $user)),
        'importOperator'  => $admin(fn() => import_operators($payload, $user)),
        'saveBagian'      => $admin(fn() => save_bagian($payload, $user)),
        'deleteBagian'    => $admin(fn() => delete_bagian($payload, $user)),

        // Setting & User Management
        'settings'        => $admin(fn() => get_settings_admin()),
        'saveSettings'    => $admin(fn() => save_settings($payload, $user)),
        'saveShift'       => $admin(fn() => save_shift($payload, $user)),
        'users'           => $admin(fn() => list_users()),
        'saveUser'        => $admin(fn() => save_user($payload, $user)),
        'deleteUser'      => $admin(fn() => delete_user((string)($payload['username'] ?? ''), $user)),
        'audit'           => $admin(fn() => get_audit($user)),

        // Foto profil (user sendiri) & foto operator (Admin/Supervisor)
        'fotoProfil'      => fn() => profile_set_foto($user, isset($payload['data']) ? (string)$payload['data'] : null),
        'fotoOperator'    => $admin(fn() => operator_set_foto((string)($payload['id'] ?? ''), isset($payload['data']) ? (string)$payload['data'] : null, $user)),

        // Penjelajah database (khusus super admin)
        'dbTables'        => fn() => dba_tables($user),
        'dbRows'          => fn() => dba_rows($payload, $user),
        'dbDeleteRow'     => fn() => dba_delete_row($payload, $user, (string)$token),
        'dbTruncate'      => fn() => dba_truncate($payload, $user, (string)$token),

        // Maintenance (khusus super admin)
        'maintenance'     => function () use ($user) {
            require_super($user);
            return ['aktif' => maintenance_on(), 'key' => MAINTENANCE_KEY, 'autoApprove' => auto_approve_on()];
        },
        'setAutoApprove'  => function () use ($payload, $user) {
            require_super($user);
            $on = !empty($payload['aktif']);
            set_settings(['auto_approve' => $on ? '1' : '0']);
            audit_log($user['username'], 'AUTO_APPROVE', $on ? 'Aktif' : 'Nonaktif');
            return ['autoApprove' => $on];
        },
        'accessGet'       => function () use ($user) {
            require_super($user);
            return ['config' => menu_access_config(), 'users' => list_users()];
        },
        'accessSave'      => fn() => menu_access_save($payload, $user),
        'setMaintenance'  => function () use ($payload, $user) {
            require_super($user);
            $on = !empty($payload['aktif']);
            set_settings(['maintenance' => $on ? '1' : '0']);
            audit_log($user['username'], 'MAINTENANCE', $on ? 'Aktif' : 'Nonaktif');
            return ['aktif' => $on, 'key' => MAINTENANCE_KEY, 'autoApprove' => auto_approve_on()];
        },

        // Order MPP (WhatsApp & Approval Supervisor)
        'order'           => fn() => order_get($payload, $user),
        'orderList'       => fn() => order_list_all($payload, $user),
        'orderSave'       => fn() => order_save($payload, $user),
        'orderSend'       => fn() => order_send($payload, $user),
        'orderSubmit'     => fn() => order_submit($payload, $user),
        'orderApprove'    => fn() => order_approve($payload, $user),
        'orderReject'     => fn() => order_reject($payload, $user),
        'orderShared'     => fn() => order_shared($payload, $user),
        'rekapMpp'        => fn() => rekap_mpp($payload, $user),
        'rekapExcel'      => fn() => rekap_export($payload, $user),
        'botToken'        => $admin(fn() => bot_token_new($user)),
        'waGroups'        => $admin(fn() => []), // daftar grup hanya tersedia lewat bot WhatsApp eksternal
        'waStatus'        => fn() => wa_status($payload, $user),
        'waLog'           => fn() => wa_log($payload, $user),
        'waAuto'          => function () use ($payload, $user) {
            require_admin($user);
            set_settings(['wa_auto' => !empty($payload['aktif']) ? '1' : '0']);
            audit_log($user['username'], 'SET_WA_AUTO', !empty($payload['aktif']) ? 'Aktif' : 'Nonaktif');
            return true;
        }
    ];

    if (!isset($routes[$action])) {
        throw new InvalidArgumentException("Aksi tidak dikenal: $action");
    }

    // Control Akses "lihat saja": aksi simpan/ubah per menu ditolak
    $jadMenu = !empty($payload['onlyIeg']) ? ['jadwal_ieg', 'Jadwal IEG'] : ['jadwal', 'Jadwal Employee'];
    $writeMenu = [
        'setAbsensi' => ['absensi', 'Absensi Shift'], 'addToShift' => ['absensi', 'Absensi Shift'],
        'removeFromShift' => ['absensi', 'Absensi Shift'], 'fillVacant' => ['absensi', 'Absensi Shift'],
        'mingguSimpan' => $jadMenu, 'mingguIsi' => $jadMenu, 'mingguSalin' => $jadMenu,
        'mingguKunci' => ['jadwal', 'Jadwal Employee'], 'setJadwal' => ['jadwal', 'Jadwal Employee'], 'mingguImport' => ['jadwal', 'Jadwal Employee'],
        'orderSave' => ['request_os', 'Request OS'], 'orderSend' => ['request_os', 'Request OS'],
        'orderSubmit' => ['request_os', 'Request OS'], 'orderShared' => ['request_os', 'Request OS'],
        'orderApprove' => ['req_approval', 'Approval Request OS'], 'orderReject' => ['req_approval', 'Approval Request OS'],
        'saveOperator' => ['operator', 'Operator'], 'deleteOperator' => ['operator', 'Operator'],
        'importOperator' => ['operator', 'Operator'], 'fotoOperator' => ['operator', 'Operator'],
        'saveBagian' => ['bagian', 'Bagian & Target'], 'deleteBagian' => ['bagian', 'Bagian & Target'],
        'saveSettings' => ['pengaturan', 'Pengaturan'], 'saveShift' => ['pengaturan', 'Pengaturan'], 'kodeSimpan' => ['pengaturan', 'Pengaturan'],
        'saveUser' => ['pengaturan', 'Pengaturan'], 'deleteUser' => ['pengaturan', 'Pengaturan'],
        'botToken' => ['pengaturan', 'Pengaturan'], 'waAuto' => ['pengaturan', 'Pengaturan'],
    ];
    if (isset($writeMenu[$action])) {
        require_menu_edit($user, $writeMenu[$action][0], $writeMenu[$action][1]);
    }

    $result = $routes[$action]();
    echo json_encode(['ok' => true, 'data' => $result]);

} catch (Throwable $e) {
    $isAuthErr = !empty($e->auth) || ($e->getMessage() === 'Sesi berakhir, silakan login ulang.');
    // Pesan validasi/bisnis aman ditampilkan; error internal (database, PHP) hanya dicatat di log server
    $safe = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !($e instanceof PDOException);
    if (!$safe) {
        error_log('[HRIS API] ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    http_response_code($isAuthErr ? 401 : 400);
    echo json_encode([
        'ok'    => false,
        'error' => $safe ? $e->getMessage() : 'Terjadi kesalahan pada server. Silakan coba lagi atau hubungi Admin.',
        'auth'  => $isAuthErr
    ]);
}
