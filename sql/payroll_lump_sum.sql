-- Chạy một lần trước khi bật "Trả lương khoán" trong hồ sơ nhân viên.
-- Phiếu lương lưu cờ riêng để giữ nguyên trạng thái tại thời điểm tính lương.
ALTER TABLE employee_profiles
    ADD COLUMN is_lump_sum TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE payroll_slips
    ADD COLUMN is_lump_sum TINYINT(1) NOT NULL DEFAULT 0;
