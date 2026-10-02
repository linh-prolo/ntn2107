import '../utils/formatters.dart';
import '../utils/json.dart';

/// Đơn xin nghỉ phép (bảng leave_requests).
class LeaveRequest {
  final int id;
  final String leaveType;
  final DateTime? startDate;
  final DateTime? endDate;
  final double totalDays;
  final String reason;
  final String status;
  final String? rejectReason;
  final String? approverName;
  final DateTime? createdAt;

  const LeaveRequest({
    required this.id,
    required this.leaveType,
    this.startDate,
    this.endDate,
    required this.totalDays,
    required this.reason,
    this.status = 'pending',
    this.rejectReason,
    this.approverName,
    this.createdAt,
  });

  factory LeaveRequest.fromJson(Map<String, dynamic> json) => LeaveRequest(
    id: asInt(json['id']),
    leaveType: asString(json['leave_type'], 'other'),
    startDate: asDateTime(json['start_date']),
    endDate: asDateTime(json['end_date']),
    totalDays: asDouble(json['total_days']),
    reason: asString(json['reason']),
    status: asString(json['status'], 'pending'),
    rejectReason: asStringOrNull(json['reject_reason']),
    approverName: asStringOrNull(json['approver_name']),
    createdAt: asDateTime(json['created_at']),
  );
}

/// Dữ liệu gửi lên khi tạo đơn nghỉ phép.
class LeaveRequestInput {
  final String leaveType;
  final DateTime startDate;
  final DateTime endDate;
  final String reason;

  const LeaveRequestInput({
    required this.leaveType,
    required this.startDate,
    required this.endDate,
    required this.reason,
  });

  /// Số ngày nghỉ (tính cả ngày đầu và cuối, giống PHP).
  int get totalDays =>
      DateTime.utc(endDate.year, endDate.month, endDate.day)
          .difference(
            DateTime.utc(startDate.year, startDate.month, startDate.day),
          )
          .inDays +
      1;

  /// Kiểm tra phía client trước khi gửi (máy chủ vẫn kiểm tra lại).
  String? validate() {
    if (reason.trim().isEmpty) return 'Vui lòng nhập lý do.';
    if (endDate.isBefore(startDate)) {
      return 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.';
    }
    if (leaveType == 'annual' && totalDays > 1) {
      return 'Phép năm chỉ được tối đa 1 ngày mỗi tháng.';
    }
    return null;
  }

  Map<String, dynamic> toJson() => {
    'leave_type': leaveType,
    'start_date': Fmt.apiDate(startDate),
    'end_date': Fmt.apiDate(endDate),
    'reason': reason.trim(),
  };
}
