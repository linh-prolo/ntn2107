import '../utils/json.dart';

/// Thông báo từ máy chủ (bảng notifications).
class AppNotification {
  final int id;
  final String title;
  final String message;
  final String type;
  final int? referenceId;
  final bool isRead;
  final DateTime? createdAt;

  const AppNotification({
    required this.id,
    required this.title,
    required this.message,
    this.type = 'general',
    this.referenceId,
    this.isRead = false,
    this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) =>
      AppNotification(
        id: asInt(json['id']),
        title: asString(json['title']),
        message: asString(json['message']),
        type: asString(json['type'], 'general'),
        referenceId: asIntOrNull(json['reference_id']),
        isRead: asBool(json['is_read']),
        createdAt: asDateTime(json['created_at']),
      );

  AppNotification copyWith({bool? isRead}) => AppNotification(
    id: id,
    title: title,
    message: message,
    type: type,
    referenceId: referenceId,
    isRead: isRead ?? this.isRead,
    createdAt: createdAt,
  );
}
