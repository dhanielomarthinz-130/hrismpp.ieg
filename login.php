<?php
/**
 * login.php : Halaman Form Login Dedicated (Clean URL: /login)
 * HRIS PP Inventory PT. Inovasi Eka Gemilang
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
    <meta name="theme-color" content="#F4F5F8">
    <link rel="icon" type="image/png" href="assets/image/logo-IEG.png">
    <link rel="apple-touch-icon" href="assets/image/logo-IEG.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
    <title>Masuk · HRIS PP Inventory PT. Inovasi Eka Gemilang</title>
    <!-- INCLUDE_STYLES -->
    <style>
        .auth {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            background: var(--bg);
        }

        @media (min-width: 900px) {
            .auth {
                grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
            }
        }

        /* Panel brand */
        .auth-brand {
            display: none;
            position: relative;
            overflow: hidden;
            background: var(--side-bg);
            color: #FFFFFF;
            padding: 40px 48px;
            flex-direction: column;
            justify-content: space-between;
        }

        @media (min-width: 900px) {
            .auth-brand {
                display: flex;
            }
        }

        .auth-brand-top {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .auth-brand-top .logo-mark {
            width: 40px;
            height: 40px;
        }

        .auth-brand-top b {
            display: block;
            font-size: 14px;
            font-weight: 600;
        }

        .auth-brand-top small {
            display: block;
            font-size: 12px;
            color: var(--side-muted);
        }

        .auth-brand h2 {
            font-size: 30px;
            line-height: 1.2;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0 0 14px;
            max-width: 420px;
        }

        .auth-brand p {
            margin: 0 0 28px;
            color: var(--side-text);
            font-size: 14px;
            line-height: 1.6;
            max-width: 400px;
        }

        .auth-feats {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 16px;
        }

        .auth-feats li {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13px;
            color: var(--side-text);
        }

        .auth-feats li b {
            display: block;
            color: #FFFFFF;
            font-weight: 600;
            font-size: 13.5px;
        }

        .auth-feats .material-symbols-outlined {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.07);
            color: #8FD0F2;
            font-size: 18px;
            flex: none;
        }

        .auth-brand-foot {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--side-muted);
        }

        /* Panel form */
        .auth-main {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding: 20px 20px 24px;
        }

        .auth-top {
            display: flex;
            justify-content: flex-end;
        }

        .auth-form-wrap {
            flex: 1;
            display: grid;
            place-items: center;
            padding: 24px 0;
        }

        .auth-form {
            width: 100%;
            max-width: 380px;
        }

        .auth-logo {
            height: 38px;
            width: auto;
            display: block;
            margin-bottom: 28px;
        }

        [data-theme="evening"] .auth-logo {
            background: #FFFFFF;
            padding: 6px 10px;
            border-radius: 8px;
            box-sizing: content-box;
        }

        .auth-form h1 {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0 0 6px;
        }

        .auth-form .lead {
            color: var(--text-2);
            margin: 0 0 26px;
            font-size: 13.5px;
        }

        .auth-meta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 22px;
            font-size: 12px;
            color: var(--text-3);
        }
    </style>
</head>

<body>
    <main class="auth" aria-label="Halaman login">
        <!-- Panel brand -->
        <section class="auth-brand" aria-hidden="true">
            <div class="auth-brand-top">
                <div class="logo-mark"><img src="assets/image/logo-IEG.png" alt=""></div>
                <div>
                    <b>PT. Inovasi Eka Gemilang</b>
                    <small>HRIS PP Inventory</small>
                </div>
            </div>

            <div>
                <h2>Perencanaan tenaga kerja, dalam satu tempat.</h2>
                <p>Request OS, approval supervisor, jadwal mingguan, dan absensi shift — terhubung otomatis.</p>
                <ul class="auth-feats">
                    <li>
                        <span class="material-symbols-outlined">fact_check</span>
                        <span><b>Request OS &amp; kuota vacant</b>Ajukan kebutuhan MP per divisi dan shift.</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">verified</span>
                        <span><b>Approval supervisor</b>Disetujui langsung masuk jadwal &amp; absensi.</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">how_to_reg</span>
                        <span><b>Clock-in &amp; rekap MPP</b>Pantau pemenuhan kehadiran setiap shift.</span>
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

            <div class="auth-form-wrap">
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
            </div>
        </section>
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
                if (meta) meta.content = t === 'morning' ? '#F4F5F8' : '#0B0D13';
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
