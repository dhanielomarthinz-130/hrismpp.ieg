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

ob_start();
?>
<!DOCTYPE html>
<html lang="id" data-theme="morning">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4F46E5">
    <link rel="icon" type="image/png" href="assets/image/logo-IEG.png">
    <link rel="apple-touch-icon" href="assets/image/logo-IEG.png">
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
            background: var(--surface);
            border: 1px solid var(--border);
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
            background: var(--side-bg);
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

        [data-theme="evening"] .auth-logo {
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
            color: var(--text-2);
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

        .auth-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            font-size: 11.5px;
            color: var(--text-3);
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
                    <h1>Masuk ke akun Anda</h1>
                    <p class="lead">Gunakan akun Admin, Supervisor, atau Leader/PIC.</p>

                    <form id="loginForm" class="login-form" autocomplete="on">
                        <label class="field">
                            <span>Username</span>
                            <div class="input-icon-wrap">
                                <span class="material-symbols-outlined input-lead-icon">person</span>
                                <input id="lgUser" name="username" autocomplete="username" autocapitalize="none" placeholder="Masukkan username" required autofocus>
                            </div>
                        </label>
                        <label class="field">
                            <span>Password</span>
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

                    <div class="auth-meta">
                        <span class="status-dot-online" aria-hidden="true"></span>
                        <span>Sistem aktif · v<?= $appVer ?></span>
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

            form.addEventListener('submit', async function (ev) {
                ev.preventDefault();
                errBox.classList.add('hidden');
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
                    const baseDir = location.pathname.replace(/\/(login(\.php)?|index\.php|Index\.html)\/?$/i, '').replace(/\/+$/, '');
                    window.location.href = (baseDir || '') + '/';
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
