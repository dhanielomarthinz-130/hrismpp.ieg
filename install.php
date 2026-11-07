<?php
/**
 * install.php : Halaman Status & Pemasangan Database Sistem HRIS MPP PHP.
 * Reset data hanya diizinkan dari localhost agar aman saat di-hosting publik.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = in_array($remote, ['127.0.0.1', '::1'], true);

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'reseed') {
        if (!$isLocal) {
            $err = 'Reset data hanya bisa dilakukan dari komputer server (localhost).';
        } else {
            try {
                $pdo = db();
                $tables = ['users', 'user_tokens', 'bagian', 'operator', 'kode_jadwal', 'shift', 'settings'];
                foreach ($tables as $t) {
                    $pdo->exec("DELETE FROM $t");
                }
                seed_default_data($pdo);
                $msg = 'Database berhasil di-reset dan diisi ulang data bawaan.';
            } catch (Throwable $e) {
                $err = 'Gagal reset: ' . $e->getMessage();
            }
        }
    }
}

$online = false;
$dbStatus = '';
$counts = [];
try {
    $pdo = db();
    $online = true;
    $dbStatus = 'Terhubung · ' . strtoupper(DB_DRIVER);
    foreach (['operator', 'bagian', 'users', 'kode_jadwal'] as $t) {
        $counts[$t] = (int)db_val("SELECT COUNT(*) FROM $t");
    }
} catch (Throwable $e) {
    $dbStatus = 'Gagal terhubung';
    $err = $err ?: $e->getMessage();
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/image/logo-IEG.png">
    <title>Status Sistem · HRIS MPP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
    <style>
        :root {
            --bg: #F4F5F8; --surface: #FFFFFF; --surface-2: #F6F7F9; --border: #E3E6EC; --line: #E9EBF0;
            --text: #111827; --text-2: #4B5563; --text-3: #6B7280;
            --primary: #2F3C93; --primary-hover: #26317C;
            --ok: #067647; --ok-bg: #E9F7EF; --err: #B42318; --err-bg: #FEF0EF;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0B0D13; --surface: #12151D; --surface-2: #181C26; --border: #252A37; --line: #212634;
                --text: #ECEEF3; --text-2: #A7AEBE; --text-3: #858DA0;
                --primary: #93A0F5; --primary-hover: #AEB8F8;
                --ok: #4ACB8B; --ok-bg: rgba(74,203,139,.12); --err: #F58A80; --err-bg: rgba(245,138,128,.12);
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 32px 16px;
            background: var(--bg); color: var(--text); font: 13.5px/1.5 "Plus Jakarta Sans", system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        .card { width: 100%; max-width: 560px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 24px; box-shadow: 0 4px 12px -2px rgba(17,24,39,.08); }
        .head { display: flex; align-items: center; gap: 12px; padding-bottom: 18px; margin-bottom: 18px; border-bottom: 1px solid var(--line); }
        .logo { width: 40px; height: 40px; border-radius: 10px; background: #fff; padding: 4px; flex: none; }
        .logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
        h1 { margin: 0; font-size: 17px; font-weight: 700; letter-spacing: -.01em; }
        .sub { color: var(--text-3); font-size: 12.5px; }
        .status { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
        .status > span:first-child { font-weight: 600; color: var(--text-2); }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge.ok { background: var(--ok-bg); color: var(--ok); }
        .badge.err { background: var(--err-bg); color: var(--err); }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 18px; }
        .item { padding: 12px 14px; border-left: 1px solid var(--line); }
        .item:first-child { border-left: 0; }
        .item-label { font-size: 12px; color: var(--text-2); white-space: nowrap; }
        .item-val { font-size: 20px; font-weight: 700; margin-top: 2px; font-variant-numeric: tabular-nums; }
        @media (max-width: 520px) { .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .item:nth-child(3) { border-left: 0; } .item:nth-child(n+3) { border-top: 1px solid var(--line); } }
        .alert { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; word-break: break-word; }
        .alert.ok { background: var(--ok-bg); color: var(--ok); }
        .alert.err { background: var(--err-bg); color: var(--err); }
        .btn { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 42px; padding: 0 16px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; border: 1px solid var(--border); background: var(--surface); color: var(--text); }
        .btn-pri { background: var(--primary); border-color: var(--primary); color: #fff; }
        @media (prefers-color-scheme: dark) { .btn-pri { color: #0B0D13; } }
        @media (hover: hover) { .btn-pri:hover { background: var(--primary-hover); } .btn-sec:hover { background: var(--surface-2); } }
        .btn-sec { margin-top: 8px; }
        .info { margin-top: 18px; padding: 14px; border-radius: 10px; background: var(--surface-2); color: var(--text-2); font-size: 12.5px; line-height: 1.7; }
        .info b { color: var(--text); font-weight: 600; }
        code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; background: var(--border); padding: 1px 5px; border-radius: 4px; color: var(--text); }
    </style>
</head>
<body>
    <main class="card">
        <div class="head">
            <div class="logo"><img src="assets/image/logo-IEG.png" alt=""></div>
            <div>
                <h1>Status sistem HRIS MPP</h1>
                <div class="sub">PT. Inovasi Eka Gemilang · v<?= $h(APP_VERSION) ?></div>
            </div>
        </div>

        <?php if ($msg): ?><div class="alert ok"><?= $h($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert err"><?= $h($err) ?></div><?php endif; ?>

        <div class="status">
            <span>Database</span>
            <span class="badge <?= $online ? 'ok' : 'err' ?>"><?= $h($dbStatus) ?></span>
        </div>

        <div class="grid">
            <div class="item"><div class="item-label">Operator</div><div class="item-val"><?= $counts['operator'] ?? 0 ?></div></div>
            <div class="item"><div class="item-label">Bagian</div><div class="item-val"><?= $counts['bagian'] ?? 0 ?></div></div>
            <div class="item"><div class="item-label">Akun user</div><div class="item-val"><?= $counts['users'] ?? 0 ?></div></div>
            <div class="item"><div class="item-label">Kode jadwal</div><div class="item-val"><?= $counts['kode_jadwal'] ?? 0 ?></div></div>
        </div>

        <a href="./" class="btn btn-pri">Buka aplikasi</a>

        <?php if ($isLocal): ?>
        <form method="POST" onsubmit="return confirm('Reset dan isi ulang data bawaan? Data master saat ini akan dihapus.');">
            <input type="hidden" name="action" value="reseed">
            <button type="submit" class="btn btn-sec">Reset &amp; isi ulang data bawaan</button>
        </form>

        <div class="info">
            <b>Akun bawaan</b><br>
            Admin: <code>admin</code> / <code>admin123</code> · Leader: <code>leader</code> / <code>leader123</code><br>
            <b>Mode database:</b> <code><?= $h(strtoupper(DB_DRIVER)) ?></code> — ubah <code>DB_DRIVER</code> di <code>config.php</code> untuk beralih ke MySQL.
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
