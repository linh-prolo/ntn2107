import 'dart:async';

import '../models/notification.dart';
import '../services/api_service.dart';
import '../services/notification_service.dart';
import 'safe_change_notifier.dart';

/// Danh sách thông báo + số chưa đọc (tự làm mới định kỳ).
class NotificationProvider extends SafeChangeNotifier {
  NotificationProvider(this._service);

  static const Duration pollInterval = Duration(minutes: 2);

  final NotificationService _service;

  List<AppNotification> _items = const [];
  int _unreadCount = 0;
  bool _loading = false;
  bool _loaded = false;
  String? _error;
  Timer? _timer;

  List<AppNotification> get items => _items;
  int get unreadCount => _unreadCount;
  bool get isLoading => _loading;
  bool get isLoaded => _loaded;
  String? get error => _error;

  void startPolling() {
    _timer?.cancel();
    refreshUnreadCount();
    _timer = Timer.periodic(pollInterval, (_) => refreshUnreadCount());
  }

  void stopPolling() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> load() async {
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      final page = await _service.fetch();
      _items = page.items;
      _unreadCount = page.unreadCount;
      _loaded = true;
    } on ApiException catch (e) {
      _error = e.message;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> refreshUnreadCount() async {
    try {
      final page = await _service.fetch(unreadOnly: true, limit: 1);
      if (page.unreadCount != _unreadCount) {
        _unreadCount = page.unreadCount;
        notifyListeners();
      }
    } on ApiException {
      // Bỏ qua lỗi khi làm mới nền.
    }
  }

  Future<void> markRead(AppNotification n) async {
    if (n.isRead) return;
    await _service.markRead(n.id);
    _items = [
      for (final e in _items) e.id == n.id ? e.copyWith(isRead: true) : e,
    ];
    if (_unreadCount > 0) _unreadCount--;
    notifyListeners();
  }

  Future<void> markAllRead() async {
    await _service.markAllRead();
    _items = [for (final e in _items) e.copyWith(isRead: true)];
    _unreadCount = 0;
    notifyListeners();
  }

  @override
  void dispose() {
    stopPolling();
    super.dispose();
  }
}
