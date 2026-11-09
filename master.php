<?php
/**
 * master.php : Master data (bagian, operator, shift, kode jadwal, setting) dan ringkasan Dashboard.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

const STATUS_MAP = [
    'H' => 'Hadir',
    'T' => 'Telat',
    'S' => 'Sakit',
    'I' => 'Izin',
    'A' => 'Alpa',
    'C' => 'Cuti'
];

const HARI_LIST = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const BULAN_LIST = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const BULAN_PANJANG = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

function time_to_min(string $hhmm): int {
    if (preg_match('/^(\d{1,2})[:.](\d{2})/', trim($hhmm), $m)) {
        return ((int)$m[1] * 60) + (int)$m[2];
    }
    return 0;
}

function fmt_tanggal(string $ymd): string {
    $time = strtotime($ymd);
    if (!$time) return $ymd;
    $dow = (int)date('w', $time);
    $d = (int)date('j', $time);
    $m = (int)date('n', $time) - 1;
    $y = date('Y', $time);
    return HARI_LIST[$dow] . ', ' . $d . ' ' . BULAN_LIST[$m] . ' ' . $y;
}

function shifts_(): array {
    $rows = db_all("SELECT * FROM shift ORDER BY urutan ASC, kode ASC");
    $out = [];
    foreach ($rows as $r) {
        $mulai = (string)$r['mulai'];
        $selesai = (string)$r['selesai'];
        $s = time_to_min($mulai);
        $e = time_to_min($selesai);
        if ($e <= $s) $e += 1440;
        $out[] = [
            'kode'     => strtoupper((string)$r['kode']),
            'nama'     => (string)$r['nama'],
            'mulai'    => $mulai,
            'selesai'  => $selesai,
            'kirim'    => (string)($r['kirim'] ?? ''),
            'kirimReq' => (string)($r['kirim_req'] ?? ''),
            'urutan'   => (int)$r['urutan'],
            'startMin' => $s,
            'endMin'   => $e,
            'jam'      => ($e - $s) / 60
        ];
    }
    return $out;
}

function shift_map_(): array {
    $m = [];
    foreach (shifts_() as $s) {
        $m[$s['kode']] = $s;
    }
    return $m;
}

function current_shift_(?array $shifts = null): array {
    $shifts = $shifts ?: shifts_();
    $today = date('Y-m-d');
    $m = time_to_min(date('H:i'));

    foreach ($shifts as $s) {
        if ($m >= $s['startMin'] && $m < $s['endMin']) {
            return ['tanggal' => $today, 'kode' => $s['kode'], 'berjalan' => true];
        }
    }
    foreach ($shifts as $s) {
        if ($s['endMin'] > 1440 && ($m + 1440) >= $s['startMin'] && ($m + 1440) < $s['endMin']) {
            return ['tanggal' => date('Y-m-d', strtotime('-1 day', strtotime($today))), 'kode' => $s['kode'], 'berjalan' => true];
        }
    }
    foreach ($shifts as $s) {
        if ($s['startMin'] > $m) {
            return ['tanggal' => $today, 'kode' => $s['kode'], 'berjalan' => false];
        }
    }
    $firstCode = !empty($shifts) ? $shifts[0]['kode'] : 'S1';
    return ['tanggal' => date('Y-m-d', strtotime('+1 day', strtotime($today))), 'kode' => $firstCode, 'berjalan' => false];
}

function bagian_(): array {
    $rows = db_all("SELECT * FROM bagian ORDER BY urutan ASC, nama ASC");
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'kode'     => strtoupper((string)$r['kode']),
            'nama'     => (string)($r['nama'] ?: $r['kode']),
            'targetS1' => (int)$r['target_s1'],
            'targetS2' => (int)$r['target_s2'],
            'urutan'   => (int)($r['urutan'] ?? 99),
            'aktif'    => (int)$r['aktif'] === 1,
            'judulWa'  => (string)($r['judul_wa'] ?: $r['nama']),
            'mention'  => (string)($r['mention'] ?? ''),
            'order'    => (int)($r['order_mpp'] ?? 1) === 1
        ];
    }
    return $out;
}

function operators_(): array {
    $rows = db_all("SELECT * FROM operator ORDER BY nama ASC");
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (string)$r['id'],
            'nik'      => (string)($r['nik'] ?? ''),
            'nama'     => (string)$r['nama'],
            'bagian'   => strtoupper((string)$r['bagian']),
            'grup'     => strtoupper((string)($r['grup'] ?? 'A')),
            'libur'    => (string)($r['libur'] ?? ''),
            'hp'       => (string)($r['hp'] ?? ''),
            'aktif'    => (int)$r['aktif'] === 1,
            'tglMasuk' => (string)($r['tgl_masuk'] ?? ''),
            'ket'      => (string)($r['ket'] ?? ''),
            'pola'     => (int)$r['pola'] === 5 ? 5 : 6,
            'employer' => (string)($r['employer'] ?? ''),
            'role'     => (string)($r['role'] ?? ''),
            'forklift' => (int)($r['forklift'] ?? 0) === 1,
            'foto'     => foto_url($r['foto'] ?? '')
        ];
    }

    // Sort VACANT to the very bottom
    usort($out, function ($a, $b) {
        $vacA = stripos($a['nama'], 'vacant') !== false ? 1 : 0;
        $vacB = stripos($b['nama'], 'vacant') !== false ? 1 : 0;
        if ($vacA !== $vacB) return $vacA - $vacB;
        return strcasecmp($a['nama'], $b['nama']);
    });

    return $out;
}

function kode_list_(): array {
    $rows = db_all("SELECT * FROM kode_jadwal ORDER BY urutan ASC, kode ASC");
    $out = [];
    foreach ($rows as $r) {
        $mulai = preg_match('/^\d{2}:\d{2}$/', (string)($r['mulai'] ?? '')) ? (string)$r['mulai'] : '';
        $selesai = preg_match('/^\d{2}:\d{2}$/', (string)($r['selesai'] ?? '')) ? (string)$r['selesai'] : '';
        $hasTime = preg_match('/^\d{2}:\d{2}$/', $mulai) && preg_match('/^\d{2}:\d{2}$/', $selesai);
        $s = $hasTime ? time_to_min($mulai) : null;
        $e = $hasTime ? time_to_min($selesai) : null;
        if ($hasTime && $e <= $s) $e += 1440;

        $out[] = [
            'kode'     => strtoupper((string)$r['kode']),
            'label'    => (string)($r['label'] ?: $r['kode']),
            'nama'     => (string)($r['nama'] ?: $r['kode']),
            'mulai'    => $mulai,
            'selesai'  => $selesai,
            'startMin' => $s,
            'endMin'   => $e,
            'jam'      => (float)$r['jam'],
            'kerja'    => (int)$r['kerja'] === 1,
            'shift'    => strtoupper((string)($r['shift'] ?? '')),
            'warna'    => preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($r['warna'] ?? '')) ? (string)$r['warna'] : '#FFFFFF',
            'urutan'   => (int)$r['urutan']
        ];
    }
    return $out;
}

function kode_map_(): array {
    $m = [];
    foreach (kode_list_() as $k) {
        $m[$k['kode']] = $k;
    }
    return $m;
}

function settings_(): array {
    $rows = db_all("SELECT key_name, value FROM settings");
    $m = [];
    foreach ($rows as $r) {
        $m[$r['key_name']] = (string)$r['value'];
    }
    if (empty($m['app_nama'])) $m['app_nama'] = APP_NAME;
    if (empty($m['max_jam_minggu'])) $m['max_jam_minggu'] = '49';
    if (empty($m['min_istirahat'])) $m['min_istirahat'] = '8';
    if (empty($m['rotasi_anchor'])) $m['rotasi_anchor'] = date('Y-m-d', strtotime('monday this week'));
    if (empty($m['wa_provider'])) $m['wa_provider'] = 'MANUAL';
    return $m;
}

function get_setting(string $key, $default = '') {
    $s = settings_();
    return $s[$key] ?? $default;
}

function set_settings(array $obj): void {
    foreach ($obj as $k => $v) {
        $exists = db_val("SELECT key_name FROM settings WHERE key_name = ?", [$k]);
        if ($exists) {
            db_update('settings', ['value' => (string)$v], 'key_name = ?', [$k]);
        } else {
            db_insert('settings', ['key_name' => $k, 'value' => (string)$v, 'ket' => '']);
        }
    }
}

function get_master(array $currentUser): array {
    $shifts = shifts_();
    $s = settings_();

    return [
        'user'        => $currentUser,
        'maintenance' => maintenance_on(),
        'menus'       => menus_for_user($currentUser),
        'autoApprove' => auto_approve_on(),
        'today'       => date('Y-m-d'),
        'now'       => date('Y-m-d H:i:s'),
        'current'   => current_shift_($shifts),
        'shifts'    => $shifts,
        'bagian'    => bagian_(),
        'operators' => operators_(),
        'settings'  => [
            'app_nama'        => $s['app_nama'] ?? APP_NAME,
            'max_jam_minggu'  => (int)($s['max_jam_minggu'] ?? 49),
            'min_istirahat'   => (int)($s['min_istirahat'] ?? 8),
            'rotasi_anchor'   => (string)($s['rotasi_anchor'] ?? date('Y-m-d', strtotime('monday this week'))),
            'rotasi_periode'  => (int)($s['rotasi_periode'] ?? 1),
            'wa_provider'     => (string)($s['wa_provider'] ?? 'MANUAL'),
            'wa_auto'         => !empty($s['wa_auto']) && $s['wa_auto'] !== '0',
            'batas_mode'      => strtoupper((string)($s['batas_mode'] ?? 'MINGGU')) === 'BERJALAN' ? 'BERJALAN' : 'MINGGU',
            'pola6_half'      => ($s['pola6_half'] ?? '1') !== '0'
        ],
        'kodes'     => kode_list_(),
        'version'   => APP_VERSION
    ];
}

/* ============================== DASHBOARD MPP ============================== */

function target_of_bagian(array $b, string $kode): int {
    if ($kode === 'S1') return $b['targetS1'];
    if ($kode === 'S2') return $b['targetS2'];
    return 0;
}

function empty_group(string $kode, string $nama, int $target): array {
    return [
        'kode'    => $kode,
        'nama'    => $nama,
        'target'  => $target,
        'jadwal'  => 0,
        'hadir'   => 0,
        'H'       => 0,
        'T'       => 0,
        'S'       => 0,
        'I'       => 0,
        'A'       => 0,
        'C'       => 0,
        'BELUM'   => 0,
        'list'    => ['T' => [], 'S' => [], 'I' => [], 'A' => [], 'C' => [], 'BELUM' => []],
        'bko'     => []
    ];
}

function summary_(string $tanggal, string $kode): array {
    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;
    $bag = bagian_();
    $km = kode_map_();

    $groups = [];
    $order = [];
    foreach ($bag as $b) {
        $groups[$b['kode']] = empty_group($b['kode'], $b['nama'], target_of_bagian($b, $kode));
        $groups[$b['kode']]['_aktif'] = $b['aktif'];
        $order[] = $b['kode'];
    }

    $absRows = db_all("SELECT * FROM absensi WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
    $abs = [];
    foreach ($absRows as $r) {
        $abs[$r['operator_id']] = $r;
    }

    $jadRows = db_all("SELECT * FROM jadwal WHERE tanggal = ?", [$tanggal]);
    $cuti = [];
    $off = 0;

    foreach ($jadRows as $j) {
        $opId = $j['operator_id'];
        if (!isset($ops[$opId])) continue;
        $op = $ops[$opId];
        $sh = strtoupper((string)$j['shift']);

        if ($sh === 'CUTI') {
            $cuti[] = $op['nama'];
            continue;
        }

        $kInfo = $km[$sh] ?? null;
        $isWork = $kInfo && $kInfo['kerja'];
        if (!$isWork) {
            $off++;
            continue;
        }

        $refShift = $kInfo['shift'] ?: '';
        if ($refShift !== $kode) continue;

        $bt = strtoupper((string)($j['bagian_tugas'] ?: $op['bagian']));
        if (!isset($groups[$bt])) {
            $groups[$bt] = empty_group($bt, $bt, 0);
            $order[] = $bt;
        }

        $g = &$groups[$bt];
        $g['jadwal']++;

        $a = $abs[$opId] ?? null;
        $st = $a && isset(STATUS_MAP[strtoupper($a['status'])]) ? strtoupper($a['status']) : 'BELUM';
        $g[$st]++;

        if ($st !== 'H') {
            $g['list'][$st][] = $op['nama'];
        }
        if ($bt !== $op['bagian']) {
            $g['bko'][] = $op['nama'] . ' (dari ' . $op['bagian'] . ')';
        }
    }

    $total = empty_group('TOTAL', 'Total', 0);
    $list = [];
    foreach ($order as $k) {
        $g = $groups[$k];
        $g['hadir'] = $g['H'] + $g['T'];
        $aktif = $g['_aktif'] ?? true;
        unset($g['_aktif']);

        if (!$aktif && $g['jadwal'] === 0) continue;
        if ($g['target'] === 0 && $g['jadwal'] === 0) continue;

        $g['persen'] = $g['target'] > 0 ? (int)round($g['hadir'] / $g['target'] * 100)
            : ($g['jadwal'] > 0 ? (int)round($g['hadir'] / $g['jadwal'] * 100) : 0);

        foreach (['target', 'jadwal', 'hadir', 'H', 'T', 'S', 'I', 'A', 'C', 'BELUM'] as $f) {
            $total[$f] += $g[$f];
        }
        $list[] = $g;
    }

    $total['persen'] = $total['target'] > 0 ? (int)round($total['hadir'] / $total['target'] * 100)
        : ($total['jadwal'] > 0 ? (int)round($total['hadir'] / $total['jadwal'] * 100) : 0);
    $total['gap'] = $total['hadir'] - $total['target'];

    $sMap = shift_map_();
    $sh = $sMap[$kode] ?? ['kode' => $kode, 'nama' => $kode, 'mulai' => '', 'selesai' => '', 'kirim' => ''];

    return [
        'tanggal'      => $tanggal,
        'tanggalLabel' => fmt_tanggal($tanggal),
        'kode'         => $kode,
        'shift'        => [
            'kode'    => $sh['kode'],
            'nama'    => $sh['nama'],
            'mulai'   => $sh['mulai'],
            'selesai' => $sh['selesai'],
            'kirim'   => $sh['kirim']
        ],
        'bagian'       => $list,
        'total'        => $total,
        'cuti'         => $cuti,
        'off'          => $off,
        'generatedAt'  => date('Y-m-d H:i:s')
    ];
}

function get_dashboard(array $p): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    $s = summary_($tanggal, $kode);
    $s['current'] = $cur;
    return $s;
}

/* ============================== CRUD BAGIAN, OPERATOR, SHIFT, SETTING ============================== */

function save_bagian(array $p, array $currentUser): bool {
    $kode = strtoupper(trim(preg_replace('/\s+/', '_', (string)($p['kode'] ?? ''))));
    if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $kode)) {
        throw new InvalidArgumentException('Kode bagian 2–30 karakter (huruf/angka/_/-).');
    }
    $nama = trim((string)($p['nama'] ?? '')) ?: $kode;
    $targetS1 = max(0, (int)($p['targetS1'] ?? 0));
    $targetS2 = max(0, (int)($p['targetS2'] ?? 0));
    $urutan = (int)($p['urutan'] ?? 99);
    $aktif = isset($p['aktif']) && ($p['aktif'] === false || $p['aktif'] === 0 || $p['aktif'] === '0') ? 0 : 1;
    $judulWa = trim((string)($p['judulWa'] ?? '')) ?: $nama;
    $mention = trim((string)($p['mention'] ?? ''));
    $order = isset($p['order']) && ($p['order'] === false || $p['order'] === 0 || $p['order'] === '0') ? 0 : 1;

    $ex = db_row("SELECT * FROM bagian WHERE kode = ?", [$kode]);
    $isNew = !$ex;

    if ($isNew && !empty($p['isNew']) && $p['isNew'] === false) throw new RuntimeException('Bagian tidak ditemukan.');
    if (!$isNew && !empty($p['isNew']) && $p['isNew'] === true) throw new RuntimeException('Kode bagian sudah ada.');

    $data = [
        'nama'      => $nama,
        'target_s1' => $targetS1,
        'target_s2' => $targetS2,
        'urutan'    => $urutan,
        'aktif'     => $aktif,
        'judul_wa'  => $judulWa,
        'mention'   => $mention,
        'order_mpp' => $order
    ];

    if ($isNew) {
        $data['kode'] = $kode;
        db_insert('bagian', $data);
        audit_log($currentUser['username'], 'TAMBAH_BAGIAN', "$kode S1=$targetS1 S2=$targetS2");
    } else {
        db_update('bagian', $data, 'kode = ?', [$kode]);
        audit_log($currentUser['username'], 'UBAH_BAGIAN', "$kode S1=$targetS1 S2=$targetS2");
    }
    return true;
}

function delete_bagian(array $p, array $currentUser): bool {
    $kode = strtoupper(trim((string)($p['kode'] ?? '')));
    $used = (int)db_val("SELECT COUNT(*) FROM operator WHERE UPPER(bagian) = ?", [$kode]);
    if ($used > 0) {
        throw new RuntimeException("Masih ada $used operator di bagian ini. Pindahkan dulu atau nonaktifkan bagian.");
    }
    db_delete('bagian', 'kode = ?', [$kode]);
    audit_log($currentUser['username'], 'HAPUS_BAGIAN', $kode);
    return true;
}

function save_operator(array $p, array $currentUser): array {
    $nama = trim((string)($p['nama'] ?? ''));
    if ($nama === '') throw new InvalidArgumentException('Nama operator wajib diisi.');
    $bagian = strtoupper(trim((string)($p['bagian'] ?? '')));
    $grup = strtoupper(trim((string)($p['grup'] ?? 'A'))) ?: 'A';
    $nik = trim((string)($p['nik'] ?? ''));

    $id = trim((string)($p['id'] ?? ''));
    $ex = $id !== '' ? db_row("SELECT * FROM operator WHERE id = ?", [$id]) : null;

    if ($nik !== '') {
        $nikEx = db_row("SELECT id FROM operator WHERE nik = ? AND id != ?", [$nik, $id]);
        if ($nikEx) throw new RuntimeException("NIK $nik sudah dipakai operator lain.");
    }

    $isNew = !$ex;
    if ($isNew) {
        $maxNum = (int)substr(next_operator_id(), 2) - 1;
        $id = 'OP' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
    }

    $libur = is_array($p['libur'] ?? null) ? implode(',', $p['libur']) : (string)($p['libur'] ?? '');
    $hp = preg_replace('/[^0-9]/', '', (string)($p['hp'] ?? ''));
    if (str_starts_with($hp, '0')) $hp = '62' . substr($hp, 1);

    $data = [
        'nik'       => $nik,
        'nama'      => $nama,
        'bagian'    => $bagian,
        'grup'      => $grup,
        'libur'     => $libur,
        'hp'        => $hp,
        'aktif'     => isset($p['aktif']) && ($p['aktif'] === false || $p['aktif'] === 0 || $p['aktif'] === '0') ? 0 : 1,
        'tgl_masuk' => (string)($p['tglMasuk'] ?? ''),
        'ket'       => (string)($p['ket'] ?? ''),
        'pola'      => (int)($p['pola'] ?? 6) === 5 ? 5 : 6,
        'employer'  => normalize_employer($p['employer'] ?? ''),
        'role'      => (string)($p['role'] ?? ''),
        'forklift'  => !empty($p['forklift']) && $p['forklift'] !== '0' ? 1 : 0
    ];

    if ($isNew) {
        $data['id'] = $id;
        db_insert('operator', $data);
        audit_log($currentUser['username'], 'TAMBAH_OPERATOR', "$id $nama / $bagian / grup $grup");
    } else {
        db_update('operator', $data, 'id = ?', [$id]);
        audit_log($currentUser['username'], 'UBAH_OPERATOR', "$id $nama / $bagian / grup $grup");
    }

    return ['id' => $id];
}

/** Lepas tautan user yang menunjuk ke operator yang dihapus. */
function db_exec_unlink_operator_(string $id): void {
    db_update('users', ['operator_id' => ''], 'operator_id = ?', [$id]);
}

function delete_operator(array $p, array $currentUser): array {
    $id = trim((string)($p['id'] ?? ''));
    $row = db_row("SELECT * FROM operator WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException('Operator tidak ditemukan.');

    $used = (int)db_val("SELECT COUNT(*) FROM absensi WHERE operator_id = ?", [$id])
          + (int)db_val("SELECT COUNT(*) FROM jadwal WHERE operator_id = ?", [$id]);

    if ($used > 0 && empty($p['hard'])) {
        db_update('operator', ['aktif' => 0], 'id = ?', [$id]);
        audit_log($currentUser['username'], 'NONAKTIF_OPERATOR', $id . ' ' . $row['nama']);
        return ['mode' => 'nonaktif'];
    }

    db_delete('operator', 'id = ?', [$id]);
    db_exec_unlink_operator_($id);
    foto_delete_file($row['foto'] ?? '');
    audit_log($currentUser['username'], 'HAPUS_OPERATOR', $id . ' ' . $row['nama']);
    return ['mode' => 'hapus'];
}

function import_operators(array $p, array $currentUser): array {
    $text = trim(str_replace("\r", '', (string)($p['text'] ?? '')));
    if ($text === '') throw new InvalidArgumentException('Data kosong.');

    $lines = array_filter(explode("\n", $text), 'trim');
    $firstLine = reset($lines);
    $delim = strpos($firstLine, "\t") !== false ? "\t" : (strpos($firstLine, ';') !== false ? ';' : ',');

    $rows = array_map(function ($l) use ($delim) {
        return array_map('trim', explode($delim, $l));
    }, $lines);

    if (preg_match('/nama/i', $rows[0][0] ?? '')) {
        array_shift($rows);
    }

    $added = 0;
    $errors = [];
    foreach ($rows as $i => $c) {
        try {
            $nama = $c[0] ?? '';
            if (!$nama) continue;
            save_operator([
                'nama'     => $nama,
                'bagian'   => $c[1] ?? 'INBOUND',
                'grup'     => $c[2] ?? 'A',
                'nik'      => $c[3] ?? '',
                'hp'       => $c[4] ?? '',
                'libur'    => $c[5] ?? '',
                'role'     => $c[6] ?? '',
                'employer' => $c[7] ?? '',
                'pola'     => (int)($c[8] ?? 6)
            ], $currentUser);
            $added++;
        } catch (Throwable $e) {
            $errors[] = 'Baris ' . ($i + 1) . ': ' . $e->getMessage();
        }
    }

    audit_log($currentUser['username'], 'IMPORT_OPERATOR', "$added baris masuk, " . count($errors) . " gagal");
    return [
        'masuk'      => $added,
        'gagal'      => count($errors),
        'errors'     => array_slice($errors, 0, 30),
        'bagianBaru' => []
    ];
}

function save_shift(array $p, array $currentUser): bool {
    $kode = strtoupper(trim((string)($p['kode'] ?? '')));
    $re = '/^([01]\d|2[0-3]):[0-5]\d$/';

    foreach (['mulai', 'selesai', 'kirim'] as $k) {
        $val = trim((string)($p[$k] ?? ''));
        if (!preg_match($re, $val)) {
            throw new InvalidArgumentException("Format jam $k harus HH:mm (contoh 08:00).");
        }
    }
    $kirimReq = trim((string)($p['kirimReq'] ?? ''));
    if ($kirimReq !== '' && !preg_match($re, $kirimReq)) {
        throw new InvalidArgumentException('Format jam kirim request harus HH:mm atau dikosongkan.');
    }

    $ex = db_row("SELECT * FROM shift WHERE kode = ?", [$kode]);
    if (!$ex) throw new RuntimeException('Shift tidak ditemukan.');

    db_update('shift', [
        'nama'      => (string)($p['nama'] ?? $ex['nama']),
        'mulai'     => (string)$p['mulai'],
        'selesai'   => (string)$p['selesai'],
        'kirim'     => (string)$p['kirim'],
        'kirim_req' => $kirimReq
    ], 'kode = ?', [$kode]);

    audit_log($currentUser['username'], 'UBAH_SHIFT', "$kode {$p['mulai']}-{$p['selesai']} kirim {$p['kirim']}");
    return true;
}

function kode_simpan(array $p, array $currentUser): bool {
    $kodes = $p['kodes'] ?? [];
    if (!is_array($kodes)) throw new InvalidArgumentException('Data kode tidak valid.');

    foreach ($kodes as $k) {
        if (!is_array($k)) continue;
        $kode = strtoupper(trim((string)($k['kode'] ?? '')));
        if (!$kode) continue;
        if (!preg_match('/^[A-Z0-9_]{1,10}$/', $kode)) {
            throw new InvalidArgumentException("Kode jadwal \"$kode\" tidak valid (huruf/angka, maks 10 karakter).");
        }
        $mulai = trim((string)($k['mulai'] ?? ''));
        $selesai = trim((string)($k['selesai'] ?? ''));
        foreach ([$mulai, $selesai] as $t) {
            if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                throw new InvalidArgumentException("Jam pada kode $kode harus berformat HH:MM.");
            }
        }
        $warna = trim((string)($k['warna'] ?? ''));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $warna)) $warna = '#FFFFFF';
        $shift = strtoupper(trim((string)($k['shift'] ?? '')));
        if (!in_array($shift, ['', 'S1', 'S2'], true) && !preg_match('/^[A-Z0-9_]{1,10}$/', $shift)) $shift = '';
        $ex = db_row("SELECT * FROM kode_jadwal WHERE kode = ?", [$kode]);
        $data = [
            'label'   => mb_substr(trim((string)($k['label'] ?? '')) ?: $kode, 0, 20),
            'nama'    => mb_substr(trim((string)($k['nama'] ?? '')) ?: $kode, 0, 60),
            'mulai'   => $mulai,
            'selesai' => $selesai,
            'jam'     => max(0, min(24, (float)($k['jam'] ?? 8.0))),
            'kerja'   => !empty($k['kerja']) ? 1 : 0,
            'shift'   => $shift,
            'warna'   => strtoupper($warna),
            'urutan'  => (int)($k['urutan'] ?? 99)
        ];
        if ($ex) {
            db_update('kode_jadwal', $data, 'kode = ?', [$kode]);
        } else {
            $data['kode'] = $kode;
            db_insert('kode_jadwal', $data);
        }
    }
    audit_log($currentUser['username'], 'SIMPAN_KODE_JADWAL', count($kodes) . ' kode diperbarui');
    return true;
}

function get_settings_admin(): array {
    $s = settings_();
    $keys = [
        'app_nama', 'max_jam_minggu', 'min_istirahat', 'rotasi_anchor', 'rotasi_periode', 'batas_mode', 'pola6_half', 'order_employer',
        'wa_provider', 'wa_token', 'wa_target', 'wa_webhook_url', 'wa_tim', 'wa_judul', 'wa_pic', 'wa_mention',
        'wa_tpl_req', 'wa_tpl_real', 'wa_ket_full', 'wa_ket_kurang', 'wa_vacant', 'wa_lampiran'
    ];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = $s[$k] ?? '';
    }
    $out['wa_token'] = '';
    $out['wa_token_ada'] = !empty($s['wa_token']);
    $out['wa_auto'] = !empty($s['wa_auto']) && $s['wa_auto'] !== '0';

    return [
        'settings' => $out,
        'shifts'   => shifts_(),
        'bot'      => ['endpoint' => '', 'token' => '']
    ];
}

function save_settings(array $p, array $currentUser): array {
    $keys = [
        'app_nama', 'max_jam_minggu', 'min_istirahat', 'rotasi_anchor', 'rotasi_periode', 'batas_mode', 'pola6_half', 'order_employer',
        'wa_provider', 'wa_token', 'wa_target', 'wa_webhook_url', 'wa_tim', 'wa_judul', 'wa_pic', 'wa_mention',
        'wa_tpl_req', 'wa_tpl_real', 'wa_ket_full', 'wa_ket_kurang', 'wa_vacant', 'wa_lampiran'
    ];
    $v = [];
    foreach ($keys as $k) {
        if (isset($p[$k])) {
            $v[$k] = str_starts_with($k, 'wa_tpl_') ? str_replace("\r", '', (string)$p[$k]) : trim((string)$p[$k]);
        }
    }
    if (isset($v['wa_tpl_req']) && trim($v['wa_tpl_req']) === '') $v['wa_tpl_req'] = DEFAULT_TPL_REQ;
    if (isset($v['wa_tpl_real']) && trim($v['wa_tpl_real']) === '') $v['wa_tpl_real'] = DEFAULT_TPL_REAL;
    if (isset($v['wa_token']) && $v['wa_token'] === '') unset($v['wa_token']);
    if (!empty($p['hapus_token'])) $v['wa_token'] = '';

    set_settings($v);
    audit_log($currentUser['username'], 'UBAH_SETTING', implode('; ', array_keys($v)));
    return get_settings_admin();
}

