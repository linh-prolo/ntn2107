import '../config/api_config.dart';
import '../models/leave_request.dart';
import '../services/api_service.dart';
import 'safe_change_notifier.dart';
import '../utils/json.dart';

/// Đơn xin nghỉ phép.
class LeaveProvider extends SafeChangeNotifier {
  LeaveProvider(this._api);

  final ApiService _api;

  List<LeaveRequest> _items = const [];
  bool _loading = false;
  bool _submitting = false;
  bool _loaded = false;
  String? _error;

  List<LeaveRequest> get items => _items;
  bool get isLoading => _loading;
  bool get isSubmitting => _submitting;
  bool get isLoaded => _loaded;
  String? get error => _error;

  Future<void> load() async {
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final res = await _api.get(ApiConfig.leave, query: {'limit': '30'});
      _items = asMapList(res.map['items']).map(LeaveRequest.fromJson).toList();
      _loaded = true;
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  /// Gửi đơn nghỉ; trả về thông báo thành công, ném [ApiException] khi lỗi.
  Future<String> create(LeaveRequestInput input) async {
    final invalid = input.validate();
    if (invalid != null) throw ApiException(invalid);
    _submitting = true;
    notifyListeners();
    try {
      final res = await _api.post(ApiConfig.leave, body: input.toJson());
      _submitting = false;
      await load();
      return res.message ?? 'Đã gửi đơn xin nghỉ phép thành công!';
    } finally {
      _submitting = false;
      notifyListeners();
    }
  }
}
