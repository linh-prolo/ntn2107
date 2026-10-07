/// Hằng số & nhãn tiếng Việt dùng chung (khớp với mobile/common.php).
class AppConstants {
  AppConstants._();

  static const String appName = 'NTN Nhân viên';

  /// Chiều rộng tối đa của nội dung trên web/tablet (giống .mobile-shell 480px).
  static const double maxContentWidth = 520;

  static const Map<String, String> leaveTypes = {
    'annual': 'Nghỉ phép năm',
    'sick': 'Nghỉ ốm',
    'unpaid': 'Nghỉ không lương',
    'other': 'Lý do khác',
  };

  static const Map<String, String> leaveTypeShortLabels = {
    'annual': 'Phép năm',
    'sick': 'Nghỉ ốm',
    'unpaid': 'Không lương',
    'other': 'Khác',
  };

  static const Map<String, String> otTypes = {
    'weekday': 'Ngày thường',
    'weekend': 'Cuối tuần',
    'holiday': 'Ngày lễ',
    'night_weekday': 'Đêm thường',
    'night_weekend': 'Đêm cuối tuần',
    'night_holiday': 'Đêm ngày lễ',
  };

  static const Map<String, String> requestStatuses = {
    'pending': 'Chờ duyệt',
    'approved': 'Đã duyệt',
    'rejected': 'Từ chối',
  };

  static const Map<String, String> locationFlags = {
    'verified': 'Đã xác minh',
    'outside': 'Ngoài phạm vi',
    'no_gps': 'Không GPS',
    'unknown': 'Không rõ',
  };

  static String leaveTypeLabel(String type) =>
      leaveTypeShortLabels[type] ?? type;

  static String otTypeLabel(String type) => otTypes[type] ?? type;

  static String statusLabel(String status) =>
      requestStatuses[status] ?? requestStatuses['pending']!;

  /// Số giờ OT tối đa/ngày (giống mobile/ot.php).
  static const double maxOtHours = 12;

  /// Kích thước ảnh chấm công sau khi nén (giống mobile/index.php).
  static const int maxPhotoBytes = 300000;
}

/// Khóa lưu trữ cục bộ.
class StorageKeys {
  StorageKeys._();

  static const String token = 'auth_token';
  static const String user = 'auth_user';
  static const String deviceId = 'device_id';
}
