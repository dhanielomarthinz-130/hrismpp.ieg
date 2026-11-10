<?php
/**
 * 6s.php : Halaman Monitoring 6S (Clean URL: /6s; /5r lama dialihkan ke sini). Login lewat /login (pilih "Monitoring 6S").
 * Frontend: App6S.html, backend: api6s.php + lima_r.php.
 */
require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = preg_replace('#/6s(\.php)?/?$#i', '/', $path);
if ($base === '' || substr($base, -1) !== '/') $base .= '/';

$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host   = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$appUrl = ($https ? 'https' : 'http') . '://' . $host . $base . '6s';

$area = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['area'] ?? '')), 0, 12);

$js = fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$html = (string)file_get_contents(__DIR__ . '/App6S.html');
$html = str_replace(
    ['<?!= JSON.stringify(areaParam) ?>', '<?!= JSON.stringify(appUrl) ?>', '<?!= JSON.stringify(loginUrl) ?>'],
    [$js($area), $js($appUrl), $js($base . 'login')],
    $html
);
// Path relatif (api6s.php, uploads/, assets/) selalu dari folder aplikasi, juga bila URL berakhiran "/6s/"
$html = preg_replace('/<head>/i', '<head>' . "\n" . '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">', $html, 1);
echo $html;
