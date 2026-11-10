<?php
/**
 * foto.php : Upload & kelola foto profil user dan foto operator.
 *
 * Keamanan:
 * - Nama file selalu acak (32 hex dari random_bytes) — nama asli dari user tidak pernah dipakai.
 * - Hanya JPEG / PNG / WEBP yang lolos pemeriksaan isi (getimagesize + finfo), bukan dari ekstensi.
 * - Bila ekstensi GD tersedia, gambar digambar ulang menjadi JPEG maks 512px: metadata (EXIF/GPS)
 *   dan payload tersembunyi ikut terbuang.
 * - Folder uploads/ dilindungi .htaccess (tanpa eksekusi skrip, tanpa daftar isi folder).
 * - Hapus file hanya untuk nama yang cocok pola acak (mencegah path traversal).
 */
require_once __DIR__ . '/db.php';

const FOTO_MAX_BYTES = 3 * 1024 * 1024;   // 3 MB (setelah decode base64)
const FOTO_MAX_DIM   = 512;               // sisi terpanjang hasil re-encode
const FOTO_NAME_RE   = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

function foto_dir(): string {
    return __DIR__ . '/uploads/foto';
}

/** URL relatif untuk <img src>, atau '' bila kosong/tidak valid. */
function foto_url(?string $name): string {
    $name = (string)$name;
    return preg_match(FOTO_NAME_RE, $name) ? 'uploads/foto/' . $name : '';
}

function foto_delete_file(?string $name): void {
    $name = (string)$name;
    if (!preg_match(FOTO_NAME_RE, $name)) return;
    $path = foto_dir() . '/' . $name;
    if (is_file($path)) @unlink($path);
}

/**
 * Simpan gambar dari data URL / base64. Mengembalikan nama file acak yang tersimpan.
 * $maxDim = sisi terpanjang hasil re-encode (bawaan 512px untuk foto profil).
 * $webp   = simpan sebagai WebP (jauh lebih ringan dari JPEG) bila server mendukung.
 */
function foto_save(string $data, int $maxDim = FOTO_MAX_DIM, bool $webp = false): string {
    $data = trim($data);
    if (preg_match('/^data:([a-z0-9.+\/-]+);base64,/i', $data, $m)) {
        $data = substr($data, strlen($m[0]));
    }
    if ($data === '' || strlen($data) > (int)ceil(FOTO_MAX_BYTES * 4 / 3) + 16) {
        throw new InvalidArgumentException('Ukuran foto maksimal 3 MB.');
    }
    $bin = base64_decode($data, true);
    if ($bin === false || $bin === '') {
        throw new InvalidArgumentException('Data foto tidak valid.');
    }
    if (strlen($bin) > FOTO_MAX_BYTES) {
        throw new InvalidArgumentException('Ukuran foto maksimal 3 MB.');
    }

    // Periksa isi file (bukan ekstensi / mime dari browser)
    $info = @getimagesizefromstring($bin);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (defined('IMAGETYPE_WEBP')) $allowed[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($allowed[$info[2]]) || $info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) {
        throw new InvalidArgumentException('File harus berupa gambar JPG, PNG, atau WEBP.');
    }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? finfo_buffer($fi, $bin) : '';
        if ($fi) finfo_close($fi);
        if ($mime && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('File harus berupa gambar JPG, PNG, atau WEBP.');
        }
    }

    $dir = foto_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat.');
    }

    $out = null;
    $ext = $allowed[$info[2]];

    // Jalur utama: gambar ulang dengan GD -> JPEG bersih
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $src = @imagecreatefromstring($bin);
        if ($src !== false) {
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, $maxDim / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white); // latar putih untuk PNG transparan
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            if ($webp && function_exists('imagewebp')) {
                imagewebp($dst, null, 72);
                $ext = 'webp';
            } else {
                imagejpeg($dst, null, $webp ? 75 : 85);
                $ext = 'jpg';
            }
            $out = ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
        }
    }

    // Jalur cadangan tanpa GD: simpan apa adanya setelah lolos validasi, tolak konten mencurigakan
    if ($out === null) {
        if (preg_match('/<\?php|<\?=|<script|<html|<svg/i', $bin)) {
            throw new InvalidArgumentException('File gambar ditolak karena berisi konten yang tidak diizinkan.');
        }
        $out = $bin;
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $name, $out, LOCK_EX) === false) {
        throw new RuntimeException('Gagal menyimpan foto.');
    }
    return $name;
}

/** Nama file foto untuk sebuah baris user (foto operator terkait bila ada, selain itu foto user). */
function user_foto_name(array $userRow): string {
    $opId = trim((string)($userRow['operator_id'] ?? ''));
    if ($opId !== '') {
        $f = (string)db_val("SELECT foto FROM operator WHERE id = ?", [$opId], '');
        if ($f !== '') return $f;
    }
    return (string)($userRow['foto'] ?? '');
}

/** Upload / hapus foto operator. $data kosong = hapus. */
function operator_set_foto(string $id, ?string $data, array $currentUser): array {
    $row = db_row("SELECT id, nama, foto FROM operator WHERE id = ?", [$id]);
    if (!$row) throw new RuntimeException('Operator tidak ditemukan.');

    $new = ($data !== null && trim($data) !== '') ? foto_save($data) : '';
    db_update('operator', ['foto' => $new], 'id = ?', [$id]);
    foto_delete_file($row['foto'] ?? '');
    audit_log($currentUser['username'], $new ? 'FOTO_OPERATOR' : 'HAPUS_FOTO_OPERATOR', $id . ' ' . $row['nama']);
    return ['foto' => foto_url($new)];
}

/**
 * Upload / hapus foto profil user yang sedang login.
 * Bila user terhubung ke operator, fotonya disimpan sebagai foto operator tersebut (satu foto untuk keduanya).
 */
function profile_set_foto(array $currentUser, ?string $data): array {
    $row = db_row("SELECT id, username, foto, operator_id FROM users WHERE LOWER(username) = ?", [strtolower($currentUser['username'])]);
    if (!$row) throw new RuntimeException('Pengguna tidak ditemukan.');

    $opId = trim((string)($row['operator_id'] ?? ''));
    if ($opId !== '' && db_val("SELECT COUNT(*) FROM operator WHERE id = ?", [$opId])) {
        return operator_set_foto($opId, $data, $currentUser);
    }

    $new = ($data !== null && trim($data) !== '') ? foto_save($data) : '';
    db_update('users', ['foto' => $new], 'id = ?', [$row['id']]);
    foto_delete_file($row['foto'] ?? '');
    audit_log($currentUser['username'], $new ? 'FOTO_PROFIL' : 'HAPUS_FOTO_PROFIL', '');
    return ['foto' => foto_url($new)];
}
