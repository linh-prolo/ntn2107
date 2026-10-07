# NTN Nhân viên (Flutter)

Ứng dụng dành cho nhân viên NTN: chấm công (GPS + ảnh), đăng ký OT, xin nghỉ phép,
xem phiếu lương, thông báo và tài khoản. Giai đoạn 1 chạy thử trên **web (Chrome)**;
cùng mã nguồn sẽ build Android/iOS ở giai đoạn sau.

Backend: các API JSON trong `api/mobile/*` của ERP (xem mục "Backend" bên dưới).

## Yêu cầu

- Flutter SDK ≥ 3.35 (Dart ≥ 3.9), Chrome.

## Chạy thử trên Chrome

```bash
cd flutter_app
flutter pub get
# Mặc định gọi https://ntnvn.com/erp
flutter run -d chrome
# Hoặc chỉ định máy chủ khác (ví dụ máy chủ thử nghiệm/local):
flutter run -d chrome --dart-define=API_BASE_URL=http://localhost/erp
```

Build web tĩnh:

```bash
flutter build web --release --dart-define=API_BASE_URL=https://ntnvn.com/erp
# Thêm --no-web-resources-cdn nếu máy không truy cập được gstatic.com
```

Kiểm tra mã & test:

```bash
flutter analyze
flutter test
```

## Cấu trúc

```
lib/
├── config/     # api_config (API_BASE_URL), constants (loại OT/phép...), theme
├── models/     # user, attendance, ot_request, leave_request, payslip, notification
├── services/   # api_service (HTTP + lỗi), auth, storage, notification, location
├── providers/  # Provider cho auth, chấm công, OT, phép, lương, thông báo
├── screens/    # login, home (tab), attendance, ot, leave, payslip, notifications, account
├── widgets/    # bottom_nav_bar, status_badge, form_fields, common
└── main.dart
test/           # unit + widget test (backend giả lập bằng MockClient)
```

## Backend (`/erp/api/mobile/*`)

1. Chạy migration tạo bảng token (một lần):
   ```bash
   php api/master/migrate_mobile_api_tokens.php
   ```
2. Apache cần bật `mod_rewrite`; `.htaccess` đã có rule cho `/api/mobile/*` và
   chuyển tiếp header `Authorization`.
3. CORS: khi chạy Flutter web khác domain (ví dụ `http://localhost:xxxx`), đặt biến
   môi trường `MOBILE_API_ALLOWED_ORIGINS` (phân tách bằng dấu phẩy) cho các origin
   được phép. App build và đặt cùng domain với ERP thì không cần.

| Method | Endpoint | Mô tả |
|---|---|---|
| POST | `/api/mobile/login` | Đăng nhập (mã NV hoặc tên đăng nhập + mật khẩu) → token |
| POST | `/api/mobile/logout` | Thu hồi token |
| GET | `/api/mobile/me` | Thông tin nhân viên |
| GET | `/api/mobile/attendance?month=&year=` | Trạng thái hôm nay + lịch sử tháng |
| POST | `/api/mobile/attendance/checkin` · `/checkout` | Chấm công với GPS + ảnh |
| GET/POST | `/api/mobile/ot` | Danh sách / tạo đơn OT |
| GET/POST | `/api/mobile/leave` | Danh sách / tạo đơn nghỉ phép |
| GET | `/api/mobile/payslip` · `/payslip/{id}` | Danh sách / chi tiết phiếu lương |
| GET | `/api/mobile/notifications` | Danh sách thông báo |
| PATCH | `/api/mobile/notifications/{id}/read` | Đánh dấu đã đọc |

Token gửi qua header `Authorization` (kiểu Bearer <token>), hết hạn sau 30 ngày; nhân viên
chỉ truy cập được dữ liệu của chính mình. Các quy tắc nghiệp vụ (vị trí/IP chấm công,
tối đa 1 ngày phép năm/tháng, giới hạn giờ OT...) giống trang `/erp/mobile/*`.

## Ghi chú / hạn chế giai đoạn 1

- Trên web, token lưu trong `localStorage` để giữ đăng nhập khi tải lại trang.
- Chấm công yêu cầu ảnh: trên Chrome máy tính sẽ mở hộp chọn file thay vì camera.
- Thông báo đẩy (FCM/APNs) sẽ bổ sung khi build Android/iOS; hiện app kiểm tra
  thông báo chưa đọc định kỳ (2 phút) khi đang mở.
- Android/iOS: đã khai báo quyền vị trí/camera (AndroidManifest, Info.plist); cần kiểm thử thêm trên thiết bị thật.
