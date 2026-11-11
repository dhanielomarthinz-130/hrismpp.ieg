<?php
/**
 * landing.php : Halaman pembuka (Program 6S) sebelum login. Clean URL: /landing.
 * Isi halaman: landingpage.html. Pengunjung yang belum login di / (HRIS) atau /6s diarahkan ke sini dulu.
 * Banner hadiah: foto & nama Supervisor Inventory (donatur) diambil dari data Operator HRIS.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/foto.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Path relatif (login, assets/, uploads/) selalu dari folder aplikasi, juga bila URL berakhiran "/landing/"
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = preg_replace('#/landing(\.php)?/?$#i', '/', $path);
if ($base === '' || substr($base, -1) !== '/') $base .= '/';

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

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
$don = landing_donatur();
if ($don) {
    $cards = '';
    foreach ($don as $d) {
        $url = foto_url($d['foto'] ?? '');
        $ini = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim((string)$d['nama'])), 0, 2))));
        $img = $url !== ''
            ? '<img src="' . $e($url) . '" alt="Foto ' . $e($d['nama']) . '" style="width: 100%; height: 100%; object-fit: cover; display: block">'
            : '<span style="font-size: 26px; font-weight: 800">' . $e($ini) . '</span>';
        $besar = count($don) === 1;
        $sz = $besar ? '120px' : '72px';
        $cards .= '<div style="display: flex; align-items: center; gap: 16px; padding: 14px; border-radius: 18px; background: rgba(255, 255, 255, .95); box-shadow: 0 8px 20px -10px rgba(0,0,0,.35)">' .
            '<span style="flex: none; width: ' . $sz . '; height: ' . $sz . '; border-radius: 16px; overflow: hidden; background: #FEF3C7; color: #B45309; display: flex; align-items: center; justify-content: center; border: 3px solid #FDE68A">' . $img . '</span>' .
            '<div style="min-width: 0"><div style="font-size: ' . ($besar ? '20px' : '17px') . '; font-weight: 800; color: #1E1B4B">' . $e($d['nama']) . '</div>' .
            '<div style="font-size: 13px; color: #92400E; font-weight: 700">' . $e($d['role'] ?: 'Supervisor') . '</div>' .
            '<div style="font-size: 12.5px; color: #6B7280; margin-top: 2px">Donatur hadiah Program 6S</div></div></div>';
    }
    $html = preg_replace('#<!--DONATUR-->.*?<!--/DONATUR-->#s', $cards, $html, 1);
}
$html = preg_replace('/<head>/i', '<head>' . "\n" . '<base href="' . $e($base) . '">', $html, 1);
echo $html;
