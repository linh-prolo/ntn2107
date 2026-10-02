/// Cấu hình API backend.
///
/// Đổi địa chỉ máy chủ khi build/chạy thử:
/// `flutter run -d chrome --dart-define=API_BASE_URL=http://localhost/erp`
class ApiConfig {
  ApiConfig._();

  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://ntnvn.com/erp',
  );

  static const Duration timeout = Duration(seconds: 30);

  static const String mobilePrefix = '/api/mobile';

  static const String login = '$mobilePrefix/login';
  static const String logout = '$mobilePrefix/logout';
  static const String me = '$mobilePrefix/me';
  static const String attendance = '$mobilePrefix/attendance';
  static const String checkIn = '$mobilePrefix/attendance/checkin';
  static const String checkOut = '$mobilePrefix/attendance/checkout';
  static const String ot = '$mobilePrefix/ot';
  static const String leave = '$mobilePrefix/leave';
  static const String payslip = '$mobilePrefix/payslip';
  static const String notifications = '$mobilePrefix/notifications';
  static const String notificationsReadAll =
      '$mobilePrefix/notifications/read-all';

  static String payslipDetail(int id) => '$payslip/$id';
  static String notificationRead(int id) => '$notifications/$id/read';

  /// Ghép URL đầy đủ, bỏ dấu "/" thừa ở cuối [baseUrl].
  static Uri uri(String path, [Map<String, String>? query]) {
    final base = baseUrl.endsWith('/')
        ? baseUrl.substring(0, baseUrl.length - 1)
        : baseUrl;
    final uri = Uri.parse('$base$path');
    return (query == null || query.isEmpty)
        ? uri
        : uri.replace(queryParameters: query);
  }
}
