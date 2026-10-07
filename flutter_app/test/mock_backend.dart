import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'fixtures.dart';

const testBaseUrl = 'https://test.local/erp';
const testToken =
    'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

http.Response jsonResponse(Object body, [int status = 200]) =>
    http.Response.bytes(
      utf8.encode(jsonEncode(body)),
      status,
      headers: {'content-type': 'application/json; charset=utf-8'},
    );

/// Backend giả lập các endpoint /api/mobile/* dùng cho test.
class MockBackend {
  final List<http.Request> requests = [];

  late final MockClient client = MockClient((req) async {
    requests.add(req);
    final path = req.url.path.replaceFirst('/erp/api/mobile', '');
    final authed = req.headers['Authorization']?.endsWith(testToken) ?? false;

    if (path == '/login' && req.method == 'POST') {
      final body = jsonDecode(req.body) as Map<String, dynamic>;
      if (body['username'] == 'NVTEST' && body['password'] == 'Test@123') {
        return jsonResponse({
          'ok': true,
          'msg': 'Đăng nhập thành công.',
          'data': {
            'token': testToken,
            'expires_at': '2026-11-01 00:00:00',
            'user': userJson,
          },
        });
      }
      return jsonResponse({
        'ok': false,
        'msg': 'Sai tài khoản hoặc mật khẩu.',
      }, 401);
    }

    if (!authed) {
      return jsonResponse({'ok': false, 'msg': 'Chưa đăng nhập.'}, 401);
    }

    switch ((req.method, path)) {
      case ('POST', '/logout'):
        return jsonResponse({'ok': true, 'data': null, 'msg': 'Đã đăng xuất.'});
      case ('GET', '/me'):
        return jsonResponse({'ok': true, 'data': userJson});
      case ('GET', '/attendance'):
        return jsonResponse({'ok': true, 'data': attendanceJson});
      case ('GET', '/ot'):
        return jsonResponse({
          'ok': true,
          'data': {
            'month': 10,
            'year': 2026,
            'items': [otJson],
          },
        });
      case ('GET', '/leave'):
        return jsonResponse({
          'ok': true,
          'data': {
            'items': [leaveJson],
          },
        });
      case ('GET', '/payslip'):
        return jsonResponse({
          'ok': true,
          'data': {
            'items': [
              {
                'id': 70,
                'period_id': 5,
                'period_month': 9,
                'period_year': 2026,
                'period_from': '2026-08-26',
                'period_to': '2026-09-25',
                'net_salary': 8240000,
                'bank_transfer': 8240000,
              },
            ],
          },
        });
      case ('GET', '/payslip/70'):
        return jsonResponse({'ok': true, 'data': payslipJson});
      case ('GET', '/notifications'):
        return jsonResponse({
          'ok': true,
          'data': {
            'items': [notificationJson],
            'unread_count': 1,
          },
        });
      case ('PATCH', '/notifications/3/read'):
        return jsonResponse({
          'ok': true,
          'data': {'id': 3, 'is_read': true},
        });
    }
    return jsonResponse({'ok': false, 'msg': 'Không tìm thấy.'}, 404);
  });
}
