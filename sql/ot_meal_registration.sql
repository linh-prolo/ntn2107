-- Chạy một lần trước khi triển khai đăng ký ăn tăng ca và tính lại lương.
-- Đơn OT cũ mặc định không đăng ký ăn; khoản trừ ăn OT chỉ trừ sau thuế.
ALTER TABLE overtime_requests
    ADD COLUMN ot_meal_registered TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE payroll_slips
    ADD COLUMN ot_meal_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
    ADD COLUMN ot_meal_deduct_days INT NOT NULL DEFAULT 0;
