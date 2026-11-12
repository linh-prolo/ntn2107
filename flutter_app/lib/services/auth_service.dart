import 'package:flutter/foundation.dart';

import '../config/api_config.dart';
import '../models/user.dart';
import 'api_service.dart';
import 'storage_service.dart';

/// Đăng nhập / đăng xuất / khôi phục phiên.
class AuthService {
  AuthService(this._api, this._storage);

  final ApiService _api;
  final StorageService _storage;

  /// Khôi phục phiên đã lưu (nếu có). Trả về user lưu tạm để hiển thị ngay.
  User? restoreSession() {
    final token = _storage.token;
    final user = _storage.user;
    if (token == null || token.isEmpty || user == null) {
      _api.setToken(null);
      return null;
    }
    _api.setToken(token);
    return user;
  }

  Future<User> login(String username, String password) async {
    final res = await _api.post(
      ApiConfig.login,
      body: {
        'username': username.trim(),
        'password': password,
        'device_name': _deviceName(),
      },
    );
    final data = res.map;
    final token = data['token']?.toString() ?? '';
    final userJson = data['user'];
    if (token.isEmpty || userJson is! Map) {
      throw const ApiException('Phản hồi đăng nhập không hợp lệ.');
    }
    final user = User.fromJson(Map<String, dynamic>.from(userJson));
    _api.setToken(token);
    await _storage.saveSession(token, user);
    return user;
  }

  Future<User> fetchProfile() async {
    final res = await _api.get(ApiConfig.me);
    final user = User.fromJson(res.map);
    await _storage.saveUser(user);
    return user;
  }

  Future<void> logout() async {
    if (_api.hasToken) {
      try {
        await _api.post(ApiConfig.logout);
      } catch (_) {
        // Vẫn đăng xuất cục bộ kể cả khi máy chủ không phản hồi.
      }
    }
    await clearLocalSession();
  }

  Future<void> clearLocalSession() async {
    _api.setToken(null);
    await _storage.clearSession();
  }

  static String _deviceName() {
    if (kIsWeb) return 'Flutter Web';
    return 'Flutter ${defaultTargetPlatform.name}';
  }
}
