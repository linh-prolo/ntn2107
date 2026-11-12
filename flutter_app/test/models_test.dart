import 'package:flutter_test/flutter_test.dart';
import 'package:ntn_employee/models/attendance.dart';
import 'package:ntn_employee/models/leave_request.dart';
import 'package:ntn_employee/models/notification.dart';
import 'package:ntn_employee/models/ot_request.dart';
import 'package:ntn_employee/models/payslip.dart';
import 'package:ntn_employee/models/user.dart';

import 'fixtures.dart';

void main() {
  test('User parses and round-trips through toJson', () {
    final user = User.fromJson(userJson);
    expect(user.id, 93);
    expect(user.employeeCode, 'NVTEST');
    expect(user.initial, 'N');
    expect(user.dateJoined, DateTime(2024, 3, 1));
    final again = User.fromJson(user.toJson());
    expect(again.fullName, user.fullName);
    expect(again.dateJoined, user.dateJoined);
    expect(again.departmentName, 'Ban Giám đốc');
  });

  test('AttendanceStatus parses today, shift, summary and logs', () {
    final s = AttendanceStatus.fromJson(attendanceJson);
    expect(s.canCheckIn, isFalse);
    expect(s.canCheckOut, isTrue);
    expect(s.today!.checkIn, DateTime(2026, 10, 2, 8, 5));
    expect(s.today!.isLate, isTrue);
    expect(s.today!.lateMinutes, 5);
    expect(s.isCompleted, isFalse);
    expect(s.shift!.name, 'Ca hành chính');
    expect(s.summary.workHours, 8.5);
    expect(s.locationConfig.enabled, isTrue);
    expect(s.locationConfig.radius, 450);
    expect(s.logs.single.locationFlag, 'outside');
    expect(s.month, 10);
  });

  test('AttendanceStatus tolerates missing optional sections', () {
    final s = AttendanceStatus.fromJson({'month': 1, 'year': 2026});
    expect(s.today, isNull);
    expect(s.shift, isNull);
    expect(s.logs, isEmpty);
    expect(s.locationConfig.enabled, isFalse);
  });

  test('OtRequest parses', () {
    final ot = OtRequest.fromJson(otJson);
    expect(ot.otDate, DateTime(2026, 10, 5));
    expect(ot.hours, 2.0);
    expect(ot.status, 'approved');
    expect(ot.approverName, 'Trưởng phòng');
  });

  group('OtRequestInput.validate', () {
    final today = DateTime(2026, 10, 2, 10);
    OtRequestInput input({
      DateTime? date,
      String start = '17:30',
      double hours = 2,
      String reason = 'Lý do',
    }) => OtRequestInput(
      otDate: date ?? DateTime(2026, 10, 2),
      otType: 'weekday',
      startTime: start,
      hours: hours,
      reason: reason,
    );

    test('accepts valid input and serialises API fields', () {
      final i = input();
      expect(i.validate(today: today), isNull);
      expect(i.toJson(), {
        'ot_date': '2026-10-02',
        'ot_type': 'weekday',
        'start_time': '17:30',
        'hours': 2.0,
        'reason': 'Lý do',
      });
    });

    test('rejects past dates, bad hours and empty reason', () {
      expect(
        input(date: DateTime(2026, 10, 1)).validate(today: today),
        isNotNull,
      );
      expect(input(hours: 0).validate(today: today), isNotNull);
      expect(input(hours: 12.5).validate(today: today), isNotNull);
      expect(input(reason: '  ').validate(today: today), isNotNull);
      expect(input(start: '').validate(today: today), isNotNull);
    });
  });

  test('LeaveRequest parses', () {
    final l = LeaveRequest.fromJson(leaveJson);
    expect(l.leaveType, 'sick');
    expect(l.totalDays, 2);
    expect(l.rejectReason, 'Thiếu giấy tờ');
  });

  group('LeaveRequestInput', () {
    LeaveRequestInput input(
      String type,
      DateTime from,
      DateTime to, [
      String reason = 'Việc riêng',
    ]) => LeaveRequestInput(
      leaveType: type,
      startDate: from,
      endDate: to,
      reason: reason,
    );

    test('counts days inclusively', () {
      expect(
        input('sick', DateTime(2026, 10, 6), DateTime(2026, 10, 8)).totalDays,
        3,
      );
      expect(
        input('annual', DateTime(2026, 10, 6), DateTime(2026, 10, 6)).totalDays,
        1,
      );
    });

    test('limits annual leave to 1 day and checks dates/reason', () {
      expect(
        input(
          'annual',
          DateTime(2026, 10, 6),
          DateTime(2026, 10, 6),
        ).validate(),
        isNull,
      );
      expect(
        input(
          'annual',
          DateTime(2026, 10, 6),
          DateTime(2026, 10, 7),
        ).validate(),
        isNotNull,
      );
      expect(
        input('sick', DateTime(2026, 10, 7), DateTime(2026, 10, 6)).validate(),
        isNotNull,
      );
      expect(
        input(
          'sick',
          DateTime(2026, 10, 6),
          DateTime(2026, 10, 6),
          '',
        ).validate(),
        isNotNull,
      );
    });

    test('serialises API fields', () {
      expect(
        input('other', DateTime(2026, 1, 5), DateTime(2026, 1, 6)).toJson(),
        {
          'leave_type': 'other',
          'start_date': '2026-01-05',
          'end_date': '2026-01-06',
          'reason': 'Việc riêng',
        },
      );
    });
  });

  test('Payslip parses breakdown and totals', () {
    final p = Payslip.fromJson(payslipJson);
    expect(p.periodLabel, 'Tháng 9/2026');
    expect(p.allowances, hasLength(3));
    expect(p.otItems.first.label, 'OT ngày thường');
    expect(p.deductionTotal, 890000);
    expect(p.netSalary, 8240000);
    expect(p.calculatedNet, 8240000);
    expect(p.hasNetMismatch, isFalse);
  });

  test('AppNotification parses and copyWith marks read', () {
    final n = AppNotification.fromJson(notificationJson);
    expect(n.isRead, isFalse);
    expect(n.referenceId, 11);
    final read = n.copyWith(isRead: true);
    expect(read.isRead, isTrue);
    expect(read.title, n.title);
  });
}
