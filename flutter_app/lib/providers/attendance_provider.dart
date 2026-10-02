import '../config/api_config.dart';
import '../models/attendance.dart';
import '../services/api_service.dart';
import 'safe_change_notifier.dart';

/// Chấm công: trạng thái hôm nay + lịch sử theo tháng.
class AttendanceProvider extends SafeChangeNotifier {
  AttendanceProvider(this._api) {
    final now = DateTime.now();
    _month = now.month;
    _year = now.year;
  }

  final ApiService _api;

  AttendanceStatus? _status;
  late int _month;
  late int _year;
  bool _loading = false;
  bool _submitting = false;
  String? _error;
  Duration _clockOffset = Duration.zero;

  AttendanceStatus? get status => _status;

  /// Giờ hiện tại theo đồng hồ máy chủ (Asia/Ho_Chi_Minh), bù lệch giờ thiết bị.
  DateTime now() => DateTime.now().add(_clockOffset);
  int get month => _month;
  int get year => _year;
  bool get isLoading => _loading;
  bool get isSubmitting => _submitting;
  String? get error => _error;

  bool get isCurrentMonth {
    final now = DateTime.now();
    return _month == now.month && _year == now.year;
  }

  Future<void> load({int? month, int? year}) async {
    _month = month ?? _month;
    _year = year ?? _year;
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final res = await _api.get(
        ApiConfig.attendance,
        query: {'month': '$_month', 'year': '$_year'},
      );
      _setStatus(AttendanceStatus.fromJson(res.map));
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  void _setStatus(AttendanceStatus status) {
    _status = status;
    final serverTime = status.serverTime;
    if (serverTime != null) {
      _clockOffset = serverTime.difference(DateTime.now());
    }
  }

  Future<void> previousMonth() {
    final d = DateTime(_year, _month - 1);
    return load(month: d.month, year: d.year);
  }

  Future<void> nextMonth() {
    if (isCurrentMonth) return Future.value();
    final d = DateTime(_year, _month + 1);
    return load(month: d.month, year: d.year);
  }

  /// Gửi chấm công vào/ra. Trả về thông báo từ máy chủ; ném [ApiException] khi lỗi.
  Future<String> submit({
    required bool checkIn,
    required double lat,
    required double lng,
    required String deviceId,
    required String photoDataUrl,
  }) async {
    _submitting = true;
    notifyListeners();
    try {
      final res = await _api.post(
        checkIn ? ApiConfig.checkIn : ApiConfig.checkOut,
        query: {'month': '$_month', 'year': '$_year'},
        body: {
          'lat': lat,
          'lng': lng,
          'device_id': deviceId,
          'photo_data': photoDataUrl,
        },
      );
      if (res.data is Map) {
        _setStatus(AttendanceStatus.fromJson(res.map));
      }
      return res.message ??
          (checkIn ? 'Chấm công vào thành công!' : 'Chấm công ra thành công!');
    } finally {
      _submitting = false;
      notifyListeners();
    }
  }
}
