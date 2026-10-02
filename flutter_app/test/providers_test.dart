import 'package:flutter_test/flutter_test.dart';
import 'package:ntn_employee/models/leave_request.dart';
import 'package:ntn_employee/models/ot_request.dart';
import 'package:ntn_employee/providers/attendance_provider.dart';
import 'package:ntn_employee/providers/leave_provider.dart';
import 'package:ntn_employee/providers/notification_provider.dart';
import 'package:ntn_employee/providers/ot_provider.dart';
import 'package:ntn_employee/providers/payslip_provider.dart';
import 'package:ntn_employee/services/api_service.dart';
import 'package:ntn_employee/services/notification_service.dart';

import 'mock_backend.dart';

ApiService _api(MockBackend backend) =>
    ApiService(baseUrl: testBaseUrl, client: backend.client)
      ..setToken(testToken);

void main() {
  test(
    'AttendanceProvider loads status and follows the server clock',
    () async {
      final p = AttendanceProvider(_api(MockBackend()));
      await p.load();
      expect(p.error, isNull);
      expect(p.status!.canCheckOut, isTrue);
      final diff = p.now().difference(DateTime(2026, 10, 2, 16, 11, 34));
      expect(diff.inSeconds.abs(), lessThan(5));
    },
  );

  test('AttendanceProvider exposes API errors', () async {
    final api = ApiService(baseUrl: testBaseUrl, client: MockBackend().client)
      ..setToken('invalid');
    final p = AttendanceProvider(api);
    await p.load();
    expect(p.status, isNull);
    expect(p.error, isNotNull);
  });

  test('OtProvider validates before sending', () async {
    final backend = MockBackend();
    final p = OtProvider(_api(backend));
    final input = OtRequestInput(
      otDate: DateTime.now().subtract(const Duration(days: 3)),
      otType: 'weekday',
      startTime: '17:30',
      hours: 2,
      reason: 'x',
    );
    await expectLater(p.create(input), throwsA(isA<ApiException>()));
    expect(backend.requests.where((r) => r.method == 'POST'), isEmpty);
    expect(p.isSubmitting, isFalse);
  });

  test('OtProvider and LeaveProvider load history', () async {
    final backend = MockBackend();
    final ot = OtProvider(_api(backend));
    await ot.load();
    expect(ot.items.single.reason, 'Hoàn thành báo cáo');
    expect(ot.approvedHours, 2);

    final leave = LeaveProvider(_api(backend));
    await leave.load();
    expect(leave.items.single.status, 'rejected');
    await expectLater(
      leave.create(
        LeaveRequestInput(
          leaveType: 'annual',
          startDate: DateTime(2026, 10, 6),
          endDate: DateTime(2026, 10, 8),
          reason: 'Nghỉ',
        ),
      ),
      throwsA(isA<ApiException>()),
    );
  });

  test('PayslipProvider selects the latest payslip and loads detail', () async {
    final p = PayslipProvider(_api(MockBackend()));
    await p.load();
    expect(p.selectedId, 70);
    expect(p.selected!.netSalary, 8240000);
  });

  test('NotificationProvider marks notifications as read', () async {
    final backend = MockBackend();
    final p = NotificationProvider(NotificationService(_api(backend)));
    await p.load();
    expect(p.unreadCount, 1);
    await p.markRead(p.items.single);
    expect(p.items.single.isRead, isTrue);
    expect(p.unreadCount, 0);
    expect(
      backend.requests.last.url.path,
      '/erp/api/mobile/notifications/3/read',
    );
    p.dispose();
  });
}
