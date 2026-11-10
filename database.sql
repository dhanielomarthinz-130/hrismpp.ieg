-- =====================================================================
-- HRIS MPP — PT Inovasi Eka Gemilang
-- Skema Database MySQL / MariaDB (Import via phpMyAdmin atau MySQL CLI)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `hris_mpp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `hris_mpp`;

-- 1. users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `nama` VARCHAR(100) NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'VIEWER',
  `bagian` TEXT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `aktif` TINYINT NOT NULL DEFAULT 1,
  `must_change` TINYINT NOT NULL DEFAULT 0,
  `last_login` VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. user_tokens
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `token` VARCHAR(64) PRIMARY KEY,
  `user_id` INT NOT NULL,
  `created_at` VARCHAR(30) NOT NULL,
  `expires_at` VARCHAR(30) NOT NULL,
  INDEX (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. bagian
CREATE TABLE IF NOT EXISTS `bagian` (
  `kode` VARCHAR(50) PRIMARY KEY,
  `nama` VARCHAR(100) NOT NULL,
  `target_s1` INT NOT NULL DEFAULT 0,
  `target_s2` INT NOT NULL DEFAULT 0,
  `urutan` INT NOT NULL DEFAULT 99,
  `aktif` TINYINT NOT NULL DEFAULT 1,
  `judul_wa` VARCHAR(100) NULL,
  `mention` TEXT NULL,
  `order_mpp` TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. operator
CREATE TABLE IF NOT EXISTS `operator` (
  `id` VARCHAR(30) PRIMARY KEY,
  `nik` VARCHAR(50) NULL,
  `nama` VARCHAR(100) NOT NULL,
  `bagian` VARCHAR(50) NOT NULL,
  `grup` VARCHAR(20) NOT NULL DEFAULT 'A',
  `libur` VARCHAR(50) NULL DEFAULT '',
  `hp` VARCHAR(30) NULL,
  `aktif` TINYINT NOT NULL DEFAULT 1,
  `tgl_masuk` VARCHAR(20) NULL,
  `ket` TEXT NULL,
  `pola` TINYINT NOT NULL DEFAULT 6,
  `employer` VARCHAR(100) NULL,
  `role` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. kode_jadwal
CREATE TABLE IF NOT EXISTS `kode_jadwal` (
  `kode` VARCHAR(30) PRIMARY KEY,
  `label` VARCHAR(50) NOT NULL,
  `nama` VARCHAR(100) NOT NULL,
  `mulai` VARCHAR(10) NULL,
  `selesai` VARCHAR(10) NULL,
  `jam` DECIMAL(5,2) NOT NULL DEFAULT 8.00,
  `kerja` TINYINT NOT NULL DEFAULT 1,
  `shift` VARCHAR(20) NULL,
  `warna` VARCHAR(20) NOT NULL DEFAULT '#FFFFFF',
  `urutan` INT NOT NULL DEFAULT 99
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. shift
CREATE TABLE IF NOT EXISTS `shift` (
  `kode` VARCHAR(20) PRIMARY KEY,
  `nama` VARCHAR(100) NOT NULL,
  `mulai` VARCHAR(10) NOT NULL,
  `selesai` VARCHAR(10) NOT NULL,
  `kirim` VARCHAR(10) NULL,
  `urutan` INT NOT NULL DEFAULT 1,
  `kirim_req` VARCHAR(10) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. jadwal
CREATE TABLE IF NOT EXISTS `jadwal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tanggal` VARCHAR(20) NOT NULL,
  `operator_id` VARCHAR(30) NOT NULL,
  `shift` VARCHAR(30) NOT NULL,
  `bagian_tugas` VARCHAR(50) NULL,
  `sumber` VARCHAR(50) NULL,
  `ket` TEXT NULL,
  `updated_at` VARCHAR(30) NULL,
  `updated_by` VARCHAR(50) NULL,
  UNIQUE KEY `idx_jadwal_tgl_op` (`tanggal`, `operator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. absensi
CREATE TABLE IF NOT EXISTS `absensi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tanggal` VARCHAR(20) NOT NULL,
  `shift` VARCHAR(30) NOT NULL,
  `operator_id` VARCHAR(30) NOT NULL,
  `status` VARCHAR(10) NOT NULL,
  `lembur` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `ket` TEXT NULL,
  `updated_at` VARCHAR(30) NULL,
  `updated_by` VARCHAR(50) NULL,
  UNIQUE KEY `idx_absensi_tgl_sh_op` (`tanggal`, `shift`, `operator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. settings
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(50) PRIMARY KEY,
  `value` TEXT NULL,
  `ket` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. order_mpp
CREATE TABLE IF NOT EXISTS `order_mpp` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tanggal` VARCHAR(20) NOT NULL,
  `shift` VARCHAR(30) NOT NULL,
  `request` INT NOT NULL DEFAULT 0,
  `req_ids` TEXT NULL,
  `jam_extra` TEXT NULL,
  `real_ids` TEXT NULL,
  `real_auto` TINYINT NOT NULL DEFAULT 1,
  `req_sent_at` VARCHAR(30) NULL,
  `real_sent_at` VARCHAR(30) NULL,
  `updated_at` VARCHAR(30) NULL,
  `updated_by` VARCHAR(50) NULL,
  `req_submit_at` VARCHAR(30) NULL,
  `req_submit_by` VARCHAR(50) NULL,
  `real_submit_at` VARCHAR(30) NULL,
  `real_submit_by` VARCHAR(50) NULL,
  UNIQUE KEY `idx_order_tgl_shift` (`tanggal`, `shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. rekap_mpp
CREATE TABLE IF NOT EXISTS `rekap_mpp` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tanggal` VARCHAR(20) NOT NULL,
  `hari` VARCHAR(20) NULL,
  `shift` VARCHAR(30) NOT NULL,
  `jam_masuk` VARCHAR(50) NULL,
  `request` INT NOT NULL DEFAULT 0,
  `terisi` INT NOT NULL DEFAULT 0,
  `vacant` INT NOT NULL DEFAULT 0,
  `hadir` INT NOT NULL DEFAULT 0,
  `kurang` INT NOT NULL DEFAULT 0,
  `persen` INT NOT NULL DEFAULT 0,
  `nama_request` TEXT NULL,
  `nama_hadir` TEXT NULL,
  `status_request` VARCHAR(50) NULL,
  `status_realisasi` VARCHAR(50) NULL,
  `updated_by` VARCHAR(50) NULL,
  `updated_at` VARCHAR(30) NULL,
  UNIQUE KEY `idx_rekap_tgl_shift` (`tanggal`, `shift`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. outbox_wa
CREATE TABLE IF NOT EXISTS `outbox_wa` (
  `id` VARCHAR(50) PRIMARY KEY,
  `waktu` VARCHAR(30) NOT NULL,
  `targets` TEXT NULL,
  `text` TEXT NULL,
  `mentions` TEXT NULL,
  `file_path` VARCHAR(255) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ANTRE',
  `served_at` VARCHAR(30) NULL,
  `done_at` VARCHAR(30) NULL,
  `detail` TEXT NULL,
  `ref` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. log_wa
CREATE TABLE IF NOT EXISTS `log_wa` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `waktu` VARCHAR(30) NOT NULL,
  `tanggal` VARCHAR(20) NULL,
  `shift` VARCHAR(30) NULL,
  `mode` VARCHAR(50) NULL,
  `status` VARCHAR(50) NULL,
  `detail` TEXT NULL,
  `oleh` VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. audit
CREATE TABLE IF NOT EXISTS `audit` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `waktu` VARCHAR(30) NOT NULL,
  `user` VARCHAR(50) NULL,
  `aksi` VARCHAR(50) NOT NULL,
  `detail` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. week_locks
CREATE TABLE IF NOT EXISTS `week_locks` (
  `monday` VARCHAR(20) PRIMARY KEY,
  `locked_at` VARCHAR(30) NOT NULL,
  `locked_by` VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- DATA AWAL (SEEDING)
-- =====================================================================

-- Admin (password: admin123) & Leader (password: leader123)
INSERT INTO `users` (`username`, `nama`, `role`, `bagian`, `password_hash`, `aktif`, `must_change`) VALUES
('admin', 'Administrator', 'ADMIN', '', '$2y$10$tZkn9E1n/zYx1oM9g2h1.Ob6v5mZ1wFqEkea3wM12Ff.8J/s9kP.m', 1, 0),
('leader', 'Leader Shift', 'LEADER', '', '$2y$10$tZkn9E1n/zYx1oM9g2h1.Ob6v5mZ1wFqEkea3wM12Ff.8J/s9kP.m', 1, 0)
ON DUPLICATE KEY UPDATE `username`=`username`;

-- Shift
INSERT INTO `shift` (`kode`, `nama`, `mulai`, `selesai`, `kirim`, `urutan`, `kirim_req`) VALUES
('S1', 'Shift 1', '08:00', '16:00', '08:30', 1, ''),
('S2', 'Shift 2', '16:00', '00:00', '16:30', 2, ''),
('S3', 'Shift 1 Pagi', '06:00', '14:00', '06:30', 3, '')
ON DUPLICATE KEY UPDATE `kode`=`kode`;

-- Kode Jadwal
INSERT INTO `kode_jadwal` (`kode`, `label`, `nama`, `mulai`, `selesai`, `jam`, `kerja`, `shift`, `warna`, `urutan`) VALUES
('NS', 'NS', 'Non Shift', '08:00', '17:00', 8.0, 1, 'S1', '#FFFFFF', 1),
('S1', 'Shift 1', 'Shift 1', '08:00', '16:00', 8.0, 1, 'S1', '#FFFFFF', 2),
('S2', 'Shift 2', 'Shift 2', '16:00', '00:00', 8.0, 1, 'S2', '#FFFFFF', 3),
('LS1', 'LS 1', 'Long Shift 1', '08:00', '20:00', 12.0, 1, 'S1', '#FFFF00', 4),
('LS2', 'LS 2', 'Long Shift 2', '12:00', '00:00', 12.0, 1, 'S2', '#FFFF00', 5),
('HALF', 'HALF', 'Half Day', '08:00', '12:00', 4.0, 1, 'S1', '#FCE4D6', 6),
('OFF', 'OFF', 'Libur', '', '', 0.0, 0, '', '#F4CCCC', 7),
('CUTI', 'CUTI', 'Cuti', '', '', 0.0, 0, '', '#FFF2CC', 8),
('J06', '06', 'Jam Masuk 06', '06:00', '14:00', 8.0, 1, 'S3', '#DDEBF7', 9)
ON DUPLICATE KEY UPDATE `kode`=`kode`;

-- Bagian / Divisi
INSERT INTO `bagian` (`kode`, `nama`, `target_s1`, `target_s2`, `urutan`, `aktif`, `judul_wa`, `mention`, `order_mpp`) VALUES
('INBOUND', 'Inbound', 10, 10, 1, 1, 'INBOUND', '', 1),
('REPLENISH', 'Replenish', 5, 5, 2, 1, 'REPLENISH', '', 1),
('RETURN', 'Return', 8, 8, 3, 1, 'RETURN', '', 1),
('ADMIN CS RETURN', 'Admin CS Return', 2, 2, 4, 1, 'ADMIN CS RETURN', '', 1),
('KOL', 'KOL', 4, 4, 5, 1, 'KOL', '', 1),
('PICKING', 'Picking', 15, 15, 6, 1, 'PICKING', '', 1),
('PACKING', 'Packing', 15, 15, 7, 1, 'PACKING', '', 1)
ON DUPLICATE KEY UPDATE `kode`=`kode`;

-- Operator
INSERT INTO `operator` (`id`, `nik`, `nama`, `bagian`, `grup`, `libur`, `hp`, `aktif`, `pola`, `ket`, `role`) VALUES
('OP0001', '012', 'CHAIRUL BAKTI', 'INBOUND', 'S2', '6', '628120000001', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'),
('OP0002', '011', 'MUHAMMAD ISMAIL', 'INBOUND', 'S1', '6', '628120000002', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'),
('OP0003', '029', 'SAFRUL HUDA', 'INBOUND', 'A', '6,0', '628120000003', 1, 6, 'Rolling A · 6 hari', 'Rolling A'),
('OP0004', '001', 'DAPIT PRATAMA', 'RETURN', 'S2', '6', '628120000004', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'),
('OP0005', '005', 'DIMAS ANGGA SETIAWAN', 'RETURN', 'S2', '6', '628120000005', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'),
('OP0006', '004', 'MUHAMMAD RAIHAN PERMANA', 'RETURN', 'S1', '6', '628120000006', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'),
('OP0007', '003', 'SYAIFUL ANWAR', 'RETURN', 'S1', '6', '628120000007', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'),
('OP0008', '006', 'AMI SARMITA', 'ADMIN CS RETURN', 'S1', '6', '628120000008', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'),
('OP0009', '002', 'MUHAMAD FIKRI PRATAMA', 'RETURN', 'S2', '6', '628120000009', 1, 6, 'Tetap Shift 2 · 6 hari', 'Tetap Shift 2'),
('OP0010', '010', 'AGUS PALENTINO', 'REPLENISH', 'S1', '6', '628120000010', 1, 6, 'Tetap Shift 1 · 6 hari', 'Tetap Shift 1'),
('OP0011', '', 'VACANT', 'INBOUND', 'S1', '6', '', 1, 6, '', 'Tetap Shift 1'),
('OP0012', '', 'VACANT', 'INBOUND', 'S2', '6', '', 1, 6, '', 'Tetap Shift 2')
ON DUPLICATE KEY UPDATE `id`=`id`;

