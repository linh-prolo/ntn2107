-- ============================================================
-- ZKTeco SpeedFace V5L — Database Migration
-- ============================================================
-- Chạy script này một lần để cập nhật schema cho tích hợp máy chấm công.
-- ============================================================

-- 1. Thêm cột device_sn vào attendance_logs (bỏ qua nếu đã có)
ALTER TABLE attendance_logs
    ADD COLUMN IF NOT EXISTS device_sn VARCHAR(50) NULL
    COMMENT 'Serial number máy chấm công ZKTeco'
    AFTER device_id;

-- 2. (Tuỳ chọn) Cập nhật cột source nếu là ENUM — bỏ comment nếu cần
-- ALTER TABLE attendance_logs
--     MODIFY COLUMN source ENUM('manual','device','mobile') DEFAULT 'manual';

-- 3. Bảng quản lý thiết bị ZKTeco
CREATE TABLE IF NOT EXISTS zkteco_devices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    device_name   VARCHAR(100) NOT NULL,
    device_sn     VARCHAR(50)  NULL COMMENT 'Serial number',
    ip_address    VARCHAR(45)  NULL,
    port          INT          NOT NULL DEFAULT 4370,
    location      VARCHAR(100) NULL COMMENT 'Vị trí đặt máy',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_sync_at  DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bảng log push từ máy (để debug và audit)
CREATE TABLE IF NOT EXISTS zkteco_push_logs (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    device_sn     VARCHAR(50) NULL,
    raw_data      TEXT        NULL,
    processed     TINYINT(1)  NOT NULL DEFAULT 0,
    records_count INT         NOT NULL DEFAULT 0,
    error_msg     TEXT        NULL,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
