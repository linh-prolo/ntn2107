import '../utils/json.dart';

/// 1 dòng chấm công (bảng attendance_logs).
class AttendanceLog {
  final int id;
  final DateTime? workDate;
  final DateTime? checkIn;
  final DateTime? checkOut;
  final double workHours;
  final bool isLate;
  final int lateMinutes;
  final bool earlyLeave;
  final int earlyLeaveMinutes;
  final bool missingCheckout;
  final String? locationFlag;

  const AttendanceLog({
    required this.id,
    this.workDate,
    this.checkIn,
    this.checkOut,
    this.workHours = 0,
    this.isLate = false,
    this.lateMinutes = 0,
    this.earlyLeave = false,
    this.earlyLeaveMinutes = 0,
    this.missingCheckout = false,
    this.locationFlag,
  });

  factory AttendanceLog.fromJson(Map<String, dynamic> json) => AttendanceLog(
    id: asInt(json['id']),
    workDate: asDateTime(json['work_date']),
    checkIn: asDateTime(json['check_in']),
    checkOut: asDateTime(json['check_out']),
    workHours: asDouble(json['work_hours']),
    isLate: asBool(json['is_late']),
    lateMinutes: asInt(json['late_minutes']),
    earlyLeave: asBool(json['early_leave']),
    earlyLeaveMinutes: asInt(json['early_leave_minutes']),
    missingCheckout: asBool(json['missing_checkout']),
    locationFlag: asStringOrNull(json['check_in_location_flag']),
  );
}

/// Ca làm việc được phân cho nhân viên hôm nay.
class WorkShift {
  final String name;
  final String startTime;
  final String endTime;
  final bool isNightShift;

  const WorkShift({
    required this.name,
    required this.startTime,
    required this.endTime,
    this.isNightShift = false,
  });

  factory WorkShift.fromJson(Map<String, dynamic> json) => WorkShift(
    name: asString(json['shift_name']),
    startTime: asString(json['start_time']),
    endTime: asString(json['end_time']),
    isNightShift: asBool(json['is_night_shift']),
  );
}

/// Thống kê tháng hiện tại.
class AttendanceSummary {
  final int workDays;
  final double workHours;
  final int lateDays;
  final int leaveDays;

  const AttendanceSummary({
    this.workDays = 0,
    this.workHours = 0,
    this.lateDays = 0,
    this.leaveDays = 0,
  });

  factory AttendanceSummary.fromJson(Map<String, dynamic> json) =>
      AttendanceSummary(
        workDays: asInt(json['work_days']),
        workHours: asDouble(json['work_hours']),
        lateDays: asInt(json['late_days']),
        leaveDays: asInt(json['leave_days']),
      );
}

/// Phạm vi vị trí cho phép chấm công (chính sách phòng ban / cài đặt chung).
class LocationConfig {
  final bool enabled;
  final double lat;
  final double lng;
  final int radius;
  final String name;

  const LocationConfig({
    this.enabled = false,
    this.lat = 0,
    this.lng = 0,
    this.radius = 0,
    this.name = 'Công ty',
  });

  factory LocationConfig.fromJson(Map<String, dynamic> json) => LocationConfig(
    enabled: asBool(json['enabled']),
    lat: asDouble(json['lat']),
    lng: asDouble(json['lng']),
    radius: asInt(json['radius']),
    name: asString(json['name'], 'Công ty'),
  );
}

/// Kết quả GET /attendance.
class AttendanceStatus {
  final DateTime? serverTime;
  final AttendanceLog? today;
  final bool canCheckIn;
  final bool canCheckOut;
  final WorkShift? shift;
  final AttendanceSummary summary;
  final LocationConfig locationConfig;
  final int month;
  final int year;
  final List<AttendanceLog> logs;

  const AttendanceStatus({
    this.serverTime,
    this.today,
    this.canCheckIn = false,
    this.canCheckOut = false,
    this.shift,
    this.summary = const AttendanceSummary(),
    this.locationConfig = const LocationConfig(),
    required this.month,
    required this.year,
    this.logs = const [],
  });

  bool get isCompleted => today?.checkOut != null;

  factory AttendanceStatus.fromJson(Map<String, dynamic> json) {
    final today = json['today'];
    final shift = json['shift'];
    return AttendanceStatus(
      serverTime: asDateTime(json['server_time']),
      today: today is Map ? AttendanceLog.fromJson(asMap(today)) : null,
      canCheckIn: asBool(json['can_check_in']),
      canCheckOut: asBool(json['can_check_out']),
      shift: shift is Map ? WorkShift.fromJson(asMap(shift)) : null,
      summary: AttendanceSummary.fromJson(asMap(json['summary'])),
      locationConfig: LocationConfig.fromJson(asMap(json['location_config'])),
      month: asInt(json['month'], DateTime.now().month),
      year: asInt(json['year'], DateTime.now().year),
      logs: asMapList(json['logs']).map(AttendanceLog.fromJson).toList(),
    );
  }
}
