<?php
/**
 * absensi.php : Absensi operator per tanggal & shift, BKO, pengganti, dan lembur.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

function assert_editable_date(array $user, string $tanggal): void {
    if ($user['role'] === 'ADMIN') return;
    $diffDays = (int)round((strtotime($tanggal) - strtotime(date('Y-m-d'))) / 86400);
    if ($diffDays > 1 || $diffDays < -1) {
        throw new RuntimeException('Leader hanya bisa mengubah data kemarin, hari ini, atau besok. Hubungi Admin.');
    }
}

function get_absensi(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];

    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;
    $km = kode_map_();

    $absRows = db_all("SELECT * FROM absensi WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
    $abs = [];
    foreach ($absRows as $r) {
        $abs[$r['operator_id']] = $r;
    }

    $dayJadwal = [];
    $jadRows = db_all("SELECT * FROM jadwal WHERE tanggal = ?", [$tanggal]);
    foreach ($jadRows as $j) {
        $dayJadwal[$j['operator_id']] = $j;
    }

    // Sinkronkan dari order_mpp (Request OS) HANYA bila sudah di-APPROVE oleh Supervisor
    $ordRow = db_row("SELECT req_ids, approval_status, approved_by, approved_at FROM order_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
    $ordAppStatus = $ordRow ? strtoupper((string)($ordRow['approval_status'] ?? 'DRAFT')) : '';
    if ($ordRow && $ordAppStatus === 'APPROVED' && !empty($ordRow['req_ids'])) {
        $vacByBag = [];
        foreach (explode(',', (string)$ordRow['req_ids']) as $chunk) {
            $chunk = trim($chunk);
            if (!$chunk) continue;
            $parts = explode(':', $chunk, 2);
            $opId = $parts[0];
            $qty = 1;
            if (strpos($opId, '*') !== false) {
                [$opId, $qStr] = explode('*', $opId, 2);
                $qty = max(1, (int)$qStr);
            }
            if (str_starts_with($opId, 'VAC_')) {
                $bKode = strtoupper(substr($opId, 4));
                $vacByBag[$bKode] = ($vacByBag[$bKode] ?? 0) + $qty;
                continue;
            }
            if (isset($ops[$opId]) && !isset($dayJadwal[$opId])) {
                $now = date('Y-m-d H:i:s');
                db_insert('jadwal', [
                    'tanggal'      => $tanggal,
                    'operator_id'  => $opId,
                    'shift'        => $kode,
                    'bagian_tugas' => '',
                    'sumber'       => 'REQUEST_OS',
                    'ket'          => '',
                    'updated_at'   => $now,
                    'updated_by'   => 'system'
                ]);
                $dayJadwal[$opId] = [
                    'operator_id'  => $opId,
                    'tanggal'      => $tanggal,
                    'shift'        => $kode,
                    'bagian_tugas' => '',
                    'sumber'       => 'REQUEST_OS',
                    'ket'          => ''
                ];
            }
        }
        // Sinkronkan slot VACANT per divisi bila belum masuk ke jadwal
        if (!empty($vacByBag)) {
            foreach ($vacByBag as $bKode => $needQty) {
                $existingVacOps = db_all("SELECT * FROM operator WHERE UPPER(bagian) = ? AND UPPER(nama) LIKE '%VACANT%' ORDER BY id ASC", [$bKode]);
                while (count($existingVacOps) < $needQty) {
                    $maxNum = (int)db_val("SELECT MAX(CAST(SUBSTR(id, 3) AS INTEGER)) FROM operator WHERE id LIKE 'OP%'");
                    $newId = 'OP' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
                    $newRow = [
                        'id'        => $newId,
                        'nik'       => '',
                        'nama'      => 'VACANT',
                        'bagian'    => $bKode,
                        'grup'      => 'S1',
                        'libur'     => '0',
                        'hp'        => '',
                        'aktif'     => 1,
                        'tgl_masuk' => '',
                        'ket'       => 'Slot Vacant ' . $bKode,
                        'pola'      => 6,
                        'employer'  => 'RKA',
                        'role'      => 'Operator Vacant'
                    ];
                    db_insert('operator', $newRow);
                    $existingVacOps[] = $newRow;
                    $ops[$newId] = $newRow;
                }
                for ($i = 0; $i < $needQty; $i++) {
                    $vId = $existingVacOps[$i]['id'];
                    if (!isset($dayJadwal[$vId])) {
                        $now = date('Y-m-d H:i:s');
                        db_insert('jadwal', [
                            'tanggal'      => $tanggal,
                            'operator_id'  => $vId,
                            'shift'        => $kode,
                            'bagian_tugas' => $bKode,
                            'sumber'       => 'REQUEST_OS',
                            'ket'          => 'Slot Vacant',
                            'updated_at'   => $now,
                            'updated_by'   => 'system'
                        ]);
                        $dayJadwal[$vId] = [
                            'operator_id'  => $vId,
                            'tanggal'      => $tanggal,
                            'shift'        => $kode,
                            'bagian_tugas' => $bKode,
                            'sumber'       => 'REQUEST_OS',
                            'ket'          => 'Slot Vacant'
                        ];
                    }
                }
            }
        }
    }

    $rows = [];
    $scheduledCount = 0;
    foreach ($ops as $id => $op) {
        $j = $dayJadwal[$id] ?? null;
        $a = $abs[$id] ?? null;

        $jShift = $j ? strtoupper((string)$j['shift']) : '';
        $kInfo = $jShift ? ($km[$jShift] ?? null) : null;
        $refShift = ($kInfo && $kInfo['kerja']) ? $kInfo['shift'] : ($jShift === $kode ? $kode : '');
        $isScheduledThisShift = ($refShift === $kode || $jShift === $kode);
        $hasAbsThisShift = ($a !== null && $a['status'] !== '');

        if (!$op['aktif'] && !$isScheduledThisShift && !$hasAbsThisShift) {
            continue;
        }

        if ($isScheduledThisShift || $hasAbsThisShift) {
            $scheduledCount++;
        }

        $bt = strtoupper((string)(($j && !empty($j['bagian_tugas'])) ? $j['bagian_tugas'] : $op['bagian']));
        $canEdit = can_edit_bagian($currentUser, $bt) || can_edit_bagian($currentUser, $op['bagian']);
        $isVacant = stripos($op['nama'], 'vacant') !== false;

        // Jangan tampilkan dummy operator VACANT yang tidak dijadwalkan pada shift hari ini
        if ($isVacant && !$isScheduledThisShift && !$hasAbsThisShift) {
            continue;
        }

        $rows[] = [
            'operatorId'  => $id,
            'nama'        => $op['nama'],
            'nik'         => $op['nik'],
            'bagian'      => $op['bagian'],
            'bagianTugas' => $bt,
            'kodeJadwal'  => $jShift ?: '-',
            'terjadwal'   => ($isScheduledThisShift || $hasAbsThisShift),
            'isVacant'    => $isVacant,
            'sumber'      => $j ? (string)($j['sumber'] ?? '') : '',
            'status'      => $a ? strtoupper((string)$a['status']) : '',
            'lembur'      => $a ? (float)$a['lembur'] : 0.0,
            'ket'         => $a ? (string)$a['ket'] : '',
            'updatedBy'   => $a ? (string)$a['updated_by'] : '',
            'updatedAt'   => $a ? (string)$a['updated_at'] : '',
            'canEdit'     => $canEdit
        ];
    }

    usort($rows, function ($a, $b) {
        $vacA = stripos($a['nama'], 'vacant') !== false ? 1 : 0;
        $vacB = stripos($b['nama'], 'vacant') !== false ? 1 : 0;
        if ($vacA !== $vacB) return $vacA - $vacB;
        $c = strcmp($a['bagianTugas'], $b['bagianTugas']);
        if ($c !== 0) return $c;
        return strcasecmp($a['nama'], $b['nama']);
    });

    $available = [];
    foreach ($ops as $o) {
        $jShift = isset($dayJadwal[$o['id']]) ? strtoupper((string)$dayJadwal[$o['id']]['shift']) : '-';
        if ($o['aktif'] && $jShift !== $kode) {
            $available[] = [
                'id'            => $o['id'],
                'nama'          => $o['nama'],
                'bagian'        => $o['bagian'],
                'jadwalHariIni' => $jShift
            ];
        }
    }

    return [
        'tanggal'        => $tanggal,
        'tanggalLabel'   => fmt_tanggal($tanggal),
        'shift'          => $kode,
        'scheduledCount' => $scheduledCount,
        'approvalStatus' => $ordAppStatus,
        'approvedBy'     => $ordRow ? (string)($ordRow['approved_by'] ?? '') : '',
        'rows'           => $rows,
        'available'      => $available,
        'current'        => $cur
    ];
}

function set_absensi(array $p, array $currentUser): array {
    $tanggal = trim((string)($p['tanggal'] ?? ''));
    $kode = strtoupper(trim((string)($p['shift'] ?? '')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $kode === '') {
        throw new InvalidArgumentException('Tanggal atau shift tidak valid.');
    }
    assert_editable_date($currentUser, $tanggal);

    $items = is_array($p['items'] ?? null) ? $p['items'] : [];
    if (empty($items)) return ['saved' => 0];

    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;

    $jadMap = [];
    foreach (db_all("SELECT * FROM jadwal WHERE tanggal = ?", [$tanggal]) as $j) {
        $jadMap[$j['operator_id']] = $j;
    }

    $saved = 0;
    db_transaction(function () use ($items, $tanggal, $kode, $currentUser, $ops, $jadMap, &$saved) {
        foreach ($items as $it) {
            $id = trim((string)($it['operatorId'] ?? ''));
            if (!isset($ops[$id])) continue;
            $op = $ops[$id];

            $bt = isset($jadMap[$id]) ? strtoupper((string)($jadMap[$id]['bagian_tugas'] ?: $op['bagian'])) : $op['bagian'];
            if (!can_edit_bagian($currentUser, $bt) && !can_edit_bagian($currentUser, $op['bagian'])) {
                throw new RuntimeException("Anda tidak berhak mengubah absensi bagian $bt.");
            }

            $status = strtoupper(trim((string)($it['status'] ?? '')));
            if ($status !== '' && !isset(STATUS_MAP[$status])) {
                throw new InvalidArgumentException("Status tidak valid: $status");
            }

            $lembur = max(0, min(8, (float)($it['lembur'] ?? 0)));
            $ket = mb_substr(trim((string)($it['ket'] ?? '')), 0, 200);

            $ex = db_row("SELECT id FROM absensi WHERE tanggal = ? AND UPPER(shift) = ? AND operator_id = ?", [$tanggal, $kode, $id]);

            if ($status === '') {
                if ($ex) {
                    db_delete('absensi', 'id = ?', [$ex['id']]);
                }
                $saved++;
                continue;
            }

            $data = [
                'tanggal'     => $tanggal,
                'shift'       => $kode,
                'operator_id' => $id,
                'status'      => $status,
                'lembur'      => $lembur,
                'ket'         => $ket,
                'updated_at'  => date('Y-m-d H:i:s'),
                'updated_by'  => $currentUser['username']
            ];

            if ($ex) {
                db_update('absensi', $data, 'id = ?', [$ex['id']]);
            } else {
                db_insert('absensi', $data);
            }

            // Jika karyawan di-clockin / absen namun belum tercatat di jadwal hari ini, otomatis catat ke jadwal shift ini
            if (!isset($jadMap[$id])) {
                db_insert('jadwal', [
                    'tanggal'      => $tanggal,
                    'operator_id'  => $id,
                    'shift'        => $kode,
                    'bagian_tugas' => '',
                    'sumber'       => 'CLOCKIN',
                    'ket'          => 'Auto dari Clock-In Absensi',
                    'updated_at'   => date('Y-m-d H:i:s'),
                    'updated_by'   => $currentUser['username']
                ]);
            }
            $saved++;
        }
    });

    if (count($items) > 3) {
        audit_log($currentUser['username'], 'ABSENSI_MASSAL', "$tanggal $kode " . count($items) . " operator");
    }

    return ['saved' => $saved];
}

function add_to_shift(array $p, array $currentUser): bool {
    $tanggal = trim((string)($p['tanggal'] ?? ''));
    $kode = strtoupper(trim((string)($p['shift'] ?? '')));
    $id = trim((string)($p['operatorId'] ?? ''));
    assert_editable_date($currentUser, $tanggal);

    $op = db_row("SELECT * FROM operator WHERE id = ?", [$id]);
    if (!$op) throw new RuntimeException('Operator tidak ditemukan.');

    $bt = strtoupper(trim((string)($p['bagianTugas'] ?? ''))) ?: strtoupper($op['bagian']);
    if (!can_edit_bagian($currentUser, $bt)) {
        throw new RuntimeException("Anda tidak berhak menambah operator ke bagian $bt.");
    }

    $ket = trim((string)($p['ket'] ?? '')) ?: 'Ditambahkan dari absensi';

    // Upsert jadwal
    $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$tanggal, $id]);
    $data = [
        'tanggal'      => $tanggal,
        'operator_id'  => $id,
        'shift'        => $kode,
        'bagian_tugas' => $bt === strtoupper($op['bagian']) ? '' : $bt,
        'sumber'       => 'MANUAL',
        'ket'          => $ket,
        'updated_at'   => date('Y-m-d H:i:s'),
        'updated_by'   => $currentUser['username']
    ];
    if ($ex) {
        db_update('jadwal', $data, 'id = ?', [$ex['id']]);
    } else {
        db_insert('jadwal', $data);
    }

    audit_log($currentUser['username'], 'TAMBAH_KE_SHIFT', "$id -> $tanggal $kode");
    return true;
}

function remove_from_shift(array $p, array $currentUser): bool {
    $tanggal = trim((string)($p['tanggal'] ?? ''));
    $kode = strtoupper(trim((string)($p['shift'] ?? '')));
    $id = trim((string)($p['operatorId'] ?? ''));
    assert_editable_date($currentUser, $tanggal);

    $op = db_row("SELECT * FROM operator WHERE id = ?", [$id]);
    if (!$op) throw new RuntimeException('Operator tidak ditemukan.');

    if (!can_edit_bagian($currentUser, $op['bagian']) && $currentUser['role'] !== 'ADMIN') {
        throw new RuntimeException('Anda tidak berhak mengubah operator ini.');
    }

    // Ubah jadwal hari ini jadi OFF
    $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$tanggal, $id]);
    $data = [
        'shift'        => 'OFF',
        'bagian_tugas' => '',
        'ket'          => "Dikeluarkan dari $kode",
        'updated_at'   => date('Y-m-d H:i:s'),
        'updated_by'   => $currentUser['username']
    ];
    if ($ex) {
        db_update('jadwal', $data, 'id = ?', [$ex['id']]);
    }

    // Hapus dari absensi shift ini
    db_delete('absensi', 'tanggal = ? AND UPPER(shift) = ? AND operator_id = ?', [$tanggal, $kode, $id]);

    audit_log($currentUser['username'], 'KELUARKAN_DARI_SHIFT', "$id <- $tanggal $kode");
    return true;
}

/**
 * Mengisi / menamai slot VACANT di halaman Absensi Shift menjadi nama karyawan asli dan otomatis Clock-In (Hadir).
 */
function fill_vacant_slot(array $p, array $currentUser): array {
    $tanggal = trim((string)($p['tanggal'] ?? ''));
    $kode = strtoupper(trim((string)($p['shift'] ?? '')));
    $vacId = trim((string)($p['vacantId'] ?? ''));
    $namaBaru = strtoupper(trim((string)($p['nama'] ?? '')));
    $bagian = strtoupper(trim((string)($p['bagian'] ?? '')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) || $kode === '') {
        throw new InvalidArgumentException('Tanggal atau shift tidak valid.');
    }
    if ($namaBaru === '' || stripos($namaBaru, 'vacant') !== false) {
        throw new InvalidArgumentException('Silakan masukkan nama karyawan asli untuk mengisi slot Vacant.');
    }
    assert_editable_date($currentUser, $tanggal);

    $vacOp = $vacId !== '' ? db_row("SELECT * FROM operator WHERE id = ?", [$vacId]) : null;
    if ($bagian === '' && $vacOp) {
        $bagian = strtoupper((string)$vacOp['bagian']);
    }
    if ($bagian === '') {
        $bagian = 'INBOUND';
    }
    if (!can_edit_bagian($currentUser, $bagian)) {
        throw new RuntimeException("Anda tidak berhak mengubah data bagian $bagian.");
    }

    $now = date('Y-m-d H:i:s');
    $targetOpId = '';

    db_transaction(function () use ($tanggal, $kode, $vacId, $namaBaru, $bagian, $currentUser, $now, &$targetOpId) {
        // 1. Cari apakah nama karyawan ini sudah ada di tabel operator (non-vacant)
        $existingOp = db_row("SELECT * FROM operator WHERE UPPER(TRIM(nama)) = ? AND UPPER(nama) NOT LIKE '%VACANT%' LIMIT 1", [$namaBaru]);
        if ($existingOp) {
            $targetOpId = (string)$existingOp['id'];
            if ((int)$existingOp['aktif'] !== 1) {
                db_update('operator', ['aktif' => 1], 'id = ?', [$targetOpId]);
            }
        } else {
            $maxNum = (int)db_val("SELECT MAX(CAST(SUBSTR(id, 3) AS INTEGER)) FROM operator WHERE id LIKE 'OP%'");
            $targetOpId = 'OP' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
            db_insert('operator', [
                'id'        => $targetOpId,
                'nik'       => '',
                'nama'      => $namaBaru,
                'bagian'    => $bagian,
                'grup'      => 'S1',
                'libur'     => '0',
                'hp'        => '',
                'aktif'     => 1,
                'tgl_masuk' => $tanggal,
                'ket'       => 'Pengisi Slot Vacant',
                'pola'      => 6,
                'employer'  => 'RKA',
                'role'      => 'Operator'
            ]);
        }

        // 2. Hapus slot VACANT lama dari jadwal pada tanggal & shift ini
        if ($vacId !== '') {
            db_delete('jadwal', 'tanggal = ? AND operator_id = ?', [$tanggal, $vacId]);
            db_delete('absensi', 'tanggal = ? AND UPPER(shift) = ? AND operator_id = ?', [$tanggal, $kode, $vacId]);
        }

        // 3. Masukkan karyawan bernama tersebut ke jadwal shift ini (sumber: VACANT_FILLED)
        $exJad = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$tanggal, $targetOpId]);
        $jadData = [
            'tanggal'      => $tanggal,
            'operator_id'  => $targetOpId,
            'shift'        => $kode,
            'bagian_tugas' => $bagian,
            'sumber'       => 'VACANT_FILLED',
            'ket'          => 'Pengisi Vacant (' . $bagian . ')',
            'updated_at'   => $now,
            'updated_by'   => $currentUser['username']
        ];
        if ($exJad) {
            db_update('jadwal', $jadData, 'id = ?', [$exJad['id']]);
        } else {
            db_insert('jadwal', $jadData);
        }

        // 4. Otomatis catat Clock-In (Hadir / H) di tabel absensi
        $exAbs = db_row("SELECT id FROM absensi WHERE tanggal = ? AND UPPER(shift) = ? AND operator_id = ?", [$tanggal, $kode, $targetOpId]);
        $absData = [
            'tanggal'     => $tanggal,
            'shift'       => $kode,
            'operator_id' => $targetOpId,
            'status'      => 'H',
            'lembur'      => 0,
            'ket'         => 'Pengisi Vacant',
            'updated_at'  => $now,
            'updated_by'  => $currentUser['username']
        ];
        if ($exAbs) {
            db_update('absensi', $absData, 'id = ?', [$exAbs['id']]);
        } else {
            db_insert('absensi', $absData);
        }

        // 5. Sinkronkan juga ke order_mpp agar slot VAC_{BAGIAN} digantikan oleh operator yang baru dinamai
        $ord = db_row("SELECT * FROM order_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
        if ($ord && !empty($ord['req_ids'])) {
            $newChunks = [];
            $decremented = false;
            $alreadyInReq = false;
            $vacKey = 'VAC_' . $bagian;

            foreach (explode(',', (string)$ord['req_ids']) as $chunk) {
                $chunk = trim($chunk);
                if (!$chunk) continue;
                $parts = explode(':', $chunk, 2);
                $idPart = $parts[0];
                $jamPart = $parts[1] ?? '';

                $qty = 1;
                if (strpos($idPart, '*') !== false) {
                    [$idPart, $qStr] = explode('*', $idPart, 2);
                    $qty = max(1, (int)$qStr);
                }

                if ($idPart === $targetOpId) {
                    $alreadyInReq = true;
                }

                if (!$decremented && (strcasecmp($idPart, $vacKey) === 0 || $idPart === $vacId)) {
                    $decremented = true;
                    $remQty = $qty - 1;
                    if ($remQty > 0) {
                        $idWithQ = $remQty > 1 ? "$idPart*$remQty" : $idPart;
                        $newChunks[] = $jamPart !== '' ? "$idWithQ:$jamPart" : $idWithQ;
                    }
                    if (!$alreadyInReq) {
                        $newChunks[] = $jamPart !== '' ? "$targetOpId:$jamPart" : $targetOpId;
                        $alreadyInReq = true;
                    }
                    continue;
                }

                $idWithQ = (str_starts_with($idPart, 'VAC_') && $qty > 1) ? "$idPart*$qty" : $idPart;
                $newChunks[] = $jamPart !== '' ? "$idWithQ:$jamPart" : $idWithQ;
            }

            if (!$alreadyInReq) {
                $newChunks[] = $targetOpId;
            }

            db_update('order_mpp', [
                'req_ids'    => implode(',', $newChunks),
                'updated_at' => $now,
                'updated_by' => $currentUser['username']
            ], 'id = ?', [$ord['id']]);
        }
    });

    audit_log($currentUser['username'], 'ISI_VACANT_ABSENSI', "$tanggal $kode $vacId -> $targetOpId ($namaBaru)");
    return [
        'operatorId' => $targetOpId,
        'nama'       => $namaBaru,
        'bagian'     => $bagian
    ];
}

