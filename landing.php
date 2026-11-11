<?php
/**
 * landing.php : Halaman pembuka (Program 6S) sebelum login. Clean URL: /landing.
 * Isi halaman: landingpage.html. Pengunjung yang belum login di / (HRIS) atau /6s diarahkan ke sini dulu.
 * Data dinamis yang diisi di sini (penanda <!--...--> di landingpage.html):
 *  - POSTER  : gambar banner program dari assets/image/banner-6s.(jpg|png|webp), bila ada
 *  - AREA_N / AREAS : jumlah & nama area 6S aktif
 *  - P:KEY   : nilai poin dari pengaturan 6S (aturan nonaktif = 0)
 *  - DONATUR : foto & nama Supervisor Inventory dari data Operator HRIS
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/foto.php';
require_once __DIR__ . '/lima_r.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Path relatif (login, assets/, uploads/) selalu dari folder aplikasi, juga bila URL berakhiran "/landing/"
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = preg_replace('#/landing(\.php)?/?$#i', '/', $path);
if ($base === '' || substr($base, -1) !== '/') $base .= '/';

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Tanda sudah melihat landing page (dibutuhkan /login); berlaku selama sesi browser
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
setcookie('hris_lp', '1', ['expires' => 0, 'path' => $base, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);

// Parameter tujuan login yang dibawa dari /login (mis. ?app=6s&area=A01&msg=...)
$lq = [];
if (in_array($_GET['app'] ?? '', ['hris', '5r', '6s'], true)) $lq['app'] = $_GET['app'];
$ar = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['area'] ?? '')), 0, 12);
if ($ar !== '') $lq['area'] = $ar;
$mg = mb_substr(trim((string)($_GET['msg'] ?? '')), 0, 200);
if ($mg !== '') $lq['msg'] = $mg;

/** Operator aktif ber-role Supervisor (Supervisor Inventory didahulukan, yang punya foto didahulukan). */
function landing_donatur(): array {
    try {
        $rows = db_all("SELECT nama, role, foto FROM operator WHERE aktif = 1 AND LOWER(role) LIKE '%supervis%' AND LOWER(nama) NOT LIKE '%vacant%'");
    } catch (Throwable $ex) { return []; }
    usort($rows, function ($a, $b) {
        $ia = stripos((string)$a['role'], 'invent') !== false ? 0 : 1;
        $ib = stripos((string)$b['role'], 'invent') !== false ? 0 : 1;
        $fa = foto_url($a['foto'] ?? '') !== '' ? 0 : 1;
        $fb = foto_url($b['foto'] ?? '') !== '' ? 0 : 1;
        return ($ia <=> $ib) ?: ($fa <=> $fb) ?: strcasecmp((string)$a['nama'], (string)$b['nama']);
    });
    // Ada Supervisor Inventory -> hanya mereka; selain itu supervisor mana pun
    $inv = array_values(array_filter($rows, fn($r) => stripos((string)$r['role'], 'invent') !== false));
    return array_slice($inv ?: $rows, 0, 3);
}

$html = (string)file_get_contents(__DIR__ . '/landingpage.html');

// Periode program = bulan berjalan (poin 6S dihitung per bulan), mis. "1 – 30 November 2026"
$bln = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$html = preg_replace('#<!--PERIODE-->.*?<!--/PERIODE-->#s', '1 – ' . $now->format('t') . ' ' . $bln[(int)$now->format('n') - 1] . ' ' . $now->format('Y'), $html, 1);

// Poster program (opsional)
foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    $f = 'assets/image/banner-6s.' . $ext;
    if (!is_file(__DIR__ . '/' . $f)) continue;
    $v = filemtime(__DIR__ . '/' . $f);
    $poster = '<section class="sec" style="padding-top:8px;padding-bottom:20px"><div class="wrap"><figure class="poster rv" style="margin:0">' .
        '<img src="' . $e($f) . '?v=' . $v . '" alt="Budayakan 6S, raih hadiah ratusan ribu & kumpulkan banyak poin — Program 6S PT Inovasi Eka Gemilang" loading="lazy" ' .
        'style="width:100%;height:auto;border-radius:24px;box-shadow:var(--shadow-lg);outline:1px solid var(--line)"></figure></div></section>';
    $html = preg_replace('#<!--POSTER-->.*?<!--/POSTER-->#s', $poster, $html, 1);
    break;
}

// Area 6S aktif & nilai poin (tabel 6S bisa belum ada di instalasi baru: pakai isi bawaan halaman)
try {
    $areas = db_all("SELECT nama FROM r5_area WHERE aktif <> 'N' ORDER BY area_id");
    if ($areas) {
        $html = preg_replace('#<!--AREA_N-->.*?<!--/AREA_N-->#s', (string)count($areas), $html);
        $html = preg_replace('#<!--AREAS-->.*?<!--/AREAS-->#s', implode('', array_map(fn($a) => '<li>' . $e($a['nama']) . '</li>', $areas)), $html, 1);
    }
    $html = preg_replace_callback('#<!--P:([A-Z0-9_]+)-->.*?<!--/P-->#s', function ($m) {
        $v = r5_num($m[1], 0);
        return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
    }, $html);
} catch (Throwable $ex) { /* biarkan nilai bawaan */ }

$don = landing_donatur();
if ($don) {
    $cards = '';
    $besar = count($don) === 1;
    foreach ($don as $d) {
        $url = foto_url($d['foto'] ?? '');
        $ini = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim((string)$d['nama'])), 0, 2))));
        $img = $url !== ''
            ? '<img src="' . $e($url) . '" alt="Foto ' . $e($d['nama']) . '" style="width: 100%; height: 100%; object-fit: cover; display: block">'
            : '<span style="font-size: 26px; font-weight: 800">' . $e($ini) . '</span>';
        $sz = $besar ? '120px' : '72px';
        $cards .= '<div style="display: flex; align-items: center; gap: 16px; padding: 14px; border-radius: 18px; background: rgba(255, 255, 255, .96); box-shadow: 0 10px 24px -12px rgba(0,0,0,.4)">' .
            '<span style="flex: none; width: ' . $sz . '; height: ' . $sz . '; border-radius: 16px; overflow: hidden; background: #FEF3C7; color: #B45309; display: flex; align-items: center; justify-content: center; border: 3px solid #FDE68A">' . $img . '</span>' .
            '<div style="min-width: 0"><div style="font-size: ' . ($besar ? '20px' : '17px') . '; font-weight: 800; color: #1E1B4B">' . $e($d['nama']) . '</div>' .
            '<div style="font-size: 13px; color: #92400E; font-weight: 700">' . $e($d['role'] ?: 'Supervisor') . '</div>' .
            '<div style="font-size: 12.5px; color: #6B7280; margin-top: 2px">Donatur hadiah Program 6S</div></div></div>';
    }
    $html = preg_replace('#<!--DONATUR-->.*?<!--/DONATUR-->#s', $cards, $html, 1);
}
// Tombol Login membawa parameter tujuan
if ($lq) {
    $html = str_replace('href="login"', 'href="login?' . $e(http_build_query($lq)) . '"', $html);
    $html = str_replace('href="login?app=6s"', 'href="login?' . $e(http_build_query(['app' => '6s'] + $lq)) . '"', $html);
}
$html = preg_replace('/<head>/i', '<head>' . "\n" . '<base href="' . $e($base) . '">', $html, 1);
echo $html;
