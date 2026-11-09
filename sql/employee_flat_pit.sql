-- Chạy một lần trước khi bật "Khấu trừ thuế TNCN 10%" trong hồ sơ nhân viên (lao động thời vụ).
ALTER TABLE employee_profiles
    ADD COLUMN flat_pit_10 TINYINT(1) NOT NULL DEFAULT 0;
