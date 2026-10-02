import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:ntn_employee/config/constants.dart';
import 'package:ntn_employee/providers/auth_provider.dart';
import 'package:ntn_employee/services/api_service.dart';
import 'package:ntn_employee/services/auth_service.dart';
import 'package:ntn_employee/services/storage_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'fixtures.dart';
import 'mock_backend.dart';

Future<(AuthProvider, ApiService, SharedPreferences)> _setup([
  Map<String, Object> initial = const {},
]) async {
  SharedPreferences.setMockInitialValues(initial);
  final prefs = await SharedPreferences.getInstance();
  final api = ApiService(baseUrl: testBaseUrl, client: MockBackend().client);
  final auth = AuthProvider(AuthService(api, StorageService(prefs)), api);
  return (auth, api, prefs);
}

void main() {
  test('starts unauthenticated without saved session', () async {
    final (auth, _, _) = await _setup();
    await auth.init();
    expect(auth.status, AuthStatus.unauthenticated);
  });

  test('login stores token and user, logout clears them', () async {
    final (auth, api, prefs) = await _setup();
    await auth.init();

    expect(await auth.login('NVTEST', 'wrong'), isFalse);
    expect(auth.error, 'Sai tài khoản hoặc mật khẩu.');
    expect(auth.isAuthenticated, isFalse);

    expect(await auth.login(' NVTEST ', 'Test@123'), isTrue);
    expect(auth.isAuthenticated, isTrue);
    expect(auth.user!.fullName, 'Nguyễn Văn Test');
    expect(api.token, testToken);
    expect(prefs.getString(StorageKeys.token), testToken);

    await auth.logout();
    expect(auth.status, AuthStatus.unauthenticated);
    expect(api.token, isNull);
    expect(prefs.getString(StorageKeys.token), isNull);
    expect(prefs.getString(StorageKeys.user), isNull);
  });

  test('restores a saved session (persists across reloads)', () async {
    final (auth, api, _) = await _setup({
      StorageKeys.token: testToken,
      StorageKeys.user: jsonEncode(userJson),
    });
    await auth.init();
    expect(auth.isAuthenticated, isTrue);
    expect(auth.user!.employeeCode, 'NVTEST');
    expect(api.token, testToken);
  });

  test('expired token logs the user out with a notice', () async {
    final (auth, _, prefs) = await _setup({
      StorageKeys.token: 'expired',
      StorageKeys.user: jsonEncode(userJson),
    });
    await auth.init();
    expect(auth.status, AuthStatus.unauthenticated);
    expect(auth.takeNotice(), contains('hết hạn'));
    expect(auth.takeNotice(), isNull);
    expect(prefs.getString(StorageKeys.token), isNull);
  });

  test('device id is stable 64-char hex', () async {
    SharedPreferences.setMockInitialValues({});
    final storage = StorageService(await SharedPreferences.getInstance());
    final id = await storage.deviceId();
    expect(id, matches(RegExp(r'^[0-9a-f]{64}$')));
    expect(await storage.deviceId(), id);
  });
}
