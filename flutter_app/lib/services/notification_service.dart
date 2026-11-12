import '../config/api_config.dart';
import '../models/notification.dart';
import '../utils/json.dart';
import 'api_service.dart';

/// Kết quả tải danh sách thông báo.
class NotificationPage {
  final List<AppNotification> items;
  final int unreadCount;

  const NotificationPage(this.items, this.unreadCount);
}

/// Thông báo từ máy chủ.
///
/// Phase 1 (web): lấy danh sách định kỳ qua API.
/// Phase 2/3: bổ sung đăng ký push token (FCM/APNs) tại đây.
class NotificationService {
  NotificationService(this._api);

  final ApiService _api;

  Future<NotificationPage> fetch({
    bool unreadOnly = false,
    int limit = 50,
  }) async {
    final res = await _api.get(
      ApiConfig.notifications,
      query: {if (unreadOnly) 'unread': '1', 'limit': '$limit'},
    );
    final data = res.map;
    return NotificationPage(
      asMapList(data['items']).map(AppNotification.fromJson).toList(),
      asInt(data['unread_count']),
    );
  }

  Future<void> markRead(int id) => _api.patch(ApiConfig.notificationRead(id));

  Future<void> markAllRead() => _api.post(ApiConfig.notificationsReadAll);
}
