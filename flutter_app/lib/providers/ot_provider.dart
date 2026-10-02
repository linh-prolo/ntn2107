import '../config/api_config.dart';
import '../models/ot_request.dart';
import '../services/api_service.dart';
import 'safe_change_notifier.dart';
import '../utils/json.dart';

/// Đơn đăng ký OT theo tháng.
class OtProvider extends SafeChangeNotifier {
  OtProvider(this._api) {
    final now = DateTime.now();
    _month = now.month;
    _year = now.year;
  }

  final ApiService _api;

  List<OtRequest> _items = const [];
  late int _month;
  late int _year;
  bool _loading = false;
  bool _submitting = false;
  bool _loaded = false;
  String? _error;

  List<OtRequest> get items => _items;
  int get month => _month;
  int get year => _year;
  bool get isLoading => _loading;
  bool get isSubmitting => _submitting;
  bool get isLoaded => _loaded;
  String? get error => _error;

  double get approvedHours => _items
      .where((e) => e.status == 'approved')
      .fold(0.0, (sum, e) => sum + e.hours);

  Future<void> load({int? month, int? year}) async {
    _month = month ?? _month;
    _year = year ?? _year;
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final res = await _api.get(
        ApiConfig.ot,
        query: {'month': '$_month', 'year': '$_year'},
      );
      _items = asMapList(res.map['items']).map(OtRequest.fromJson).toList();
      _loaded = true;
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> previousMonth() {
    final d = DateTime(_year, _month - 1);
    return load(month: d.month, year: d.year);
  }

  Future<void> nextMonth() {
    final d = DateTime(_year, _month + 1);
    return load(month: d.month, year: d.year);
  }

  /// Gửi đơn OT; trả về thông báo thành công, ném [ApiException] khi lỗi.
  Future<String> create(OtRequestInput input) async {
    final invalid = input.validate();
    if (invalid != null) throw ApiException(invalid);
    _submitting = true;
    notifyListeners();
    try {
      final res = await _api.post(ApiConfig.ot, body: input.toJson());
      _submitting = false;
      await load(month: input.otDate.month, year: input.otDate.year);
      return res.message ?? 'Đã gửi đơn đăng ký OT thành công!';
    } finally {
      _submitting = false;
      notifyListeners();
    }
  }
}
