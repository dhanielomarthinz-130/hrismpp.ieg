<?php
/**
 * login.php : Halaman Form Login Dedicated (Clean URL: /login)
 * HRIS Inventory PT. Inovasi Eka Gemilang
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/master.php';

$appVer  = htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8');
$styles  = file_get_contents(__DIR__ . '/Styles.html');

// ---- Maintenance ----
$mtKey   = strtolower((string)MAINTENANCE_KEY);
$mtToken = hash('sha256', 'hris-mt|' . $mtKey);
$path    = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base    = preg_replace('#/(maintenance/[^/]*|login(\.php)?)/?$#i', '/', $path);
if ($base === '' || substr($base, -1) !== '/') $base .= '/';

if (isset($_GET['mt'])) {
    // /maintenance/<kunci> : bila kunci benar, buka form login saat maintenance lalu arahkan ke /login
    if (hash_equals($mtKey, strtolower((string)$_GET['mt']))) {
        setcookie('hris_mt', $mtToken, [
            'expires'  => time() + 7 * 86400,
            'path'     => $base,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . $base . 'login');
    exit;
}

$maintenance     = (string)get_setting('maintenance', '0') === '1';
$mtBypass        = hash_equals($mtToken, (string)($_COOKIE['hris_mt'] ?? ''));
$showMaintenance = $maintenance && !$mtBypass;

ob_start();
?>
<!DOCTYPE html>
<html lang="id" data-theme="morning">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4F46E5">
    <link rel="icon" type="image/svg+xml" href="assets/image/favicon.svg">
    <link rel="icon" type="image/png" sizes="180x180" href="assets/image/favicon-180.png">
    <link rel="apple-touch-icon" href="assets/image/favicon-180.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
    <title>Masuk · HRIS Inventory PT. Inovasi Eka Gemilang</title>
    <!-- INCLUDE_STYLES -->
    <style>
        /* Halaman: kartu login ringkas di tengah layar */
        .auth {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px 16px;
            background: var(--bg);
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            background: var(--side-bg);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        @media (min-width: 860px) {
            .auth-card {
                max-width: 820px;
                grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
            }
        }

        /* Panel brand (desktop saja) */
        .auth-brand {
            display: none;
            background: rgba(0, 0, 0, 0.18);
            color: #FFFFFF;
            padding: 28px 28px 22px;
            flex-direction: column;
            justify-content: space-between;
            gap: 24px;
        }

        @media (min-width: 860px) {
            .auth-brand {
                display: flex;
            }
        }

        .auth-brand-top {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .auth-brand-top .logo-mark {
            width: 34px;
            height: 34px;
            border-radius: 8px;
        }

        .auth-brand-top b {
            display: block;
            font-size: 13px;
            font-weight: 600;
        }

        .auth-brand-top small {
            display: block;
            font-size: 11.5px;
            color: var(--side-muted);
        }

        .auth-brand h2 {
            font-size: 20px;
            line-height: 1.3;
            font-weight: 700;
            letter-spacing: -0.015em;
            margin: 0 0 8px;
        }

        .auth-brand p {
            margin: 0 0 18px;
            color: var(--side-text);
            font-size: 12.5px;
            line-height: 1.55;
        }

        .auth-feats {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 10px;
        }

        .auth-feats li {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12.5px;
            font-weight: 500;
            color: #FFFFFF;
        }

        .auth-feats .material-symbols-outlined {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--side-indicator, #F472B6);
            font-size: 16px;
            flex: none;
        }

        .auth-brand-foot {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: var(--side-muted);
        }

        /* Panel form */
        .auth-main {
            position: relative;
            padding: 28px 28px 22px;
            color: #FFFFFF;
        }

        /* Form di atas latar sidebar */
        .auth-main .field > span {
            color: #E0E7FF;
        }

        .auth-main input {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.14);
            color: #FFFFFF;
        }

        .auth-main input::placeholder {
            color: var(--side-muted);
        }

        .auth-main input:focus {
            background: rgba(255, 255, 255, 0.09);
            border-color: #A5B4FC;
            box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.25);
        }

        .auth-main input:-webkit-autofill {
            -webkit-text-fill-color: #FFFFFF;
            -webkit-box-shadow: 0 0 0 40px #1E1B4B inset;
        }

        .auth-main .input-lead-icon,
        .auth-main .pw-eye {
            color: var(--side-muted);
        }

        .auth-main .input-icon-wrap:focus-within .input-lead-icon {
            color: #A5B4FC;
        }

        .auth-main .btn.ghost {
            color: var(--side-text);
        }

        @media (hover: hover) {
            .auth-main .btn.ghost:hover:not(:disabled) {
                background: rgba(255, 255, 255, 0.08);
                color: #FFFFFF;
            }

            .auth-main .pw-eye:hover {
                color: #FFFFFF;
            }
        }

        .auth-main .login-submit-btn {
            background: var(--grad);
            border-color: transparent;
            color: #FFFFFF;
        }

        .auth-main .alert.err {
            background: rgba(239, 68, 68, 0.16);
            border-color: rgba(248, 113, 113, 0.4);
            color: #FECACA;
        }

        .mt-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            margin-bottom: 16px;
            border-radius: 8px;
            background: rgba(251, 191, 36, 0.14);
            border: 1px solid rgba(251, 191, 36, 0.35);
            color: #FDE68A;
            font-size: 12.5px;
            font-weight: 500;
        }

        .mt-banner .material-symbols-outlined {
            font-size: 18px;
        }

        .mt-screen {
            text-align: center;
            padding: 12px 0 4px;
        }

        .mt-screen .mt-ico {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(251, 191, 36, 0.14);
            color: #FBBF24;
        }

        .mt-screen .mt-ico .material-symbols-outlined {
            font-size: 30px;
        }

        .mt-screen h1 {
            font-size: 19px;
            font-weight: 700;
            margin: 0 0 6px;
        }

        .mt-screen p {
            margin: 0;
            color: var(--side-text);
            font-size: 13px;
            line-height: 1.6;
        }

        @media (min-width: 860px) {
            .auth-main {
                padding: 32px 36px 24px;
            }
        }

        .auth-top {
            position: absolute;
            top: 12px;
            right: 12px;
        }

        .auth-form {
            width: 100%;
        }

        .auth-logo {
            height: 30px;
            width: auto;
            display: block;
            margin-bottom: 20px;
        }

        .auth-logo {
            background: #FFFFFF;
            padding: 5px 8px;
            border-radius: 6px;
            box-sizing: content-box;
        }

        .auth-form h1 {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.015em;
            margin: 0 0 4px;
        }

        .auth-form .lead {
            color: var(--side-text);
            margin: 0 0 20px;
            font-size: 12.5px;
        }

        .auth-form .field {
            margin-bottom: 12px;
        }

        .auth-form .input-icon-wrap input {
            min-height: 40px;
            font-size: 13.5px;
        }

        .auth-form .login-submit-btn {
            min-height: 40px;
            margin-top: 6px;
        }

        /* Pilihan aplikasi: HRIS / Monitoring 5R */
        .app-switch {
            position: relative;
            display: grid;
            grid-template-columns: 1fr 1fr;
            padding: 4px;
            margin: 0 0 18px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.10);
        }
        .app-switch::before {
            content: "";
            position: absolute;
            top: 4px;
            bottom: 4px;
            left: 4px;
            width: calc(50% - 4px);
            border-radius: 9px;
            background: var(--grad);
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
            transition: transform .28s cubic-bezier(.2, .8, .2, 1);
        }
        .app-switch[data-app="5r"]::before {
            transform: translateX(100%);
        }
        .app-switch button {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
            padding: 0 10px;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: var(--side-text);
            font: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: color .2s;
        }
        .app-switch button[aria-checked="true"] {
            color: #FFFFFF;
        }
        .app-switch button .material-symbols-outlined {
            font-size: 18px;
        }
        .app-switch button:focus-visible {
            outline: 2px solid #A5B4FC;
            outline-offset: 2px;
        }
        @media (hover: hover) {
            .app-switch button[aria-checked="false"]:hover {
                color: #FFFFFF;
            }
        }
        .auth-main .alert.info {
            background: rgba(165, 180, 252, 0.14);
            border: 1px solid rgba(165, 180, 252, 0.35);
            color: #E0E7FF;
        }
        .auth-swap {
            transition: opacity .18s ease;
        }
        .auth-swap.fading {
            opacity: 0;
        }
        @media (prefers-reduced-motion: reduce) {
            .app-switch::before, .auth-swap { transition: none; }
        }
        .auth-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            font-size: 11.5px;
            color: var(--side-muted);
        }
    </style>
</head>

<body>
    <main class="auth" aria-label="Halaman login">
        <div class="auth-card">
        <!-- Panel brand -->
        <section class="auth-brand" aria-hidden="true">
            <div class="auth-brand-top">
                <div class="logo-mark"><img src="assets/image/logo-IEG.png" alt=""></div>
                <div>
                    <b>PT. Inovasi Eka Gemilang</b>
                    <small>HRIS Inventory</small>
                </div>
            </div>

            <div>
                <div class="auth-swap" id="brandHris">
                <h2>Perencanaan tenaga kerja dalam satu tempat.</h2>
                <p>Request OS, approval supervisor, jadwal employee, dan absensi shift — terhubung otomatis.</p>
                <ul class="auth-feats">
                    <li>
                        <span class="material-symbols-outlined">fact_check</span>
                        <span>Request OS &amp; kuota vacant</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">verified</span>
                        <span>Approval supervisor otomatis</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">how_to_reg</span>
                        <span>Clock-in &amp; rekap MPP</span>
                    </li>
                </ul>
                </div>
                <div class="auth-swap hidden" id="brand5r">
                <h2>Area kerja rapi, temuan tertutup tepat waktu.</h2>
                <p>Lapor temuan 5R dengan foto, PIC memperbaiki, auditor memverifikasi — skor tim dihitung otomatis.</p>
                <ul class="auth-feats">
                    <li>
                        <span class="material-symbols-outlined">photo_camera</span>
                        <span>Temuan &amp; perbaikan berfoto</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">fact_check</span>
                        <span>Verifikasi &amp; audit checklist 5R</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">emoji_events</span>
                        <span>Papan poin tim &amp; PIC</span>
                    </li>
                </ul>
                </div>
            </div>

            <div class="auth-brand-foot">
                <span>Created by M. Solihin · 2026</span>
                <span>v<?= $appVer ?></span>
            </div>
        </section>

        <!-- Panel form -->
        <section class="auth-main">
            <div class="auth-top">
                <button class="btn ghost sm" type="button" id="themeBtn">
                    <span class="material-symbols-outlined theme-icon" id="themeIcon">dark_mode</span>
                    <span class="theme-label" id="themeLabel">Evening</span>
                </button>
            </div>

                <div class="auth-form">
                    <img class="auth-logo" src="assets/image/logo_text-BademdvM.jpg" alt="PT. Inovasi Eka Gemilang">
<?php if ($showMaintenance): ?>
                    <div class="mt-screen">
                        <div class="mt-ico"><span class="material-symbols-outlined">construction</span></div>
                        <h1>Sistem sedang maintenance</h1>
                        <p>Kami sedang melakukan pemeliharaan sistem.<br>Silakan coba kembali beberapa saat lagi.</p>
                    </div>
<?php else: ?>
<?php if ($maintenance): ?>
                    <div class="mt-banner"><span class="material-symbols-outlined">construction</span>Mode maintenance aktif — hanya super admin yang dapat masuk.</div>
<?php endif; ?>
                    <div class="app-switch" id="appSwitch" role="radiogroup" aria-label="Pilih aplikasi" data-app="hris">
                        <button type="button" role="radio" aria-checked="true" data-app="hris"><span class="material-symbols-outlined" aria-hidden="true">badge</span>HRIS</button>
                        <button type="button" role="radio" aria-checked="false" data-app="5r"><span class="material-symbols-outlined" aria-hidden="true">cleaning_services</span>Monitoring 5R</button>
                    </div>
                    <div class="auth-swap" id="formHead">
                        <h1 id="lgTitle">Masuk ke HRIS</h1>
                        <p class="lead" id="lgLead">Gunakan akun Admin, Supervisor, atau Leader/PIC.</p>
                    </div>

                    <form id="loginForm" class="login-form" autocomplete="on">
                        <label class="field">
                            <span id="lgUserLbl">Username</span>
                            <div class="input-icon-wrap">
                                <span class="material-symbols-outlined input-lead-icon" id="lgUserIco">person</span>
                                <input id="lgUser" name="username" autocomplete="username" autocapitalize="none" placeholder="Masukkan username" required autofocus>
                            </div>
                        </label>
                        <label class="field">
                            <span id="lgPassLbl">Password</span>
                            <div class="pw-wrap input-icon-wrap">
                                <span class="material-symbols-outlined input-lead-icon">lock</span>
                                <input id="lgPass" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required>
                                <button type="button" class="pw-eye" id="lgEye" aria-label="Tampilkan password" title="Tampilkan password">
                                    <span class="material-symbols-outlined eye-open">visibility</span>
                                    <span class="material-symbols-outlined eye-closed hidden">visibility_off</span>
                                </button>
                            </div>
                        </label>

                        <div id="loginErr" class="alert err hidden" role="alert"></div>

                        <button class="btn primary block lg login-submit-btn" id="lgBtn" type="submit">
                            <span id="lgBtnText">Masuk</span>
                            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                        </button>
                    </form>
<?php endif; ?>

                    <div class="auth-meta">
                        <span class="status-dot-online" aria-hidden="true" <?= $showMaintenance ? 'style="background:#FBBF24"' : '' ?>></span>
                        <span><?= $showMaintenance ? 'Maintenance' : 'Sistem aktif' ?> · v<?= $appVer ?></span>
                    </div>
                </div>
        </section>
        </div>
    </main>

    <script>
        (function () {
            // Pastikan URL di browser bersih tanpa ekstensi .php atau .html
            if (/\.(php|html)$/i.test(location.pathname)) {
                const cleanUrl = location.pathname.replace(/\/(login\.php|index\.php|Index\.html)$/i, '/login');
                history.replaceState(null, '', cleanUrl + location.search);
            }

            // Tema Morning / Evening
            let savedTheme = null;
            try { savedTheme = localStorage.getItem('hris_theme'); } catch (e) { }
            savedTheme = savedTheme || (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'evening' : 'morning');
            function setTheme(t) {
                document.documentElement.setAttribute('data-theme', t);
                try { localStorage.setItem('hris_theme', t); } catch (e) { }
                document.getElementById('themeLabel').textContent = t === 'morning' ? 'Evening' : 'Morning';
                document.getElementById('themeIcon').textContent = t === 'morning' ? 'dark_mode' : 'light_mode';
                const meta = document.querySelector('meta[name=theme-color]');
                if (meta) meta.content = t === 'morning' ? '#4F46E5' : '#12171C';
            }
            setTheme(savedTheme);
            document.getElementById('themeBtn').addEventListener('click', function () {
                setTheme(document.documentElement.getAttribute('data-theme') === 'morning' ? 'evening' : 'morning');
            });

            // Layar maintenance: tidak ada form login
            if (!document.getElementById('loginForm')) return;

            // Toggle lihat password
            const passInput = document.getElementById('lgPass');
            const eyeBtn = document.getElementById('lgEye');
            eyeBtn.addEventListener('click', function () {
                const show = passInput.type === 'password';
                passInput.type = show ? 'text' : 'password';
                eyeBtn.querySelector('.eye-open').classList.toggle('hidden', show);
                eyeBtn.querySelector('.eye-closed').classList.toggle('hidden', !show);
            });

            // Proses submit login
            const form = document.getElementById('loginForm');
            const errBox = document.getElementById('loginErr');
            const submitBtn = document.getElementById('lgBtn');
            const submitText = document.getElementById('lgBtnText');
            const userInput = document.getElementById('lgUser');
            const baseDir = location.pathname.replace(/\/(login(\.php)?|index\.php|Index\.html)\/?$/i, '').replace(/\/+$/, '');

            // Pilihan aplikasi: HRIS atau Monitoring 5R (satu form login)
            const params = new URLSearchParams(location.search);
            const APPS = {
                hris: { title: 'Masuk ke HRIS', lead: 'Gunakan akun Admin, Supervisor, atau Leader/PIC.', user: 'Username', userPh: 'Masukkan username', ico: 'person', pass: 'Password', passPh: 'Masukkan password', mode: 'text' },
                '5r': { title: 'Masuk ke Monitoring 5R', lead: 'Gunakan akun HRIS yang sama — satu akun untuk HRIS dan 5R.', user: 'Username', userPh: 'Masukkan username', ico: 'person', pass: 'Password', passPh: 'Masukkan password', mode: 'text' }
            };
            let app = params.get('app') === '5r' ? '5r' : (params.get('app') === 'hris' ? 'hris' : null);
            if (!app) { try { app = localStorage.getItem('login_app'); } catch (e) { } }
            if (app !== '5r') app = 'hris';
            const sw = document.getElementById('appSwitch');
            function fade(els, fn) {
                els.forEach(el => el && el.classList.add('fading'));
                setTimeout(() => { fn(); els.forEach(el => el && el.classList.remove('fading')); }, 140);
            }
            function setApp(a, animate) {
                app = a;
                try { localStorage.setItem('login_app', a); } catch (e) { }
                const c = APPS[a];
                sw.dataset.app = a;
                sw.querySelectorAll('button').forEach(b => b.setAttribute('aria-checked', String(b.dataset.app === a)));
                const apply = () => {
                    document.getElementById('lgTitle').textContent = c.title;
                    document.getElementById('lgLead').textContent = c.lead;
                    document.getElementById('lgUserLbl').textContent = c.user;
                    document.getElementById('lgUserIco').textContent = c.ico;
                    document.getElementById('lgPassLbl').textContent = c.pass;
                    userInput.placeholder = c.userPh;
                    passInput.placeholder = c.passPh;
                    passInput.setAttribute('inputmode', c.mode);
                    document.getElementById('brandHris').classList.toggle('hidden', a !== 'hris');
                    document.getElementById('brand5r').classList.toggle('hidden', a !== '5r');
                    document.title = (a === '5r' ? 'Masuk · Monitoring 5R' : 'Masuk · HRIS Inventory') + ' PT. Inovasi Eka Gemilang';
                };
                if (animate) fade([document.getElementById('formHead'), document.getElementById('brandHris'), document.getElementById('brand5r')], apply);
                else apply();
            }
            sw.querySelectorAll('button').forEach(b => b.addEventListener('click', function () {
                if (b.dataset.app === app) return;
                errBox.classList.add('hidden');
                setApp(b.dataset.app, true);
                userInput.focus();
            }));
            sw.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
                e.preventDefault();
                setApp(app === 'hris' ? '5r' : 'hris', true);
                sw.querySelector('[data-app="' + app + '"]').focus();
            });
            setApp(app, false);

            // Pesan dari aplikasi (mis. sesi 5R berakhir)
            const msg = params.get('msg');
            if (msg) {
                errBox.textContent = msg.slice(0, 160);
                errBox.classList.remove('hidden');
                errBox.classList.add('info');
            }


            form.addEventListener('submit', async function (ev) {
                ev.preventDefault();
                errBox.classList.add('hidden');
                errBox.classList.remove('info');
                submitBtn.disabled = true;
                submitText.textContent = 'Memverifikasi…';

                try {
                    const res = await fetch('api.php?action=login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            username: document.getElementById('lgUser').value.trim(),
                            password: passInput.value
                        })
                    });
                    const r = await res.json();
                    if (!r || !r.ok) {
                        throw new Error(r && r.error ? r.error : 'Gagal masuk ke sistem');
                    }
                    localStorage.setItem('hris_token', r.data.token);
                    // Satu akun untuk dua aplikasi: pilihan menentukan tujuan setelah masuk.
                    // Password awal wajib diganti dulu di HRIS.
                    if (app === '5r' && !r.data.mustChange) {
                        const area = (params.get('area') || '').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 12);
                        window.location.href = (baseDir || '') + '/5r' + (area ? '?area=' + encodeURIComponent(area) : '');
                    } else {
                        window.location.href = (baseDir || '') + '/';
                    }
                } catch (err) {
                    errBox.textContent = err.message || 'Username atau password salah.';
                    errBox.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitText.textContent = 'Masuk';
                }
            });
        })();
    </script>
</body>

</html>
<?php
$html = ob_get_clean();
$html = str_replace('<!-- INCLUDE_STYLES -->', $styles, $html);
echo $html;
