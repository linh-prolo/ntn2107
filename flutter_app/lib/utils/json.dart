/// Hàm chuyển đổi kiểu an toàn khi đọc JSON từ PHP
/// (số có thể trả về dạng chuỗi, bool có thể là 0/1...).
int asInt(Object? v, [int fallback = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  if (v is String) {
    return int.tryParse(v) ?? double.tryParse(v)?.toInt() ?? fallback;
  }
  if (v is bool) return v ? 1 : 0;
  return fallback;
}

int? asIntOrNull(Object? v) => v == null ? null : asInt(v);

double asDouble(Object? v, [double fallback = 0]) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v) ?? fallback;
  return fallback;
}

bool asBool(Object? v) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  if (v is String) return v == '1' || v.toLowerCase() == 'true';
  return false;
}

String asString(Object? v, [String fallback = '']) {
  if (v == null) return fallback;
  return v.toString();
}

String? asStringOrNull(Object? v) {
  if (v == null) return null;
  final s = v.toString();
  return s.isEmpty ? null : s;
}

/// Parse "Y-m-d" hoặc "Y-m-d H:i:s" (giờ máy chủ, Asia/Ho_Chi_Minh) thành DateTime cục bộ (không đổi múi giờ).
DateTime? asDateTime(Object? v) {
  if (v == null) return null;
  final s = v.toString().trim();
  if (s.isEmpty || s.startsWith('0000-00-00')) return null;
  return DateTime.tryParse(s.replaceFirst(' ', 'T'));
}

Map<String, dynamic> asMap(Object? v) =>
    v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};

List<Map<String, dynamic>> asMapList(Object? v) => v is List
    ? v.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
    : <Map<String, dynamic>>[];
