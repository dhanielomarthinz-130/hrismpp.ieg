<?php
/**
 * landing.php : Halaman pembuka (Program 6S) sebelum login. Clean URL: /landing.
 * Isi halaman: landingpage.html. Pengunjung yang belum login di / (HRIS) atau /6s diarahkan ke sini dulu.
 */
require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Path relatif (login, assets/) selalu dari folder aplikasi, juga bila URL berakhiran "/landing/"
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = preg_replace('#/landing(\.php)?/?$#i', '/', $path);
if ($base === '' || substr($base, -1) !== '/') $base .= '/';

$html = (string)file_get_contents(__DIR__ . '/landingpage.html');
$html = preg_replace('/<head>/i', '<head>' . "\n" . '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">', $html, 1);
echo $html;
