<?php
/**
 * mingguan.php : Jadwal mingguan 7 hari (Senin–Minggu), kuas jadwal, validasi jam & istirahat, salin minggu, kunci.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

function monday_of(string $ymd): string {
    $time = strtotime($ymd);
    $w = (int)date('w', $time);
    $diff = $w === 0 ? -6 : 1 - $w;
    return date('Y-m-d', strtotime("$diff days", $time));
}

function week_dates(string $monday): array {
    $out = [];
    $mTime = strtotime($monday);
    for ($i = 0; $i < 7; $i++) {
        $out[] = date('Y-m-d', strtotime("+$i days", $mTime));
    }
    return $out;
}

function week_lock_(string $monday): ?array {
    $row = db_row("SELECT * FROM week_locks WHERE monday = ?", [$monday]);
    if (!$row) return null;
    return [
        'monday' => $row['monday'],
        'by'     => $row['locked_by'],
        'at'     => $row['locked_at']
    ];
}

function rest_hours(?string $prevDate, ?string $prevCode, ?string $currDate, ?string $currCode): ?float {
    if (!$prevCode || !$currCode) return null;
    $km = kode_map_();
    $kPrev = $km[strtoupper($prevCode)] ?? null;
    $kCurr = $km[strtoupper($currCode)] ?? null;
    if (!$kPrev || !$kCurr || !$kPrev['kerja'] || !$kCurr['kerja']) return null;
    if ($kPrev['endMin'] === null || $kCurr['startMin'] === null) return null;

    // currDate is 1 day after prevDate
    $restMin = ($kCurr['startMin'] + 1440) - $kPrev['endMin'];
    return round($restMin / 60, 1);
}

function eval_week_row(array $op, array $codes, ?string $prevKode, array $dates, array $cfg, callable $lemburOf): array {
    $km = kode_map_();
    $jam = 0.0;
    $hari = 0;

    foreach ($codes as $i => $k) {
        $kk = $km[strtoupper((string)$k)] ?? null;
        if ($kk && $kk['kerja']) {
            $jam += (float)$kk['jam'];
            $hari++;
        }
        $jam += (float)$lemburOf($dates[$i]);
    }

    $warn = [];
    if ($jam > $cfg['maxJam']) {
        $warn[] = "$jam jam > batas {$cfg['maxJam']} jam";
    }

    $filled = count(array_filter($codes, 'strlen'));
    if ($filled === 7 && $hari !== (int)$op['pola']) {
        $warn[] = "$hari hari kerja (pola {$op['pola']} hari)";
    }

    for ($i = 0; $i < 7; $i++) {
        $prev = ($i === 0) ? $prevKode : $codes[$i - 1];
        $prevDate = ($i === 0) ? date('Y-m-d', strtotime('-1 day', strtotime($dates[0]))) : $dates[$i - 1];
        $r = rest_hours($prevDate, $prev, $dates[$i], $codes[$i]);
        if ($r !== null && $r < $cfg['minRest']) {
            $dow = (int)date('w', strtotime($dates[$i]));
            $warn[] = "istirahat $r jam sebelum " . HARI_LIST[$dow];
        }
    }

    return [
        'jam'  => $jam,
        'hari' => $hari,
        'warn' => $warn
    ];
}

function is_ieg_operator_(array $o): bool {
    if (stripos((string)($o['nama'] ?? ''), 'vacant') !== false) return false;
    return strtoupper(trim((string)($o['employer'] ?? ''))) === 'IEG';
}

function jadwal_minggu(array $p, array $currentUser): array {
    $tgl = !empty($p['tanggal']) ? (string)$p['tanggal'] : date('Y-m-d');
    $onlyIeg = !empty($p['onlyIeg']);
    $monday = monday_of($tgl);
    $dates = week_dates($monday);
    $prevSun = date('Y-m-d', strtotime('-1 day', strtotime($monday)));

    $s = settings_();
    $cfg = [
        'anchor'  => monday_of((string)($s['rotasi_anchor'] ?? $monday)),
        'periode' => max(1, (int)($s['rotasi_periode'] ?? 1)),
        'maxJam'  => (int)($s['max_jam_minggu'] ?? 49),
        'minRest' => (int)($s['min_istirahat'] ?? 8),
        'mode'    => strtoupper((string)($s['batas_mode'] ?? 'MINGGU')),
        'half6'   => ($s['pola6_half'] ?? '1') !== '0'
    ];

    $ops = operators_();
    if ($onlyIeg) {
        $ops = array_values(array_filter($ops, 'is_ieg_operator_'));
    }
    $bag = bagian_();
    $bagOrder = [];
    foreach ($bag as $i => $b) {
        $bagOrder[$b['kode']] = $i;
    }

    // Ambil jadwal rentang prevSun s/d dates[6]
    $jadRows = db_all("SELECT operator_id, tanggal, shift, sumber, ket FROM jadwal WHERE tanggal BETWEEN ? AND ?", [$prevSun, $dates[6]]);
    $jad = [];
    foreach ($jadRows as $r) {
        $key = $r['operator_id'] . '|' . $r['tanggal'];
        $jad[$key] = [
            'kode' => strtoupper((string)$r['shift']),
            'src'  => (string)($r['sumber'] ?? ''),
            'ket'  => (string)($r['ket'] ?? '')
        ];
    }

    // Lembur per operator
    $lemRows = db_all("SELECT operator_id, tanggal, SUM(lembur) as total_lembur FROM absensi WHERE tanggal BETWEEN ? AND ? GROUP BY operator_id, tanggal", [$dates[0], $dates[6]]);
    $lem = [];
    foreach ($lemRows as $r) {
        $lem[$r['operator_id'] . '|' . $r['tanggal']] = (float)$r['total_lembur'];
    }

    $rows = [];
    foreach ($ops as $o) {
        $hasAnyJadwal = false;
        foreach ($dates as $d) {
            if (isset($jad[$o['id'] . '|' . $d])) {
                $hasAnyJadwal = true;
                break;
            }
        }
        if (!$o['aktif'] && !$hasAnyJadwal) continue;

        $codes = [];
        $kets = [];
        $lemburs = [];
        $lemburOf = function ($d) use ($o, $lem) {
            return $lem[$o['id'] . '|' . $d] ?? 0.0;
        };

        foreach ($dates as $d) {
            $entry = $jad[$o['id'] . '|' . $d] ?? null;
            $codes[] = $entry ? $entry['kode'] : '';
            $kets[] = $entry ? $entry['ket'] : '';
            $lemburs[] = $lemburOf($d);
        }

        $prevKode = isset($jad[$o['id'] . '|' . $prevSun]) ? $jad[$o['id'] . '|' . $prevSun]['kode'] : '';
        $ev = eval_week_row($o, $codes, $prevKode, $dates, $cfg, $lemburOf);

        $canEditRow = $onlyIeg
            ? can_edit_bagian($currentUser, $o['bagian'])
            : in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true);

        $rows[] = [
            'id'       => $o['id'],
            'nik'      => $o['nik'],
            'nama'     => $o['nama'],
            'bagian'   => $o['bagian'],
            'role'     => $o['role'],
            'employer' => $o['employer'],
            'pola'     => $o['pola'],
            'grup'     => $o['grup'],
            'libur'    => $o['libur'],
            'aktif'    => $o['aktif'],
            'codes'    => $codes,
            'prevKode' => $prevKode,
            'lembur'   => $lemburs,
            'jam'      => $ev['jam'],
            'hari'     => $ev['hari'],
            'warn'     => $ev['warn'],
            'ket'      => $kets,
            'canEdit'  => $canEditRow
        ];
    }

    usort($rows, function ($a, $b) use ($bagOrder) {
        $vacA = stripos($a['nama'], 'vacant') !== false ? 1 : 0;
        $vacB = stripos($b['nama'], 'vacant') !== false ? 1 : 0;
        if ($vacA !== $vacB) return $vacA - $vacB;

        $orderA = $bagOrder[$a['bagian']] ?? 99;
        $orderB = $bagOrder[$b['bagian']] ?? 99;
        if ($orderA !== $orderB) return $orderA - $orderB;

        return strcasecmp($a['nama'], $b['nama']);
    });

    $targets = [];
    foreach (['S1', 'S2'] as $k) {
        $tSum = 0;
        foreach ($bag as $b) {
            if ($b['aktif']) $tSum += target_of_bagian($b, $k);
        }
        $targets[$k] = $tSum;
    }

    $datesFormatted = [];
    foreach ($dates as $d) {
        $dow = (int)date('w', strtotime($d));
        $datesFormatted[] = [
            'd'       => $d,
            'hari'    => HARI_LIST[$dow],
            'tgl'     => (int)date('j', strtotime($d)),
            'isToday' => $d === date('Y-m-d')
        ];
    }

    $employers = array_values(array_unique(array_filter(array_column($ops, 'employer'))));

    return [
        'monday'    => $monday,
        'dates'     => $datesFormatted,
        'rows'      => $rows,
        'kodes'     => kode_list_(),
        'targets'   => $targets,
        'lock'      => week_lock_($monday),
        'maxJam'    => $cfg['maxJam'],
        'minRest'   => $cfg['minRest'],
        'mode'      => $cfg['mode'],
        'half6'     => $cfg['half6'],
        'employers' => $employers
    ];
}

function jadwal_simpan(array $p, array $currentUser): array {
    $onlyIeg = !empty($p['onlyIeg']);
    if (!$onlyIeg && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Jadwal Mingguan hanya dapat diubah melalui menu Jadwal IEG, Approval Request OS, atau oleh Admin / Supervisor.');
    }
    $tgl = !empty($p['tanggal']) ? (string)$p['tanggal'] : date('Y-m-d');
    $monday = monday_of($tgl);

    $lock = week_lock_($monday);
    if ($lock && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException("Jadwal minggu ini sudah dikunci oleh {$lock['by']}. Hubungi Admin / Supervisor untuk membuka kunci.");
    }

    $changes = is_array($p['changes'] ?? null) ? $p['changes'] : [];
    if (empty($changes)) return ['ok' => true, 'saved' => 0];

    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;
    $km = kode_map_();

    foreach ($changes as $c) {
        $id = (string)$c['operatorId'];
        $op = $ops[$id] ?? null;
        if (!$op) throw new RuntimeException("Operator $id tidak ditemukan.");
        if ($onlyIeg && !is_ieg_operator_($op)) {
            throw new RuntimeException("Halaman Jadwal IEG hanya dapat mengubah jadwal karyawan IEG.");
        }
        if (!can_edit_bagian($currentUser, $op['bagian'])) {
            throw new RuntimeException("Anda tidak memiliki izin untuk mengubah jadwal bagian {$op['bagian']}.");
        }
        $kd = strtoupper(trim((string)($c['kode'] ?? '')));
        if ($kd !== '' && !isset($km[$kd])) {
            throw new InvalidArgumentException("Kode jadwal tidak dikenal: $kd");
        }
    }

    $saved = 0;
    $sumberLabel = $onlyIeg ? 'JADWAL_IEG' : 'MANUAL';
    db_transaction(function () use ($changes, $currentUser, $sumberLabel, &$saved) {
        $now = date('Y-m-d H:i:s');
        foreach ($changes as $c) {
            $id = (string)$c['operatorId'];
            $d = (string)$c['tanggal'];
            $kd = strtoupper(trim((string)($c['kode'] ?? '')));

            $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$d, $id]);
            if ($kd === '') {
                if ($ex) db_delete('jadwal', 'id = ?', [$ex['id']]);
                $saved++;
                continue;
            }

            $data = [
                'tanggal'      => $d,
                'operator_id'  => $id,
                'shift'        => $kd,
                'sumber'       => $sumberLabel,
                'updated_at'   => $now,
                'updated_by'   => $currentUser['username']
            ];
            if ($ex) {
                db_update('jadwal', $data, 'id = ?', [$ex['id']]);
            } else {
                $data['bagian_tugas'] = '';
                $data['ket'] = '';
                db_insert('jadwal', $data);
            }
            $saved++;
        }
    });

    audit_log($currentUser['username'], $onlyIeg ? 'SUBMIT_JADWAL_IEG' : 'JADWAL_MINGGU', "$monday $saved sel disimpan");
    return ['ok' => true, 'saved' => $saved, 'warnings' => []];
}

function jadwal_kunci(array $p, array $currentUser): array {
    if (!in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Hanya Admin atau Supervisor yang dapat mengunci atau membuka kunci jadwal.');
    }
    $monday = monday_of(!empty($p['tanggal']) ? (string)$p['tanggal'] : date('Y-m-d'));
    $lock = !empty($p['lock']) || !empty($p['kunci']);

    if ($lock) {
        $now = date('Y-m-d H:i:s');
        $ex = db_row("SELECT monday FROM week_locks WHERE monday = ?", [$monday]);
        if ($ex) {
            db_update('week_locks', ['locked_at' => $now, 'locked_by' => $currentUser['username']], 'monday = ?', [$monday]);
        } else {
            db_insert('week_locks', ['monday' => $monday, 'locked_at' => $now, 'locked_by' => $currentUser['username']]);
        }
        audit_log($currentUser['username'], 'KUNCI_MINGGU', $monday);
        return ['locked' => true, 'lockedBy' => $currentUser['username'], 'lockedAt' => $now];
    } else {
        db_delete('week_locks', 'monday = ?', [$monday]);
        audit_log($currentUser['username'], 'BUKA_KUNCI_MINGGU', $monday);
        return ['locked' => false, 'lockedBy' => null, 'lockedAt' => null];
    }
}

function jadwal_salin(array $p, array $currentUser): array {
    $onlyIeg = !empty($p['onlyIeg']);
    if (!$onlyIeg && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Hanya Admin atau Supervisor yang dapat menyalin semua jadwal manual.');
    }
    $tgl = !empty($p['tanggal']) ? (string)$p['tanggal'] : date('Y-m-d');
    $monday = monday_of($tgl);

    $lock = week_lock_($monday);
    if ($lock && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException("Jadwal minggu ini sudah dikunci oleh {$lock['by']}.");
    }

    $prevMon = date('Y-m-d', strtotime('-7 days', strtotime($monday)));
    $prevSun = date('Y-m-d', strtotime('+6 days', strtotime($prevMon)));
    $bagian = strtoupper(trim((string)($p['bagian'] ?? '')));
    $timpa = !empty($p['timpa']);

    $prevRows = db_all("SELECT j.*, o.bagian as op_bagian, o.employer as op_employer, o.nama as op_nama FROM jadwal j JOIN operator o ON j.operator_id = o.id WHERE j.tanggal BETWEEN ? AND ? AND o.aktif = 1", [$prevMon, $prevSun]);
    if (empty($prevRows)) {
        return ['ok' => true, 'saved' => 0, 'info' => 'Tidak ada jadwal pada minggu lalu untuk disalin.'];
    }

    $curKeys = [];
    $curSun = date('Y-m-d', strtotime('+6 days', strtotime($monday)));
    foreach (db_all("SELECT operator_id, tanggal FROM jadwal WHERE tanggal BETWEEN ? AND ?", [$monday, $curSun]) as $r) {
        $curKeys[$r['operator_id'] . '|' . $r['tanggal']] = true;
    }

    $saved = 0;
    $now = date('Y-m-d H:i:s');
    db_transaction(function () use ($prevRows, $bagian, $timpa, $onlyIeg, $curKeys, $currentUser, $now, &$saved) {
        foreach ($prevRows as $r) {
            if ($onlyIeg && (strtoupper(trim((string)($r['op_employer'] ?? ''))) !== 'IEG' || stripos((string)($r['op_nama'] ?? ''), 'vacant') !== false)) continue;
            if ($bagian !== '' && strtoupper($r['op_bagian']) !== $bagian) continue;
            if (!can_edit_bagian($currentUser, $r['op_bagian'])) continue;

            $newDate = date('Y-m-d', strtotime('+7 days', strtotime($r['tanggal'])));

            $key = $r['operator_id'] . '|' . $newDate;
            if (isset($curKeys[$key]) && !$timpa) continue;

            $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$newDate, $r['operator_id']]);
            $data = [
                'tanggal'      => $newDate,
                'operator_id'  => $r['operator_id'],
                'shift'        => $r['shift'],
                'bagian_tugas' => $r['bagian_tugas'] ?? '',
                'sumber'       => $onlyIeg ? 'JADWAL_IEG' : 'SALIN',
                'ket'          => 'Salin minggu lalu',
                'updated_at'   => $now,
                'updated_by'   => $currentUser['username']
            ];
            if ($ex) {
                db_update('jadwal', $data, 'id = ?', [$ex['id']]);
            } else {
                db_insert('jadwal', $data);
            }
            $saved++;
        }
    });

    audit_log($currentUser['username'], 'SALIN_JADWAL', "$prevMon -> $monday ($saved sel)");
    return ['ok' => true, 'saved' => $saved];
}

function jadwal_isi(array $p, array $currentUser): array {
    $onlyIeg = !empty($p['onlyIeg']);
    if (!$onlyIeg && !in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Hanya Admin atau Supervisor yang dapat mengisi jadwal otomatis penuh.');
    }
    $tgl = !empty($p['tanggal']) ? (string)$p['tanggal'] : date('Y-m-d');
    $monday = monday_of($tgl);
    $dates = week_dates($monday);
    $bagian = strtoupper(trim((string)($p['bagian'] ?? '')));
    $timpa = !empty($p['timpa']);

    $s = settings_();
    $anchor = monday_of((string)($s['rotasi_anchor'] ?? $monday));
    $periode = max(1, (int)($s['rotasi_periode'] ?? 1));
    $half6 = ($s['pola6_half'] ?? '1') !== '0';

    $ops = operators_();
    if ($onlyIeg) {
        $ops = array_values(array_filter($ops, 'is_ieg_operator_'));
    }
    $saved = 0;
    $opCount = 0;
    $dipertahankan = 0;
    $now = date('Y-m-d H:i:s');

    db_transaction(function () use ($ops, $dates, $bagian, $timpa, $onlyIeg, $anchor, $periode, $half6, $currentUser, $now, &$saved, &$opCount, &$dipertahankan) {
        foreach ($ops as $o) {
            if (!$o['aktif']) continue;
            if ($bagian !== '' && $o['bagian'] !== $bagian) continue;
            if (!can_edit_bagian($currentUser, $o['bagian'])) continue;
            $opCount++;

            $pola = (int)$o['pola'] === 5 ? 5 : 6;
            $liburArr = array_filter(array_map('trim', explode(',', (string)$o['libur'])), 'strlen');
            $liburDays = !empty($liburArr) ? array_map('intval', $liburArr) : ($pola === 5 ? [6, 0] : [0]);

            $g = strtoupper((string)($o['grup'] ?? 'A'));

            foreach ($dates as $d) {
                $ex = db_row("SELECT id, shift, sumber FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$d, $o['id']]);
                if ($ex && !$timpa) {
                    $dipertahankan++;
                    continue;
                }

                $dow = (int)date('w', strtotime($d));
                $shiftCode = 'S1';

                if (in_array($dow, $liburDays, true)) {
                    $shiftCode = 'OFF';
                } elseif ($pola === 6 && $half6 && in_array(($dow + 1) % 7, $liburDays, true)) {
                    $shiftCode = 'HALF';
                } elseif ($g === 'NS') {
                    $shiftCode = 'NS';
                } elseif ($g === 'S1' || $g === 'S2') {
                    $shiftCode = $g;
                } else {
                    // Rolling group A & B
                    $diffWeeks = (int)floor((strtotime($d) - strtotime($anchor)) / (7 * 86400));
                    $phase = (($diffWeeks / $periode) % 2 + 2) % 2;
                    $aShift = $phase === 0 ? 'S1' : 'S2';
                    $shiftCode = ($g === 'A') ? $aShift : ($aShift === 'S1' ? 'S2' : 'S1');
                }

                $data = [
                    'tanggal'      => $d,
                    'operator_id'  => $o['id'],
                    'shift'        => $shiftCode,
                    'bagian_tugas' => '',
                    'sumber'       => $onlyIeg ? 'JADWAL_IEG' : 'AUTO',
                    'ket'          => 'Pola otomatis ' . $pola . ' hari',
                    'updated_at'   => $now,
                    'updated_by'   => $currentUser['username']
                ];

                if ($ex) {
                    db_update('jadwal', $data, 'id = ?', [$ex['id']]);
                } else {
                    db_insert('jadwal', $data);
                }
                $saved++;
            }
        }
    });

    audit_log($currentUser['username'], 'ISI_OTOMATIS_JADWAL', "$monday ($saved sel)");
    return [
        'ok'            => true,
        'saved'         => $saved,
        'operator'      => $opCount,
        'entri'         => $saved,
        'offAturan'     => 0,
        'dipertahankan' => $dipertahankan
    ];
}

function set_jadwal_cell(array $p, array $currentUser): bool {
    if (!in_array($currentUser['role'], ['ADMIN', 'SUPERVISOR'], true)) {
        throw new RuntimeException('Jadwal Mingguan bersifat View-Only untuk role Anda.');
    }
    $id = trim((string)($p['operatorId'] ?? ''));
    $tgl = trim((string)($p['tanggal'] ?? ''));
    $shift = strtoupper(trim((string)($p['shift'] ?? '')));
    if (!$id || !$tgl) throw new InvalidArgumentException('Operator dan tanggal wajib diisi.');

    $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$tgl, $id]);
    $data = [
        'tanggal'      => $tgl,
        'operator_id'  => $id,
        'shift'        => $shift,
        'bagian_tugas' => trim((string)($p['bagianTugas'] ?? '')),
        'sumber'       => 'MANUAL',
        'ket'          => trim((string)($p['ket'] ?? '')),
        'updated_at'   => date('Y-m-d H:i:s'),
        'updated_by'   => $currentUser['username']
    ];
    if ($ex) {
        db_update('jadwal', $data, 'id = ?', [$ex['id']]);
    } else {
        db_insert('jadwal', $data);
    }
    return true;
}

