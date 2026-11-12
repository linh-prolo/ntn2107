import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ntn_employee/config/constants.dart';
import 'package:ntn_employee/main.dart';
import 'package:ntn_employee/screens/account_screen.dart';
import 'package:ntn_employee/screens/leave_screen.dart';
import 'package:ntn_employee/screens/notifications_screen.dart';
import 'package:ntn_employee/screens/ot_screen.dart';
import 'package:ntn_employee/services/api_service.dart';
import 'package:ntn_employee/services/storage_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'mock_backend.dart';

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

Finder _scrollableIn(Type screen) => find
    .descendant(of: find.byType(screen), matching: find.byType(Scrollable))
    .first;

Finder _nav(String label) =>
    find.descendant(of: find.byType(NavigationBar), matching: find.text(label));

void main() {
  testWidgets(
    'login → attendance → OT → leave → payslip → notifications → logout',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final backend = MockBackend();
      final api = ApiService(baseUrl: testBaseUrl, client: backend.client);

      await tester.pumpWidget(NtnApp(storage: StorageService(prefs), api: api));
      await _settle(tester);

      // Đăng nhập: thiếu thông tin → sai mật khẩu → thành công.
      await tester.tap(find.byKey(const Key('login_submit')));
      await _settle(tester);
      expect(find.text('Vui lòng nhập tài khoản và mật khẩu.'), findsOneWidget);

      await tester.enterText(find.byKey(const Key('login_username')), 'NVTEST');
      await tester.enterText(find.byKey(const Key('login_password')), 'bad');
      await tester.tap(find.byKey(const Key('login_submit')));
      await _settle(tester);
      expect(find.text('Sai tài khoản hoặc mật khẩu.'), findsOneWidget);

      await tester.enterText(
        find.byKey(const Key('login_password')),
        'Test@123',
      );
      await tester.tap(find.byKey(const Key('login_submit')));
      await _settle(tester);
      expect(prefs.getString(StorageKeys.token), testToken);

      // Chấm công: đã vào ca 08:05 (muộn 5 phút) → hiện nút chấm công ra.
      expect(find.byKey(const Key('check_out_button')), findsOneWidget);
      expect(find.text('08:05'), findsWidgets);
      expect(find.text('Muộn 5 phút'), findsOneWidget);

      // OT
      await tester.tap(_nav('OT'));
      await _settle(tester);
      await tester.scrollUntilVisible(
        find.text('Hoàn thành báo cáo'),
        200,
        scrollable: _scrollableIn(OtScreen),
      );
      expect(find.text('Hoàn thành báo cáo'), findsOneWidget);

      // Xin phép
      await tester.tap(_nav('Xin phép'));
      await _settle(tester);
      await tester.scrollUntilVisible(
        find.text('Lý do từ chối: Thiếu giấy tờ'),
        200,
        scrollable: _scrollableIn(LeaveScreen),
      );
      expect(find.text('Lý do từ chối: Thiếu giấy tờ'), findsOneWidget);

      // Lương
      await tester.tap(_nav('Lương'));
      await _settle(tester);
      expect(
        tester.widget<Text>(find.byKey(const Key('payslip_net'))).data,
        '8.240.000 ₫',
      );

      // Thông báo
      await tester.tap(find.byKey(const Key('open_notifications')));
      await _settle(tester);
      expect(find.text('Đơn OT đã được duyệt'), findsOneWidget);
      await tester.tap(find.text('Đơn OT đã được duyệt'));
      await _settle(tester);
      expect(
        backend.requests.any(
          (r) =>
              r.method == 'PATCH' &&
              r.url.path.endsWith('/notifications/3/read'),
        ),
        isTrue,
      );
      Navigator.of(tester.element(find.byType(NotificationsScreen))).pop();
      await _settle(tester);

      // Tài khoản → đăng xuất
      await tester.tap(_nav('Tôi'));
      await _settle(tester);
      expect(find.byKey(const Key('account_name')), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('logout_button')),
        200,
        scrollable: _scrollableIn(AccountScreen),
      );
      await tester.tap(find.byKey(const Key('logout_button')));
      await _settle(tester);
      await tester.tap(find.widgetWithText(TextButton, 'Đăng xuất'));
      await _settle(tester);

      expect(find.byKey(const Key('login_submit')), findsOneWidget);
      expect(prefs.getString(StorageKeys.token), isNull);
      expect(
        backend.requests.any((r) => r.url.path.endsWith('/logout')),
        isTrue,
      );

      // Giải phóng timer (đồng hồ, polling thông báo).
      await tester.pumpWidget(const SizedBox());
    },
  );
}
