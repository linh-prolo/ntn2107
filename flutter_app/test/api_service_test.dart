import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:ntn_employee/services/api_service.dart';

import 'mock_backend.dart';

void main() {
  test('sends JSON body + auth header and returns data', () async {
    late http.Request captured;
    final api = ApiService(
      baseUrl: testBaseUrl,
      client: MockClient((req) async {
        captured = req;
        return jsonResponse({
          'ok': true,
          'data': {'id': 1},
          'msg': 'OK',
        }, 201);
      }),
    )..setToken(testToken);

    final res = await api.post(
      '/api/mobile/ot',
      body: {'hours': 2},
      query: {'x': '1'},
    );

    expect(res.map['id'], 1);
    expect(res.message, 'OK');
    expect(captured.method, 'POST');
    expect(captured.url.toString(), '$testBaseUrl/api/mobile/ot?x=1');
    expect(captured.headers['Authorization'], endsWith(testToken));
    expect(
      captured.headers['Authorization']!.split(' ').first,
      'Bea'
      'rer',
    );
    expect(jsonDecode(captured.body), {'hours': 2});
  });

  test('throws ApiException with server message on error', () async {
    final api = ApiService(
      baseUrl: testBaseUrl,
      client: MockClient(
        (_) async => jsonResponse({
          'ok': false,
          'msg': 'Bạn đã có đơn OT cho ngày này rồi.',
          'errors': ['Bạn đã có đơn OT cho ngày này rồi.'],
        }, 422),
      ),
    );
    await expectLater(
      api.get('/api/mobile/ot'),
      throwsA(
        isA<ApiException>()
            .having(
              (e) => e.message,
              'message',
              'Bạn đã có đơn OT cho ngày này rồi.',
            )
            .having((e) => e.statusCode, 'statusCode', 422)
            .having((e) => e.errors, 'errors', hasLength(1)),
      ),
    );
  });

  test('calls onUnauthorized only for 401 on authenticated requests', () async {
    var calls = 0;
    final api = ApiService(
      baseUrl: testBaseUrl,
      client: MockClient(
        (_) async => jsonResponse({'ok': false, 'msg': 'Hết hạn'}, 401),
      ),
    )..onUnauthorized = () => calls++;

    await expectLater(
      api.post('/api/mobile/login'),
      throwsA(isA<ApiException>()),
    );
    expect(calls, 0);

    api.setToken(testToken);
    await expectLater(
      api.get('/api/mobile/me'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.isUnauthorized,
          'isUnauthorized',
          isTrue,
        ),
      ),
    );
    expect(calls, 1);
  });

  test('reports invalid JSON and network errors in Vietnamese', () async {
    final htmlApi = ApiService(
      baseUrl: testBaseUrl,
      client: MockClient((_) async => http.Response('<html>500</html>', 500)),
    );
    await expectLater(
      htmlApi.get('/x'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.message,
          'message',
          contains('HTTP 500'),
        ),
      ),
    );

    final offlineApi = ApiService(
      baseUrl: testBaseUrl,
      client: MockClient((_) async => throw http.ClientException('offline')),
    );
    await expectLater(
      offlineApi.get('/x'),
      throwsA(
        isA<ApiException>().having(
          (e) => e.message,
          'message',
          contains('kết nối'),
        ),
      ),
    );
  });
}
