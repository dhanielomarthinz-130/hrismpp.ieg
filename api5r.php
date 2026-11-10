<?php
/**
 * api5r.php : Endpoint JSON Monitoring 5R.
 * Body: {"fn": "apiDashboard", "args": [token, ...]} -> {"ok": true, "data": ...} | {"ok": false, "error": "..."}
 * token = token sesi HRIS (login di /login memakai username + password HRIS).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak didukung.']);
    exit;
}

require_once __DIR__ . '/lima_r.php';

$in = json_decode((string)file_get_contents('php://input'), true);
$fn = is_array($in) ? (string)($in['fn'] ?? '') : '';
$args = is_array($in) && is_array($in['args'] ?? null) ? array_values($in['args']) : [];

try {
    if (!in_array($fn, R5_API, true)) throw new InvalidArgumentException('Fungsi tidak dikenal.');
    r5_init();
    $data = $fn(...$args);   // maintenance & sesi dicek oleh auth_check() HRIS di dalam r5_auth()
    echo json_encode(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    $safe = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !($e instanceof PDOException);
    if (!$safe) error_log('[5R API] ' . $fn . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => $safe ? $e->getMessage() : 'Terjadi kesalahan pada server. Silakan coba lagi atau hubungi Admin.']);
}
