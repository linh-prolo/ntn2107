import '../utils/formatters.dart';
import '../utils/json.dart';

/// Đơn đăng ký OT (bảng overtime_requests).
class OtRequest {
  final int id;
  final DateTime? otDate;
  final String otType;
  final String startTime;
  final String endTime;
  final double hours;
  final String reason;
  final String status;
  final String? rejectReason;
  final String? approverName;
  final DateTime? createdAt;

  const OtRequest({
    required this.id,
    this.otDate,
    required this.otType,
    required this.startTime,
    required this.endTime,
    required this.hours,
    required this.reason,
    this.status = 'pending',
    this.rejectReason,
    this.approverName,
    this.createdAt,
  });

  factory OtRequest.fromJson(Map<String, dynamic> json) => OtRequest(
    id: asInt(json['id']),
    otDate: asDateTime(json['ot_date']),
    otType: asString(json['ot_type'], 'weekday'),
    startTime: asString(json['start_time']),
    endTime: asString(json['end_time']),
    hours: asDouble(json['hours']),
    reason: asString(json['reason']),
    status: asString(json['status'], 'pending'),
    rejectReason: asStringOrNull(json['reject_reason']),
    approverName: asStringOrNull(json['approver_name']),
    createdAt: asDateTime(json['created_at']),
  );
}

/// Dữ liệu gửi lên khi tạo đơn OT.
class OtRequestInput {
  final DateTime otDate;
  final String otType;
  final String startTime; // HH:mm
  final double hours;
  final String reason;

  const OtRequestInput({
    required this.otDate,
    required this.otType,
    required this.startTime,
    required this.hours,
    required this.reason,
  });

  /// Kiểm tra phía client trước khi gửi (máy chủ vẫn kiểm tra lại).
  String? validate({DateTime? today}) {
    final now = today ?? DateTime.now();
    final todayDate = DateTime(now.year, now.month, now.day);
    final date = DateTime(otDate.year, otDate.month, otDate.day);
    if (date.isBefore(todayDate)) {
      return 'Không thể đăng ký OT cho ngày đã qua.';
    }
    if (!RegExp(r'^\d{2}:\d{2}$').hasMatch(startTime)) {
      return 'Vui lòng nhập giờ bắt đầu.';
    }
    if (hours <= 0) return 'Số giờ OT phải lớn hơn 0.';
    if (hours > 12) return 'OT không được vượt quá 12 giờ/ngày.';
    if (reason.trim().isEmpty) return 'Vui lòng nhập lý do OT.';
    return null;
  }

  Map<String, dynamic> toJson() => {
    'ot_date': Fmt.apiDate(otDate),
    'ot_type': otType,
    'start_time': startTime,
    'hours': hours,
    'reason': reason.trim(),
  };
}
