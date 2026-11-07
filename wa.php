<?php
/**
 * wa.php : Order MPP (WhatsApp), template formatting, mention parser (@nomor auto-tag), pratinjau pesan, dan rekap MPP.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/master.php';

function norm_hp(string $v): string {
    $s = preg_replace('/[^0-9]/', '', $v);
    if (str_starts_with($s, '0')) {
        $s = '62' . substr($s, 1);
    }
    return $s;
}

/**
 * Parsing teks mention (format per baris: Sapaan | Nama | NomorHP).
 * Contoh: "Pak | Erfan RKA | 6281292716771"
 */
function parse_mentions(string $raw): array {
    $out = [];
    $lines = explode("\n", str_replace("\r", '', $raw));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line));
        $sapaan = '';
        $nama = '';
        $nomor = '';

        if (count($parts) >= 3) {
            $sapaan = $parts[0];
            $nama = $parts[1];
            $nomor = norm_hp($parts[2]);
        } elseif (count($parts) === 2) {
            if (preg_match('/^[0-9+ ]+$/', $parts[1])) {
                $nama = $parts[0];
                $nomor = norm_hp($parts[1]);
            } else {
                $sapaan = $parts[0];
                $nama = $parts[1];
            }
        } else {
            $nama = $parts[0];
        }

        if ($nama !== '') {
            $out[] = [
                'sapaan' => $sapaan,
                'nama'   => $nama,
                'nomor'  => $nomor
            ];
        }
    }
    return $out;
}

function tag_person(array $m, bool $useNum): string {
    if ($useNum && !empty($m['nomor'])) {
        return '@' . $m['nomor'];
    }
    return '@' . $m['nama'];
}

function with_sapaan(array $list, bool $useNum): string {
    $parts = [];
    foreach ($list as $m) {
        $prefix = !empty($m['sapaan']) ? trim($m['sapaan']) . ' ' : '';
        $parts[] = $prefix . tag_person($m, $useNum);
    }
    return implode(' ', $parts);
}

function jam_label(string $jam): string {
    $p = explode('-', $jam);
    return ($p[0] ?? '') . ' - ' . ($p[1] ?? '');
}

function jam_groups(array $st): array {
    $defJam = $st['defJam'];
    $jams = array_merge([$defJam], array_filter($st['jamExtra'], fn($j) => $j !== $defJam));

    $byJam = [];
    foreach ($jams as $j) $byJam[$j] = [];
    foreach ($st['req'] as $x) {
        $j = in_array($x['jam'] ?? '', $jams, true) ? $x['jam'] : $defJam;
        $qty = max(1, (int)($x['qty'] ?? 1));
        for ($k = 0; $k < $qty; $k++) {
            $byJam[$j][] = $x['id'];
        }
    }

    $out = [];
    $rem = $st['request'];
    foreach ($jams as $j) {
        $ids = $byJam[$j] ?? [];
        $reqCount = $j === $defJam ? max(count($ids), $rem) : count($ids);
        $rem = max(0, $rem - $reqCount);
        $out[] = [
            'jam'     => $j,
            'request' => $reqCount,
            'ids'     => $ids
        ];
    }
    return $out;
}

function salam_waktu(): string {
    $h = (int)date('G');
    if ($h >= 3 && $h < 11) return 'pagi';
    if ($h >= 11 && $h < 15) return 'siang';
    if ($h >= 15 && $h < 19) return 'sore';
    return 'malam';
}

function fill_template(string $tpl, array $data): string {
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($data) {
        $k = $m[1];
        return isset($data[$k]) ? (string)$data[$k] : $m[0];
    }, $tpl);
}

function order_text(string $type, array $st, bool $display): string {
    $s = settings_();
    $useNum = !$display; // Saat dishare/dikirim ke WA, selalu pakai @nomor murni agar WA auto-tag dan munculkan nama di kontak penerima

    $sMap = shift_map_();
    $sh = $sMap[$st['kode']] ?? ['nama' => $st['kode'], 'mulai' => '08:00', 'selesai' => '16:00'];
    $p = explode('-', $st['tanggal']);

    $pic = parse_mentions($s['wa_pic'] ?? '');
    $cc = parse_mentions($s['wa_mention'] ?? '');

    // Mention per divisi bila ada
    $bagWithOp = [];
    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;

    $targetIds = $type === 'REQ' ? array_column($st['req'], 'id') : $st['realIds'];
    foreach ($targetIds as $id) {
        if (str_starts_with((string)$id, 'VAC_')) {
            $bagWithOp[substr((string)$id, 4)] = true;
        } elseif (isset($ops[$id]['bagian'])) {
            $bagWithOp[$ops[$id]['bagian']] = true;
        }
    }

    foreach (bagian_() as $b) {
        if ($b['aktif'] && !empty($b['mention']) && (empty($bagWithOp) || isset($bagWithOp[$b['kode']]))) {
            foreach (parse_mentions($b['mention']) as $m) {
                $already = false;
                foreach ($cc as $x) {
                    if ((!empty($m['nomor']) && $x['nomor'] === $m['nomor']) || strcasecmp($x['nama'], $m['nama']) === 0) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) $cc[] = $m;
            }
        }
    }

    $all = [];
    foreach (array_merge($cc, $pic) as $m) {
        $already = false;
        foreach ($all as $x) {
            if ((!empty($m['nomor']) && $x['nomor'] === $m['nomor']) || strcasecmp($x['nama'], $m['nama']) === 0) {
                $already = true;
                break;
            }
        }
        if (!$already) $all[] = $m;
    }

    $vacLabel = $s['wa_vacant'] ?? 'VACANT';
    $nameOf = fn($id) => str_starts_with((string)$id, 'VAC_') ? $vacLabel : (isset($ops[$id]) ? strtoupper($ops[$id]['nama']) : '');
    $renderList = function (array $names, string $sep) {
        $lines = [];
        foreach ($names as $i => $n) {
            $lines[] = ($i + 1) . $sep . $n;
        }
        return implode("\n", $lines);
    };

    $sal = salam_waktu();
    $monthIdx = (int)$p[1] - 1;
    $dow = (int)date('w', strtotime($st['tanggal']));

    $d = [
        'salam'       => $sal,
        'Salam'       => ucfirst($sal),
        'tim'         => $s['wa_tim'] ?? 'Inventory',
        'bagian'      => $s['wa_tim'] ?? 'Inventory',
        'judul'       => $s['wa_judul'] ?? 'INVENTORY LPK RKA',
        'shift'       => $sh['nama'],
        'shift_kecil' => strtolower($sh['nama']),
        'mulai'       => $sh['mulai'],
        'selesai'     => $sh['selesai'],
        'hari'        => HARI_LIST[$dow],
        'd'           => (string)(int)$p[2],
        'dd'          => $p[2],
        'bulan'       => BULAN_PANJANG[$monthIdx] ?? $p[1],
        'yyyy'        => $p[0],
        'request'     => $st['request'],
        'pic'         => with_sapaan($pic, $useNum),
        'cc'          => with_sapaan($cc, $useNum),
        'mention'     => implode(' ', array_map(fn($m) => tag_person($m, $useNum), $all))
    ];

    if ($type === 'REQ') {
        $groups = jam_groups($st);
        $vac = $s['wa_vacant'] ?? 'VACANT';
        $named = [];
        foreach ($groups as $g) {
            $names = array_values(array_filter(array_map($nameOf, $g['ids'])));
            while (count($names) < $g['request']) {
                $names[] = $vac;
            }
            $named[] = ['jam' => $g['jam'], 'request' => $g['request'], 'names' => $names];
        }

        $render = function (string $sep) use ($named, $renderList) {
            if (count($named) === 1) {
                return $renderList($named[0]['names'], $sep);
            }
            $blocks = [];
            foreach ($named as $g) {
                $blocks[] = "Jam " . jam_label($g['jam']) . " ({$g['request']} Mp)\n" . $renderList($g['names'], $sep);
            }
            return implode("\n\n", $blocks);
        };

        $d['nama'] = $render('. ');
        $d['nama_tab'] = $render(".\t");
        $d['jam_kerja'] = implode(' & ', array_map(fn($g) => jam_label($g['jam']), $named));
        $d['hadir'] = count($st['req']);
        $tpl = $s['wa_tpl_req'] ?? DEFAULT_TPL_REQ;
    } else {
        $names = array_values(array_filter(array_map($nameOf, $st['realIds'])));
        $diff = count($names) - $st['request'];
        $d['hadir'] = count($names);
        $d['selisih'] = abs($diff);
        $d['keterangan'] = $diff >= 0
            ? fill_template($s['wa_ket_full'] ?? 'Terfullfill', ['selisih' => $diff])
            : fill_template($s['wa_ket_kurang'] ?? 'Kurang {selisih} Mp', ['selisih' => abs($diff)]);
        $d['nama'] = $renderList($names, '. ');
        $d['nama_tab'] = $renderList($names, ".\t");
        $d['jam_kerja'] = jam_label($st['defJam']);
        $tpl = $s['wa_tpl_real'] ?? DEFAULT_TPL_REAL;
    }

    return rtrim(fill_template($tpl, $d));
}

function order_state_(string $tanggal, string $kode): array {
    $sMap = shift_map_();
    $sh = $sMap[$kode] ?? ['mulai' => '08:00', 'selesai' => '16:00'];
    $defJam = $sh['mulai'] . '-' . $sh['selesai'];

    $row = db_row("SELECT * FROM order_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);

    $request = $row ? (int)$row['request'] : 0;
    $jamExtraStr = $row ? (string)($row['jam_extra'] ?? '') : '';
    $jamExtra = array_values(array_filter(explode(',', $jamExtraStr)));

    // Parse reqIds: mendukung "OP0001:08:00-16:00" maupun "VAC_INBOUND*2:08:00-16:00"
    $ops = operators_();
    $opById = [];
    foreach ($ops as $o) $opById[$o['id']] = $o;

    $req = [];
    $vacAgg = [];
    if ($row && !empty($row['req_ids'])) {
        foreach (explode(',', (string)$row['req_ids']) as $chunk) {
            $chunk = trim($chunk);
            if (!$chunk) continue;
            $parts = explode(':', $chunk, 2);
            $idPart = $parts[0];
            $jam = isset($parts[1]) && in_array($parts[1], $jamExtra, true) ? $parts[1] : $defJam;

            $qty = 1;
            if (strpos($idPart, '*') !== false) {
                [$idPart, $qStr] = explode('*', $idPart, 2);
                $qty = max(1, (int)$qStr);
            }

            // Konversi otomatis bila ID adalah operator VACANT lama menjadi VAC_{BAGIAN}
            if (isset($opById[$idPart]) && stripos($opById[$idPart]['nama'], 'vacant') !== false) {
                $idPart = 'VAC_' . strtoupper($opById[$idPart]['bagian']);
            }

            if (str_starts_with($idPart, 'VAC_')) {
                if (!isset($vacAgg[$idPart])) {
                    $vacAgg[$idPart] = ['id' => $idPart, 'jam' => $jam, 'qty' => $qty];
                } else {
                    $vacAgg[$idPart]['qty'] += $qty;
                }
            } else {
                $req[] = ['id' => $idPart, 'jam' => $jam, 'qty' => 1];
            }
        }
        foreach ($vacAgg as $vItem) {
            $req[] = $vItem;
        }
    }

    $realAuto = !$row || (int)$row['real_auto'] === 1;

    // Real IDs from attendance
    $absRows = db_all("SELECT operator_id, status FROM absensi WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
    $hadirFromAbs = [];
    foreach ($absRows as $a) {
        $st = strtoupper((string)$a['status']);
        if ($st === 'H' || $st === 'T') {
            $hadirFromAbs[] = (string)$a['operator_id'];
        }
    }

    if ($realAuto) {
        $realIds = $hadirFromAbs;
    } else {
        $realIds = $row && !empty($row['real_ids']) ? array_values(array_filter(explode(',', (string)$row['real_ids']))) : [];
    }

    // Candidates: Tampilkan semua operator non-VACANT + 1 baris VACANT (dengan Qty) di setiap Divisi/Bagian
    $dayJadwal = [];
    foreach (db_all("SELECT operator_id, shift FROM jadwal WHERE tanggal = ?", [$tanggal]) as $j) {
        $dayJadwal[$j['operator_id']] = strtoupper((string)$j['shift']);
    }
    $absMap = [];
    foreach ($absRows as $a) {
        $absMap[$a['operator_id']] = strtoupper((string)$a['status']);
    }

    $km = kode_map_();
    $candidates = [];
    $scheduledForShift = [];
    $scheduledVacantByBag = [];

    foreach ($ops as $o) {
        $jCode = $dayJadwal[$o['id']] ?? '-';
        $kInfo = $km[$jCode] ?? null;
        $refShift = ($kInfo && $kInfo['kerja']) ? $kInfo['shift'] : ($jCode === $kode ? $kode : '');
        $terjadwal = ($refShift === $kode || $jCode === $kode);
        $isVacOp = stripos($o['nama'], 'vacant') !== false;

        if ($isVacOp) {
            if ($terjadwal) {
                $bKode = strtoupper($o['bagian']);
                $scheduledVacantByBag[$bKode] = ($scheduledVacantByBag[$bKode] ?? 0) + 1;
            }
            continue;
        }

        if ($terjadwal) {
            $scheduledForShift[] = $o['id'];
        }

        $candidates[] = [
            'id'        => $o['id'],
            'nama'      => $o['nama'],
            'nik'       => $o['nik'],
            'bagian'    => $o['bagian'],
            'pola'      => $o['pola'],
            'employer'  => $o['employer'],
            'jadwal'    => $jCode,
            'terjadwal' => $terjadwal,
            'status'    => $absMap[$o['id']] ?? '',
            'isVacant'  => false
        ];
    }

    // Tambahkan baris VACANT untuk SETIAP Divisi/Bagian aktif agar bisa dipilih & diatur Qty-nya (1, 2, 3, dst.)
    foreach (bagian_() as $b) {
        if (!$b['aktif']) continue;
        $bKode = strtoupper($b['kode']);
        $vacCount = $scheduledVacantByBag[$bKode] ?? 0;
        $candidates[] = [
            'id'        => 'VAC_' . $bKode,
            'nama'      => 'VACANT',
            'nik'       => '',
            'bagian'    => $bKode,
            'pola'      => 6,
            'employer'  => '',
            'jadwal'    => $vacCount > 0 ? $kode : '-',
            'terjadwal' => $vacCount > 0,
            'status'    => '',
            'isVacant'  => true
        ];
    }

    // Jika belum ada draft order_mpp namun jadwal sudah terisi, sinkronkan otomatis dari jadwal
    if (!$row && (!empty($scheduledForShift) || !empty($scheduledVacantByBag))) {
        $totalMp = 0;
        foreach ($scheduledForShift as $opId) {
            $req[] = ['id' => $opId, 'jam' => $defJam, 'qty' => 1];
            $totalMp++;
        }
        foreach ($scheduledVacantByBag as $bKode => $vQty) {
            $req[] = ['id' => 'VAC_' . $bKode, 'jam' => $defJam, 'qty' => $vQty];
            $totalMp += $vQty;
        }
        $request = $totalMp;
    }

    $st = [
        'tanggal'      => $tanggal,
        'kode'         => $kode,
        'defJam'       => $defJam,
        'jamExtra'     => $jamExtra,
        'request'      => $request,
        'req'          => $req,
        'realIds'      => $realIds,
        'realAuto'     => $realAuto,
        'candidates'   => $candidates,
        'belumAbsen'   => count(array_filter($candidates, fn($c) => $c['terjadwal'] && empty($c['status']))),
        'adaAbsen'     => !empty($hadirFromAbs),
        'reqSubmitAt'    => (string)($row['req_submit_at'] ?? ''),
        'reqSubmitBy'    => (string)($row['req_submit_by'] ?? ''),
        'realSubmitAt'   => (string)($row['real_submit_at'] ?? ''),
        'realSubmitBy'   => (string)($row['real_submit_by'] ?? ''),
        'reqSentAt'      => (string)($row['req_sent_at'] ?? ''),
        'realSentAt'     => (string)($row['real_sent_at'] ?? ''),
        'approvalStatus' => strtoupper((string)($row['approval_status'] ?? 'DRAFT')),
        'approvedAt'     => (string)($row['approved_at'] ?? ''),
        'approvedBy'     => (string)($row['approved_by'] ?? ''),
        'approvalNote'   => (string)($row['approval_note'] ?? '')
    ];

    return $st;
}

function user_display_map_(): array {
    $rows = db_all("SELECT username, nama, role, aktif FROM users");
    $map = [];
    foreach ($rows as $r) {
        $u = strtolower(trim((string)$r['username']));
        $nama = trim((string)($r['nama'] ?? ''));
        $map[$u] = $nama !== '' ? ($nama . ' (' . $r['username'] . ')') : $r['username'];
    }
    return $map;
}

function supervisor_display_name_(): string {
    $spvs = db_all("SELECT username, nama FROM users WHERE UPPER(role) = 'SUPERVISOR' AND aktif = 1 ORDER BY id ASC");
    if (empty($spvs)) return 'Supervisor';
    $names = [];
    foreach ($spvs as $s) {
        $nm = trim((string)($s['nama'] ?? ''));
        $names[] = $nm !== '' ? ($nm . ' (' . $s['username'] . ')') : $s['username'];
    }
    return implode(', ', $names);
}

function parse_req_names_from_row_(array $row, array $opById): array {
    $sMap = shift_map_();
    $kode = strtoupper((string)($row['shift'] ?? 'S1'));
    $sh = $sMap[$kode] ?? ['mulai' => '08:00', 'selesai' => '16:00'];
    $defJam = $sh['mulai'] . '-' . $sh['selesai'];
    $jamExtra = array_values(array_filter(explode(',', (string)($row['jam_extra'] ?? ''))));

    $items = [];
    if (!empty($row['req_ids'])) {
        foreach (explode(',', (string)$row['req_ids']) as $chunk) {
            $chunk = trim($chunk);
            if (!$chunk) continue;
            $parts = explode(':', $chunk, 2);
            $idPart = $parts[0];
            $jam = isset($parts[1]) && in_array($parts[1], $jamExtra, true) ? $parts[1] : $defJam;
            $qty = 1;
            if (strpos($idPart, '*') !== false) {
                [$idPart, $qStr] = explode('*', $idPart, 2);
                $qty = max(1, (int)$qStr);
            }
            if (str_starts_with($idPart, 'VAC_')) {
                $bKode = strtoupper(substr($idPart, 4));
                for ($i = 1; $i <= $qty; $i++) {
                    $items[] = [
                        'id'       => $idPart . '_' . $i,
                        'nama'     => 'VACANT #' . $i,
                        'nik'      => '',
                        'bagian'   => $bKode,
                        'jam'      => $jam,
                        'isVacant' => true
                    ];
                }
            } elseif (isset($opById[$idPart])) {
                $op = $opById[$idPart];
                $items[] = [
                    'id'       => $op['id'],
                    'nama'     => $op['nama'],
                    'nik'      => $op['nik'],
                    'bagian'   => $op['bagian'],
                    'jam'      => $jam,
                    'isVacant' => stripos($op['nama'], 'vacant') !== false
                ];
            }
        }
    }
    return $items;
}

function order_view_(string $tanggal, string $kode, array $currentUser): array {
    $st = order_state_($tanggal, $kode);
    $s = settings_();
    $uMap = user_display_map_();
    $spvName = supervisor_display_name_();

    $st['supervisorName'] = $spvName;
    $subUserKey = strtolower(trim($st['reqSubmitBy']));
    $appUserKey = strtolower(trim($st['approvedBy']));
    $st['reqSubmitByNama'] = $subUserKey !== '' ? ($uMap[$subUserKey] ?? $st['reqSubmitBy']) : '';
    $st['approvedByNama'] = $appUserKey !== '' ? ($uMap[$appUserKey] ?? $st['approvedBy']) : '';

    $st['textReq'] = order_text('REQ', $st, true);
    $st['textReal'] = order_text('REAL', $st, true);
    $st['shareReq'] = order_text('REQ', $st, false); // format WA dengan @nomor
    $st['shareReal'] = order_text('REAL', $st, false);

    $pic = parse_mentions($s['wa_pic'] ?? '');
    $cc = parse_mentions($s['wa_mention'] ?? '');

    foreach (bagian_() as $b) {
        if ($b['aktif'] && !empty($b['mention'])) {
            foreach (parse_mentions($b['mention']) as $m) {
                $already = false;
                foreach ($cc as $x) {
                    if ((!empty($m['nomor']) && $x['nomor'] === $m['nomor']) || strcasecmp($x['nama'], $m['nama']) === 0) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) $cc[] = $m;
            }
        }
    }

    $st['picList'] = $pic;
    $st['ccList'] = $cc;

    $tagTanpaNomor = [];
    foreach (array_merge($pic, $cc) as $m) {
        if (empty($m['nomor'])) $tagTanpaNomor[] = $m['nama'];
    }
    $st['tagTanpaNomor'] = array_values(array_unique($tagTanpaNomor));

    $st['tanggalLabel'] = fmt_tanggal($tanggal);
    $st['provider'] = strtoupper((string)($s['wa_provider'] ?? 'MANUAL'));
    $st['lampiran'] = strtoupper((string)($s['wa_lampiran'] ?? 'GAMBAR'));
    $st['vacant'] = $s['wa_vacant'] ?? 'VACANT';
    $st['groups'] = array_map(fn($g) => ['jam' => $g['jam'], 'request' => $g['request'], 'jumlah' => count($g['ids'])], jam_groups($st));
    $st['bagian'] = array_map(fn($b) => ['kode' => $b['kode'], 'nama' => $b['nama']], bagian_());
    $st['canEdit'] = in_array($currentUser['role'], ['ADMIN', 'LEADER', 'SUPERVISOR'], true);
    $st['canApprove'] = can_approve_mpp($currentUser);

    $opById = [];
    foreach (operators_() as $o) $opById[$o['id']] = $o;

    // Daftar antrean Request OS yang menunggu Approval Supervisor (lengkap dengan daftar nama karyawan)
    $pendingRows = db_all("SELECT * FROM order_mpp WHERE UPPER(approval_status) = 'PENDING' ORDER BY tanggal DESC, shift ASC LIMIT 30");
    $st['pendingList'] = array_map(function ($pr) use ($uMap, $spvName, $opById) {
        $byRaw = (string)($pr['req_submit_by'] ?: $pr['updated_by']);
        $byKey = strtolower(trim($byRaw));
        return [
            'tanggal'        => (string)$pr['tanggal'],
            'tanggalLabel'   => fmt_tanggal((string)$pr['tanggal']),
            'shift'          => strtoupper((string)$pr['shift']),
            'request'        => (int)$pr['request'],
            'submitAt'       => (string)($pr['req_submit_at'] ?? ''),
            'submitBy'       => $byRaw,
            'submitByNama'   => $byKey !== '' ? ($uMap[$byKey] ?? $byRaw) : 'Leader',
            'supervisorName' => $spvName,
            'reqNames'       => parse_req_names_from_row_($pr, $opById)
        ];
    }, $pendingRows);

    // Data Minggu & Hasil Jadwal Terisi untuk ditampilkan langsung di halaman Request OS
    $tTime = strtotime($tanggal);
    $w = (int)date('w', $tTime);
    $diffToMon = $w === 0 ? -6 : 1 - $w;
    $monday = date('Y-m-d', strtotime("$diffToMon days", $tTime));
    $weekDates = [];
    for ($i = 0; $i < 7; $i++) {
        $weekDates[] = date('Y-m-d', strtotime("+$i days", strtotime($monday)));
    }
    $st['monday'] = $monday;
    $st['weekDates'] = $weekDates;

    $weekJadRows = db_all("SELECT operator_id, tanggal, shift, sumber, updated_at, updated_by FROM jadwal WHERE tanggal BETWEEN ? AND ?", [$weekDates[0], $weekDates[6]]);
    $weekJadMap = [];
    foreach ($weekJadRows as $wj) {
        $weekJadMap[$wj['operator_id']][$wj['tanggal']] = [
            'shift'      => strtoupper((string)$wj['shift']),
            'sumber'     => (string)($wj['sumber'] ?? ''),
            'updated_at' => (string)($wj['updated_at'] ?? ''),
            'updated_by' => (string)($wj['updated_by'] ?? '')
        ];
    }

    $reqJamMap = [];
    $vacReqEntries = [];
    foreach ($st['req'] as $rx) {
        if (str_starts_with((string)$rx['id'], 'VAC_')) {
            $vacReqEntries[] = $rx;
        } else {
            $reqJamMap[$rx['id']] = $rx['jam'] ?? $st['defJam'];
        }
    }

    $scheduledRows = [];
    foreach (operators_() as $o) {
        $opDays = $weekJadMap[$o['id']] ?? [];
        $hasScheduleInWeek = !empty($opDays);
        $isSelectedToday = isset($reqJamMap[$o['id']]);
        if (!$hasScheduleInWeek && !$isSelectedToday) continue;

        $daysSimple = [];
        foreach ($weekDates as $wd) {
            $daysSimple[$wd] = $opDays[$wd]['shift'] ?? ($wd === $tanggal && $isSelectedToday ? $kode : '-');
        }
        $todayEntry = $opDays[$tanggal] ?? null;
        $scheduledRows[] = [
            'id'          => $o['id'],
            'nama'        => $o['nama'],
            'nik'         => $o['nik'],
            'bagian'      => $o['bagian'],
            'pola'        => $o['pola'],
            'jadwalHari'  => $todayEntry ? $todayEntry['shift'] : ($isSelectedToday ? $kode : '-'),
            'sumberHari'  => $todayEntry ? $todayEntry['sumber'] : '',
            'updatedAt'   => $todayEntry ? $todayEntry['updated_at'] : '',
            'updatedBy'   => $todayEntry ? $todayEntry['updated_by'] : '',
            'jamKerja'    => $reqJamMap[$o['id']] ?? $st['defJam'],
            'terisiHari'  => $todayEntry !== null,
            'days'        => $daysSimple
        ];
    }

    // Jika status masih DRAFT / PENDING (belum disinkronkan ke jadwal), tampilkan juga baris VACANT yang di-request
    if ($st['approvalStatus'] !== 'APPROVED' && !empty($vacReqEntries)) {
        foreach ($vacReqEntries as $vx) {
            $bKode = strtoupper(substr((string)$vx['id'], 4));
            $vQty = max(1, (int)($vx['qty'] ?? 1));
            $daysSimple = [];
            foreach ($weekDates as $wd) {
                $daysSimple[$wd] = ($wd === $tanggal) ? $kode : '-';
            }
            for ($i = 1; $i <= $vQty; $i++) {
                $scheduledRows[] = [
                    'id'          => $vx['id'] . '_' . $i,
                    'nama'        => 'VACANT #' . $i,
                    'nik'         => '',
                    'bagian'      => $bKode,
                    'pola'        => 6,
                    'jadwalHari'  => $kode,
                    'sumberHari'  => 'REQUEST_OS',
                    'updatedAt'   => $st['reqSubmitAt'],
                    'updatedBy'   => $st['reqSubmitBy'],
                    'jamKerja'    => $vx['jam'] ?? $st['defJam'],
                    'terisiHari'  => false,
                    'days'        => $daysSimple
                ];
            }
        }
    }

    $st['scheduledRows'] = $scheduledRows;

    return $st;
}

/**
 * Sinkronisasi otomatis daftar operator & VACANT per divisi (beserta Qty) ke tabel jadwal.
 */
function sync_order_to_jadwal_(string $tanggal, string $kode, array $reqList, string $username): void {
    $now = date('Y-m-d H:i:s');
    $selectedIds = [];
    $jamMap = [];
    $vacantNeededByBag = [];

    foreach ($reqList as $x) {
        $id = trim((string)($x['id'] ?? ''));
        if ($id === '') continue;
        $jam = (string)($x['jam'] ?? '');
        $qty = max(1, (int)($x['qty'] ?? 1));

        if (str_starts_with($id, 'VAC_')) {
            $bKode = strtoupper(substr($id, 4));
            $vacantNeededByBag[$bKode] = [
                'qty' => ($vacantNeededByBag[$bKode]['qty'] ?? 0) + $qty,
                'jam' => $jam
            ];
        } else {
            $selectedIds[$id] = true;
            $jamMap[$id] = $jam;
        }
    }

    db_transaction(function () use ($tanggal, $kode, &$selectedIds, &$jamMap, $vacantNeededByBag, $username, $now) {
        // Pastikan slot operator VACANT untuk setiap divisi mencukupi sesuai Qty yang diminta
        foreach ($vacantNeededByBag as $bKode => $vInfo) {
            $needQty = $vInfo['qty'];
            $vJam = $vInfo['jam'];
            $existingVacOps = db_all("SELECT id FROM operator WHERE UPPER(bagian) = ? AND UPPER(nama) LIKE '%VACANT%' ORDER BY id ASC", [$bKode]);

            while (count($existingVacOps) < $needQty) {
                $maxNum = (int)db_val("SELECT MAX(CAST(SUBSTR(id, 3) AS INTEGER)) FROM operator WHERE id LIKE 'OP%'");
                $newId = 'OP' . str_pad($maxNum + 1, 4, '0', STR_PAD_LEFT);
                db_insert('operator', [
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
                    'employer'  => 'LPK RKA',
                    'role'      => 'Operator Vacant'
                ]);
                $existingVacOps[] = ['id' => $newId];
            }

            for ($i = 0; $i < $needQty; $i++) {
                $vOpId = $existingVacOps[$i]['id'];
                $selectedIds[$vOpId] = true;
                $jamMap[$vOpId] = $vJam;
            }
        }

        // Hapus jadwal shift ini pada tanggal tersebut untuk operator yang batal dipilih
        $existingOnShift = db_all("SELECT id, operator_id FROM jadwal WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
        foreach ($existingOnShift as $exRow) {
            if (!isset($selectedIds[$exRow['operator_id']])) {
                db_delete('jadwal', 'id = ?', [$exRow['id']]);
            }
        }

        // Masukkan / perbarui jadwal untuk setiap operator & slot VACANT yang dipilih
        foreach (array_keys($selectedIds) as $opId) {
            $ex = db_row("SELECT id FROM jadwal WHERE tanggal = ? AND operator_id = ?", [$tanggal, $opId]);
            $ketJam = !empty($jamMap[$opId]) ? 'Jam: ' . $jamMap[$opId] : '';
            $data = [
                'tanggal'     => $tanggal,
                'operator_id' => $opId,
                'shift'       => $kode,
                'sumber'      => 'CREATED_SCHEDULE',
                'ket'         => $ketJam,
                'updated_at'  => $now,
                'updated_by'  => $username
            ];
            if ($ex) {
                db_update('jadwal', $data, 'id = ?', [$ex['id']]);
            } else {
                $data['bagian_tugas'] = '';
                db_insert('jadwal', $data);
            }
        }
    });
}

function order_get(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    return order_view_($tanggal, $kode, $currentUser);
}

function order_save(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    assert_editable_date($currentUser, $tanggal);

    $sMap = shift_map_();
    $sh = $sMap[$kode] ?? ['mulai' => '08:00', 'selesai' => '16:00'];
    $defJam = $sh['mulai'] . '-' . $sh['selesai'];

    $jamExtra = array_values(array_filter(array_map('trim', $p['jamExtra'] ?? [])));
    $reqList = is_array($p['req'] ?? null) ? $p['req'] : [];

    // Format reqIds string (mendukung VAC_BAGIAN*QTY:JAM)
    $reqArr = [];
    $seen = [];
    foreach ($reqList as $x) {
        $id = (string)($x['id'] ?? '');
        if (!$id || isset($seen[$id])) continue;
        $qty = max(1, (int)($x['qty'] ?? 1));
        $seen[$id] = true;
        $j = in_array($x['jam'] ?? '', $jamExtra, true) ? $x['jam'] : $defJam;
        $idWithQty = (str_starts_with($id, 'VAC_') && $qty > 1) ? "$id*$qty" : $id;
        $reqArr[] = $j !== $defJam ? "$idWithQty:$j" : $idWithQty;
    }
    $reqIdsStr = implode(',', $reqArr);

    $realAuto = !isset($p['realAuto']) || $p['realAuto'] !== false;
    $realIds = is_array($p['realIds'] ?? null) ? implode(',', array_filter(array_map('trim', $p['realIds']))) : '';

    $now = date('Y-m-d H:i:s');
    $ex = db_row("SELECT id, approval_status FROM order_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);

    $data = [
        'tanggal'    => $tanggal,
        'shift'      => $kode,
        'request'    => max(0, (int)($p['request'] ?? 0)),
        'req_ids'    => $reqIdsStr,
        'jam_extra'  => implode(',', $jamExtra),
        'real_ids'   => $realAuto ? '' : $realIds,
        'real_auto'  => $realAuto ? 1 : 0,
        'updated_at' => $now,
        'updated_by' => $currentUser['username']
    ];

    if ($ex) {
        db_update('order_mpp', $data, 'id = ?', [$ex['id']]);
    } else {
        $data['approval_status'] = 'DRAFT';
        db_insert('order_mpp', $data);
    }

    // Hanya sinkronkan ke jadwal bila status sudah APPROVED
    if ($ex && strtoupper((string)($ex['approval_status'] ?? '')) === 'APPROVED') {
        sync_order_to_jadwal_($tanggal, $kode, $reqList, $currentUser['username']);
    }

    return order_view_($tanggal, $kode, $currentUser);
}

function order_submit(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    assert_editable_date($currentUser, $tanggal);

    $type = strtoupper((string)($p['type'] ?? 'REQ')) === 'REAL' ? 'REAL' : 'REQ';
    $now = date('Y-m-d H:i:s');
    $reqList = is_array($p['req'] ?? null) ? $p['req'] : [];

    // Daftar tanggal target (bisa 1 tanggal atau multi-tanggal saat input nama)
    $targetDates = [$tanggal];
    if (!empty($p['dates']) && is_array($p['dates'])) {
        foreach ($p['dates'] as $dt) {
            $dt = trim((string)$dt);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) && !in_array($dt, $targetDates, true)) {
                $targetDates[] = $dt;
            }
        }
    }

    foreach ($targetDates as $dt) {
        $pDate = $p;
        $pDate['tanggal'] = $dt;
        order_save($pDate, $currentUser);

        $data = [];
        if ($type === 'REQ') {
            $data['req_submit_at'] = $now;
            $data['req_submit_by'] = $currentUser['username'];
            // Masuk ke antrean Approval Supervisor terlebih dahulu sebelum masuk ke Jadwal Mingguan & Absensi
            $data['approval_status'] = 'PENDING';
            $data['approved_at'] = null;
            $data['approved_by'] = null;
        } else {
            $data['real_submit_at'] = $now;
            $data['real_submit_by'] = $currentUser['username'];
        }
        db_update('order_mpp', $data, 'tanggal = ? AND UPPER(shift) = ?', [$dt, $kode]);

        // Update RekapMPP
        write_rekap_mpp($dt, $kode, $type, $type === 'REQ' ? 'MENUNGGU APPROVAL' : 'SUBMIT', $currentUser['username']);
    }

    audit_log($currentUser['username'], "SUBMIT_$type", implode(',', $targetDates) . " $kode (PENDING APPROVAL)");

    return order_view_($tanggal, $kode, $currentUser);
}

function order_approve(array $p, array $currentUser): array {
    if (!can_approve_mpp($currentUser)) {
        throw new RuntimeException('Hanya Supervisor atau Admin yang berwenang melakukan Approval Request OS.');
    }
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    $now = date('Y-m-d H:i:s');
    $note = trim((string)($p['note'] ?? 'Approved'));

    $targetDates = [$tanggal];
    if (!empty($p['dates']) && is_array($p['dates'])) {
        foreach ($p['dates'] as $dt) {
            $dt = trim((string)$dt);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) && !in_array($dt, $targetDates, true)) {
                $targetDates[] = $dt;
            }
        }
    }

    foreach ($targetDates as $dt) {
        if (isset($p['req']) && is_array($p['req']) && $dt === $tanggal) {
            $pDate = $p;
            $pDate['tanggal'] = $dt;
            order_save($pDate, $currentUser);
        }
        $st = order_state_($dt, $kode);
        $upd = [
            'approval_status' => 'APPROVED',
            'approved_at'     => $now,
            'approved_by'     => $currentUser['username'],
            'approval_note'   => $note,
            'updated_at'      => $now
        ];
        if (empty($st['reqSubmitAt'])) {
            $upd['req_submit_at'] = $now;
            $upd['req_submit_by'] = $currentUser['username'];
        }
        db_update('order_mpp', $upd, 'tanggal = ? AND UPPER(shift) = ?', [$dt, $kode]);

        // Saat di-Approve oleh Supervisor, BARU masuk ke data Jadwal Mingguan & Absensi!
        sync_order_to_jadwal_($dt, $kode, $st['req'], $currentUser['username']);
        write_rekap_mpp($dt, $kode, 'REQ', 'APPROVED', $currentUser['username']);
    }

    audit_log($currentUser['username'], 'APPROVE_REQ_OS', implode(',', $targetDates) . " $kode");

    return order_view_($tanggal, $kode, $currentUser);
}

function order_reject(array $p, array $currentUser): array {
    if (!can_approve_mpp($currentUser)) {
        throw new RuntimeException('Hanya Supervisor atau Admin yang berwenang menolak Request OS.');
    }
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    $now = date('Y-m-d H:i:s');
    $note = trim((string)($p['note'] ?? 'Ditolak oleh Supervisor'));

    db_update('order_mpp', [
        'approval_status' => 'REJECTED',
        'approved_at'     => $now,
        'approved_by'     => $currentUser['username'],
        'approval_note'   => $note,
        'updated_at'      => $now
    ], 'tanggal = ? AND UPPER(shift) = ?', [$tanggal, $kode]);

    // Hapus dari jadwal bila sebelumnya pernah tersinkronisasi
    db_delete('jadwal', "tanggal = ? AND UPPER(shift) = ? AND sumber IN ('CREATED_SCHEDULE', 'REQUEST_OS')", [$tanggal, $kode]);
    write_rekap_mpp($tanggal, $kode, 'REQ', 'DITOLAK', $currentUser['username']);

    audit_log($currentUser['username'], 'REJECT_REQ_OS', "$tanggal $kode: $note");

    return order_view_($tanggal, $kode, $currentUser);
}

/**
 * Daftar seluruh pengajuan Request OS (beserta nama-nama karyawan & status approval Supervisor)
 */
function order_list_all(array $p, array $currentUser): array {
    $statusFilter = strtoupper(trim((string)($p['status'] ?? '')));
    $uMap = user_display_map_();
    $spvName = supervisor_display_name_();
    $opById = [];
    foreach (operators_() as $o) $opById[$o['id']] = $o;

    $where = "WHERE (request > 0 OR req_ids != '' OR req_submit_at IS NOT NULL)";
    $params = [];
    if ($statusFilter !== '' && in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED', 'DRAFT'], true)) {
        $where .= " AND UPPER(COALESCE(approval_status, 'DRAFT')) = ?";
        $params[] = $statusFilter;
    }

    $rows = db_all("SELECT * FROM order_mpp $where ORDER BY CASE WHEN UPPER(COALESCE(approval_status, 'DRAFT')) = 'PENDING' THEN 0 ELSE 1 END, tanggal DESC, shift ASC LIMIT 60", $params);
    $items = [];
    foreach ($rows as $r) {
        $subRaw = (string)($r['req_submit_by'] ?: $r['updated_by']);
        $subKey = strtolower(trim($subRaw));
        $appRaw = (string)($r['approved_by'] ?? '');
        $appKey = strtolower(trim($appRaw));
        $appSt = strtoupper((string)($r['approval_status'] ?: 'DRAFT'));
        $reqNames = parse_req_names_from_row_($r, $opById);

        $items[] = [
            'tanggal'        => (string)$r['tanggal'],
            'tanggalLabel'   => fmt_tanggal((string)$r['tanggal']),
            'shift'          => strtoupper((string)$r['shift']),
            'request'        => max((int)$r['request'], count($reqNames)),
            'approvalStatus' => $appSt,
            'submitAt'       => (string)($r['req_submit_at'] ?: $r['updated_at']),
            'submitBy'       => $subRaw,
            'submitByNama'   => $subKey !== '' ? ($uMap[$subKey] ?? $subRaw) : 'Leader / PIC',
            'approvedAt'     => (string)($r['approved_at'] ?? ''),
            'approvedBy'     => $appRaw,
            'approvedByNama' => $appKey !== '' ? ($uMap[$appKey] ?? $appRaw) : $spvName,
            'supervisorName' => $spvName,
            'approvalNote'   => (string)($r['approval_note'] ?? ''),
            'reqNames'       => $reqNames
        ];
    }

    return [
        'items'          => $items,
        'canApprove'     => can_approve_mpp($currentUser),
        'supervisorName' => $spvName
    ];
}

function order_shared(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    assert_editable_date($currentUser, $tanggal);

    $type = strtoupper((string)($p['type'] ?? 'REQ')) === 'REAL' ? 'REAL' : 'REQ';
    $now = date('Y-m-d H:i:s');
    $reqList = is_array($p['req'] ?? null) ? $p['req'] : [];

    $targetDates = [$tanggal];
    if (!empty($p['dates']) && is_array($p['dates'])) {
        foreach ($p['dates'] as $dt) {
            $dt = trim((string)$dt);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) && !in_array($dt, $targetDates, true)) {
                $targetDates[] = $dt;
            }
        }
    }

    foreach ($targetDates as $dt) {
        $pDate = $p;
        $pDate['tanggal'] = $dt;
        order_save($pDate, $currentUser);

        $exOrd = db_row("SELECT approval_status FROM order_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$dt, $kode]);
        $curApp = strtoupper((string)($exOrd['approval_status'] ?? 'DRAFT'));

        $data = [];
        if ($type === 'REQ') {
            $data['req_sent_at'] = $now;
            $data['req_submit_at'] = $now;
            $data['req_submit_by'] = $currentUser['username'];
            if ($curApp !== 'APPROVED') {
                $data['approval_status'] = 'PENDING';
            } else {
                sync_order_to_jadwal_($dt, $kode, $reqList, $currentUser['username']);
            }
        } else {
            $data['real_sent_at'] = $now;
            $data['real_submit_at'] = $now;
            $data['real_submit_by'] = $currentUser['username'];
        }
        db_update('order_mpp', $data, 'tanggal = ? AND UPPER(shift) = ?', [$dt, $kode]);

        write_rekap_mpp($dt, $kode, $type, ($type === 'REQ' && $curApp !== 'APPROVED') ? 'MENUNGGU APPROVAL' : 'DIBAGIKAN', $currentUser['username']);
    }

    log_wa_entry($tanggal, $kode, ($type === 'REQ' ? 'REQUEST' : 'REALISASI') . ' SHARE', 'Dibagikan manual lewat WhatsApp', $currentUser['username']);

    return order_view_($tanggal, $kode, $currentUser);
}

function order_send(array $p, array $currentUser): array {
    $cur = current_shift_();
    $tanggal = !empty($p['tanggal']) ? (string)$p['tanggal'] : $cur['tanggal'];
    $kode = !empty($p['shift']) ? strtoupper((string)$p['shift']) : $cur['kode'];
    assert_editable_date($currentUser, $tanggal);

    $type = strtoupper((string)($p['type'] ?? 'REQ')) === 'REAL' ? 'REAL' : 'REQ';
    $st = order_state_($tanggal, $kode);
    $text = order_text($type, $st, false); // format @nomor untuk WA

    $view = order_view_($tanggal, $kode, $currentUser);
    return [
        'ok'     => true,
        'manual' => true,
        'detail' => 'Pesan disiapkan untuk dibagikan ke WhatsApp.',
        'text'   => $text,
        'view'   => $view
    ];
}

function write_rekap_mpp(string $tanggal, string $kode, string $type, string $status, string $who): void {
    $st = order_state_($tanggal, $kode);
    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;

    $reqNames = [];
    $vacNames = [];
    $vacCountExplicit = 0;
    foreach ($st['req'] as $x) {
        $id = (string)$x['id'];
        $qty = max(1, (int)($x['qty'] ?? 1));
        if (str_starts_with($id, 'VAC_')) {
            $bKode = substr($id, 4);
            $vacCountExplicit += $qty;
            $vacNames[] = "VACANT $bKode ($qty)";
        } elseif (isset($ops[$id])) {
            if (stripos($ops[$id]['nama'], 'vacant') !== false) {
                $vacCountExplicit += $qty;
                $vacNames[] = "VACANT {$ops[$id]['bagian']}";
            } else {
                $reqNames[] = $ops[$id]['nama'];
            }
        }
    }
    $hadirNames = [];
    foreach ($st['realIds'] as $id) {
        if (isset($ops[$id]) && stripos($ops[$id]['nama'], 'vacant') === false) {
            $hadirNames[] = $ops[$id]['nama'];
        }
    }

    $dow = (int)date('w', strtotime($tanggal));
    $hari = HARI_LIST[$dow];
    $terisi = count($reqNames);
    $request = max($st['request'], $terisi + $vacCountExplicit);
    $vacant = max($vacCountExplicit, $request - $terisi);
    $hadir = count($hadirNames);
    $kurang = max(0, $request - $hadir);
    $persen = $request > 0 ? (int)round($hadir / $request * 100) : 0;

    $allReqLabel = array_merge($reqNames, $vacNames);

    $ex = db_row("SELECT id FROM rekap_mpp WHERE tanggal = ? AND UPPER(shift) = ?", [$tanggal, $kode]);
    $data = [
        'tanggal'      => $tanggal,
        'hari'         => $hari,
        'shift'        => $kode,
        'jam_masuk'    => jam_label($st['defJam']),
        'request'      => $request,
        'terisi'       => $terisi,
        'vacant'       => $vacant,
        'hadir'        => $hadir,
        'kurang'       => $kurang,
        'persen'       => $persen,
        'nama_request' => implode(', ', $allReqLabel),
        'nama_hadir'   => implode(', ', $hadirNames),
        'updated_by'   => $who,
        'updated_at'   => date('Y-m-d H:i:s')
    ];

    if ($type === 'REQ') {
        $data['status_request'] = $status;
    } else {
        $data['status_realisasi'] = $status;
    }

    if ($ex) {
        db_update('rekap_mpp', $data, 'id = ?', [$ex['id']]);
    } else {
        db_insert('rekap_mpp', $data);
    }
}

function log_wa_entry(string $tanggal, string $kode, string $mode, string $detail, string $who): void {
    try {
        db_insert('log_wa', [
            'waktu'   => date('Y-m-d H:i:s'),
            'tanggal' => $tanggal,
            'shift'   => $kode,
            'mode'    => $mode,
            'status'  => 'OK',
            'detail'  => mb_substr($detail, 0, 500),
            'oleh'    => $who
        ]);
    } catch (Throwable $e) {
        error_log("Gagal log WA: " . $e->getMessage());
    }
}

function wa_log(array $p, array $currentUser): array {
    $rows = db_all("SELECT waktu, tanggal, shift, mode, status, detail, oleh FROM log_wa ORDER BY id DESC LIMIT 50");
    return $rows;
}

function wa_status(array $p, array $currentUser): array {
    $s = settings_();
    $jadwalKirim = [];
    foreach (shifts_() as $sh) {
        $jadwalKirim[] = [
            'kode'     => $sh['kode'],
            'nama'     => $sh['nama'],
            'kirim'    => $sh['kirim'] ?? '',
            'kirimReq' => $sh['kirimReq'] ?? ''
        ];
    }
    return [
        'provider'    => strtoupper((string)($s['wa_provider'] ?? 'MANUAL')),
        'auto'        => !empty($s['wa_auto']) && $s['wa_auto'] !== '0',
        'outbox'      => 0,
        'jadwalKirim' => $jadwalKirim,
        'bot'         => null,
        'next'        => null
    ];
}

function rekap_mpp(array $p, array $currentUser): array {
    $from = !empty($p['from']) ? (string)$p['from'] : date('Y-m-d', strtotime('-7 days'));
    $to = !empty($p['to']) ? (string)$p['to'] : date('Y-m-d');
    $rawRows = db_all("SELECT * FROM rekap_mpp WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC, shift ASC", [$from, $to]);

    $sMap = shift_map_();
    $ops = [];
    foreach (operators_() as $o) $ops[$o['id']] = $o;

    // Ambil data absensi & jadwal aktual pada rentang tanggal untuk sinkronisasi real-time kehadiran
    $absAll = db_all("SELECT * FROM absensi WHERE tanggal BETWEEN ? AND ?", [$from, $to]);
    $absByKey = [];
    $absByDateShift = [];
    foreach ($absAll as $a) {
        $k = $a['tanggal'] . '|' . strtoupper((string)$a['shift']);
        $absByKey[$k . '|' . $a['operator_id']] = $a;
        $absByDateShift[$k][] = $a;
    }

    $jadAll = db_all("SELECT * FROM jadwal WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC, shift ASC", [$from, $to]);
    $jadByDateShift = [];
    foreach ($jadAll as $j) {
        $shKode = strtoupper((string)$j['shift']);
        if (!isset($sMap[$shKode])) continue;
        $k = $j['tanggal'] . '|' . $shKode;
        $jadByDateShift[$k][] = $j;
    }

    // Gabungkan kunci (tanggal|shift) dari rekap_mpp dan jadwal agar tidak ada yang terlewat
    $rekapByKey = [];
    foreach ($rawRows as $r) {
        $k = $r['tanggal'] . '|' . strtoupper((string)$r['shift']);
        $rekapByKey[$k] = $r;
    }
    $allKeys = array_unique(array_merge(array_keys($rekapByKey), array_keys($jadByDateShift)));
    usort($allKeys, function ($a, $b) {
        [$da, $sa] = explode('|', $a);
        [$db, $sb] = explode('|', $b);
        if ($da !== $db) return strcmp($db, $da);
        return strcmp($sa, $sb);
    });

    $rows = [];
    $detailKaryawan = [];
    $totReq = 0;
    $totTerisi = 0;
    $totVacant = 0;
    $totHadir = 0;
    $totReqReal = 0;

    foreach ($allKeys as $key) {
        [$tgl, $kode] = explode('|', $key);
        $sh = $sMap[$kode] ?? ['nama' => $kode, 'mulai' => '08:00', 'selesai' => '16:00'];
        $dow = (int)date('w', strtotime($tgl));
        $hari = HARI_LIST[$dow];
        $jamMasukDefault = ($sh['mulai'] ?? '08:00') . ' - ' . ($sh['selesai'] ?? '16:00');

        $r = $rekapByKey[$key] ?? null;
        $jadList = $jadByDateShift[$key] ?? [];
        $absList = $absByDateShift[$key] ?? [];

        // Hitung daftar karyawan yang di-request/terjadwal dan status kehadirannya
        $seenOp = [];
        $liveReqNames = [];
        $liveHadirNames = [];
        $liveVacantCount = 0;

        foreach ($jadList as $j) {
            $opId = $j['operator_id'];
            if (!isset($ops[$opId]) || isset($seenOp[$opId])) continue;
            $seenOp[$opId] = true;
            $op = $ops[$opId];
            $isVac = stripos($op['nama'], 'vacant') !== false;
            $a = $absByKey[$key . '|' . $opId] ?? null;
            $stAbs = $a ? strtoupper((string)$a['status']) : '';

            if ($isVac) {
                $liveVacantCount++;
            } else {
                $liveReqNames[] = $op['nama'];
                if ($stAbs === 'H' || $stAbs === 'T') {
                    $liveHadirNames[] = $op['nama'];
                }
            }

            $detailKaryawan[] = [
                'tanggal'    => $tgl,
                'hari'       => $hari,
                'shift'      => $kode,
                'shiftNama'  => $sh['nama'],
                'jamMasuk'   => $r['jam_masuk'] ?? $jamMasukDefault,
                'operatorId' => $opId,
                'nama'       => $op['nama'],
                'nik'        => $op['nik'],
                'bagian'     => $op['bagian'],
                'isVacant'   => $isVac,
                'status'     => $stAbs,
                'lembur'     => $a ? (float)$a['lembur'] : 0.0,
                'ket'        => $a ? (string)$a['ket'] : '',
                'clockInAt'  => ($a && !empty($a['updated_at'])) ? substr((string)$a['updated_at'], 11, 5) : ''
            ];
        }

        // Tambahkan juga karyawan yang clock-in langsung di absensi walaupun belum masuk jadwal
        foreach ($absList as $a) {
            $opId = $a['operator_id'];
            if (!isset($ops[$opId]) || isset($seenOp[$opId])) continue;
            $seenOp[$opId] = true;
            $op = $ops[$opId];
            $isVac = stripos($op['nama'], 'vacant') !== false;
            $stAbs = strtoupper((string)$a['status']);
            if (!$isVac && ($stAbs === 'H' || $stAbs === 'T')) {
                $liveHadirNames[] = $op['nama'];
            }
            $detailKaryawan[] = [
                'tanggal'    => $tgl,
                'hari'       => $hari,
                'shift'      => $kode,
                'shiftNama'  => $sh['nama'],
                'jamMasuk'   => $r['jam_masuk'] ?? $jamMasukDefault,
                'operatorId' => $opId,
                'nama'       => $op['nama'],
                'nik'        => $op['nik'],
                'bagian'     => $op['bagian'],
                'isVacant'   => $isVac,
                'status'     => $stAbs,
                'lembur'     => (float)$a['lembur'],
                'ket'        => (string)$a['ket'],
                'clockInAt'  => !empty($a['updated_at']) ? substr((string)$a['updated_at'], 11, 5) : ''
            ];
        }

        $terisi = $r ? max((int)$r['terisi'], count($liveReqNames)) : count($liveReqNames);
        $vacant = $r ? max((int)$r['vacant'], $liveVacantCount) : $liveVacantCount;
        $req = $r ? max((int)$r['request'], $terisi + $vacant) : ($terisi + $vacant);
        $hasReal = ($r && !empty($r['status_realisasi'])) || !empty($absList);
        $hadirCount = !empty($liveHadirNames) ? count($liveHadirNames) : ($r ? (int)$r['hadir'] : 0);
        $hadir = $hasReal ? $hadirCount : $hadirCount;
        $persen = $req > 0 ? (int)round($hadir / $req * 100) : 0;

        $totReq += $req;
        $totTerisi += $terisi;
        $totVacant += $vacant;
        $totHadir += $hadir;
        $totReqReal += $req;

        $namaReqStr = ($r && !empty($r['nama_request'])) ? $r['nama_request'] : implode(', ', $liveReqNames);
        $namaHadirStr = !empty($liveHadirNames) ? implode(', ', $liveHadirNames) : ($r['nama_hadir'] ?? '');

        $rows[] = [
            'id'              => $r['id'] ?? 0,
            'tanggal'         => $tgl,
            'hari'            => $hari,
            'shift'           => $kode,
            'shiftNama'       => $sh['nama'],
            'jamMasuk'        => $r['jam_masuk'] ?? $jamMasukDefault,
            'request'         => $req,
            'terisi'          => $terisi,
            'vacant'          => $vacant,
            'hadir'           => $hadir,
            'kurang'          => max(0, $req - $hadir),
            'persen'          => $persen,
            'namaRequest'     => $namaReqStr,
            'namaHadir'       => $namaHadirStr,
            'statusRequest'   => (string)($r['status_request'] ?? (!empty($jadList) ? 'SUBMIT' : '')),
            'statusRealisasi' => (string)($r['status_realisasi'] ?? (!empty($absList) ? 'CLOCK-IN' : ''))
        ];
    }

    return [
        'from'           => $from,
        'to'             => $to,
        'rows'           => $rows,
        'detailKaryawan' => $detailKaryawan,
        'total'          => [
            'request'     => $totReq,
            'terisi'      => $totTerisi,
            'vacant'      => $totVacant,
            'hadir'       => $totHadir,
            'requestReal' => $totReqReal,
            'persen'      => $totReqReal > 0 ? (int)round($totHadir / $totReqReal * 100) : null
        ]
    ];
}

