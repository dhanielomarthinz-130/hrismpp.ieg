<?php
/**
 * export.php : Unduh Excel (CSV) jadwal & rekap, tempel jadwal dari Excel, dan utilitas bot WhatsApp.
 */
require_once __DIR__ . '/db.php';

/** Bangun file CSV (pemisah ';' agar langsung rapi di Excel Indonesia) sebagai base64 untuk diunduh browser. */
function csv_download_(string $name, array $header, array $rows): array {
    $esc = function ($v): string {
        $v = (string)$v;
        // Cegah CSV/formula injection di Excel
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $lines = [implode(';', array_map($esc, $header))];
    foreach ($rows as $r) $lines[] = implode(';', array_map($esc, $r));
    $csv = "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    return ['name' => $name, 'mime' => 'text/csv;charset=utf-8', 'base64' => base64_encode($csv)];
}

/** Unduh jadwal mingguan (Jadwal Employee). */
function minggu_export(array $p, array $currentUser): array {
    $J = jadwal_minggu(['tanggal' => (string)($p['tanggal'] ?? date('Y-m-d'))], $currentUser);
    $bagian = strtoupper(trim((string)($p['bagian'] ?? '')));
    $labels = [];
    foreach ($J['kodes'] as $k) $labels[$k['kode']] = $k['label'];

    $header = ['No', 'Nama', 'NIK', 'Bagian', 'Employer', 'Role'];
    foreach ($J['dates'] as $d) $header[] = $d['hari'] . ' ' . substr($d['d'], 8, 2) . '/' . substr($d['d'], 5, 2);
    $header[] = 'Jam';
    $header[] = 'Hari kerja';

    $rows = [];
    $no = 0;
    foreach ($J['rows'] as $r) {
        if ($bagian !== '' && $r['bagian'] !== $bagian) continue;
        $line = [++$no, $r['nama'], $r['nik'], $r['bagian'], $r['employer'], $r['role']];
        foreach ($r['codes'] as $c) $line[] = $c === '' ? '' : ($labels[$c] ?? $c);
        $line[] = $r['jam'];
        $line[] = $r['hari'] . '/' . $r['pola'];
        $rows[] = $line;
    }
    return csv_download_('Jadwal_' . $J['monday'] . ($bagian ? '_' . $bagian : '') . '.csv', $header, $rows);
}

/** Unduh rekap MPP untuk rentang tanggal. */
function rekap_export(array $p, array $currentUser): array {
    $d = rekap_mpp($p, $currentUser);
    $header = ['Tanggal', 'Hari', 'Shift', 'Jam masuk', 'Request', 'Terisi', 'Vacant', 'Hadir', 'Kurang', '%', 'Nama request', 'Nama hadir', 'Status request', 'Status realisasi'];
    $rows = [];
    foreach ($d['rows'] as $r) {
        $rows[] = [$r['tanggal'], $r['hari'], $r['shiftNama'], $r['jamMasuk'], $r['request'], $r['terisi'], $r['vacant'],
            $r['hadir'] ?? '', $r['kurang'], $r['persen'] ?? '', $r['namaRequest'], $r['namaHadir'], $r['statusRequest'], $r['statusRealisasi']];
    }
    return csv_download_('Rekap_MPP_' . $d['from'] . '_sd_' . $d['to'] . '.csv', $header, $rows);
}

/**
 * Tempel jadwal dari Excel/Sheet: setiap baris dicocokkan lewat NIK atau nama,
 * 7 kolom terakhir dibaca sebagai Senin–Minggu.
 */
function minggu_import(array $p, array $currentUser): array {
    if (empty($currentUser['super']) && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Hanya Admin / Supervisor yang dapat menempel jadwal.');
    }
    $text = str_replace("\r", '', (string)($p['text'] ?? ''));
    if (trim($text) === '') throw new InvalidArgumentException('Tempel data jadwal terlebih dahulu.');
    if (strlen($text) > 500000) throw new InvalidArgumentException('Data terlalu besar.');

    $monday = monday_of((string)($p['tanggal'] ?? date('Y-m-d')));
    $dates = week_dates($monday);

    // Peta teks -> kode jadwal (kode, label, atau nama; tanpa beda huruf besar/kecil & spasi)
    $norm = fn($s) => strtoupper(preg_replace('/\s+/', '', (string)$s));
    $kmap = [];
    foreach (kode_list_() as $k) {
        foreach ([$k['kode'], $k['label'], $k['nama']] as $t) {
            if ($t !== '') $kmap[$norm($t)] = $k['kode'];
        }
    }
    $byNik = [];
    $byNama = [];
    foreach (operators_() as $o) {
        if ($o['nik'] !== '') $byNik[$norm($o['nik'])] = $o['id'];
        $byNama[$norm($o['nama'])] = $o['id'];
    }

    $changes = [];
    $errors = [];
    $matched = 0;
    foreach (array_slice(explode("\n", $text), 0, 2000) as $i => $line) {
        if (trim($line) === '') continue;
        $cols = array_map('trim', explode("\t", $line));
        if (count($cols) < 8) { $errors[] = 'Baris ' . ($i + 1) . ': kolom kurang (butuh identitas + 7 hari).'; continue; }
        $days = array_slice($cols, -7);
        $ident = array_slice($cols, 0, -7);
        $opId = null;
        foreach ($ident as $c) { if ($c !== '' && isset($byNik[$norm($c)])) { $opId = $byNik[$norm($c)]; break; } }
        if (!$opId) foreach ($ident as $c) { if ($c !== '' && isset($byNama[$norm($c)])) { $opId = $byNama[$norm($c)]; break; } }
        if (!$opId) { $errors[] = 'Baris ' . ($i + 1) . ': karyawan tidak ditemukan (' . mb_substr(implode(' ', array_filter($ident)), 0, 40) . ').'; continue; }
        $matched++;
        foreach ($days as $di => $t) {
            if ($t === '' || $t === '-') continue;
            $kd = $kmap[$norm($t)] ?? null;
            if (!$kd) { $errors[] = 'Baris ' . ($i + 1) . ', ' . $dates[$di] . ': kode "' . mb_substr($t, 0, 15) . '" tidak dikenal.'; continue; }
            $changes[] = ['operatorId' => $opId, 'tanggal' => $dates[$di], 'kode' => $kd];
        }
    }

    if (!$changes) return ['ok' => false, 'matched' => $matched, 'saved' => 0, 'errors' => $errors ?: ['Tidak ada sel jadwal yang dapat dibaca.']];
    $r = jadwal_simpan(['tanggal' => $monday, 'changes' => $changes, 'force' => !empty($p['force'])], $currentUser);
    return ['ok' => !empty($r['ok']), 'matched' => $matched, 'saved' => (int)($r['saved'] ?? 0), 'warnings' => $r['warnings'] ?? [], 'errors' => $errors];
}

/** Token untuk bot WhatsApp kantor (disimpan di settings). */
function bot_info_(): array {
    return [
        'token'   => (string)get_setting('bot_token', ''),
        'url'     => '',
        'online'  => false,
        'pending' => (int)db_val("SELECT COUNT(*) FROM outbox_wa WHERE status = 'ANTRE'", [], 0),
    ];
}

function bot_token_new(array $currentUser): array {
    set_settings(['bot_token' => bin2hex(random_bytes(24))]);
    audit_log($currentUser['username'], 'BOT_TOKEN', 'Token bot dibuat ulang');
    return bot_info_();
}
