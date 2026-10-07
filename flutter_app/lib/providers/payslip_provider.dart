import '../config/api_config.dart';
import '../models/payslip.dart';
import '../services/api_service.dart';
import 'safe_change_notifier.dart';
import '../utils/json.dart';

/// Phiếu lương: danh sách kỳ đã duyệt + chi tiết kỳ đang chọn.
class PayslipProvider extends SafeChangeNotifier {
  PayslipProvider(this._api);

  final ApiService _api;

  List<PayslipSummary> _items = const [];
  final Map<int, Payslip> _details = {};
  int? _selectedId;
  bool _loading = false;
  bool _loadingDetail = false;
  bool _loaded = false;
  String? _error;
  String? _detailError;

  List<PayslipSummary> get items => _items;
  int? get selectedId => _selectedId;
  Payslip? get selected => _selectedId == null ? null : _details[_selectedId];
  bool get isLoading => _loading;
  bool get isLoadingDetail => _loadingDetail;
  bool get isLoaded => _loaded;
  String? get error => _error;
  String? get detailError => _detailError;

  Future<void> load() async {
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final res = await _api.get(ApiConfig.payslip);
      _items = asMapList(
        res.map['items'],
      ).map(PayslipSummary.fromJson).toList();
      _details.clear();
      _loaded = true;
      final keep = _items.any((e) => e.id == _selectedId);
      _selectedId = keep
          ? _selectedId
          : (_items.isEmpty ? null : _items.first.id);
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
    if (_selectedId != null) await select(_selectedId!);
  }

  Future<void> select(int id) async {
    _selectedId = id;
    _detailError = null;
    if (_details.containsKey(id)) {
      notifyListeners();
      return;
    }
    _loadingDetail = true;
    notifyListeners();
    try {
      final res = await _api.get(ApiConfig.payslipDetail(id));
      _details[id] = Payslip.fromJson(res.map);
    } on ApiException catch (e) {
      _detailError = e.message;
    } finally {
      _loadingDetail = false;
      notifyListeners();
    }
  }
}
