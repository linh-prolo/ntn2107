import 'dart:convert';
import 'dart:math';

import 'package:shared_preferences/shared_preferences.dart';

import '../config/constants.dart';
import '../models/user.dart';

/// Lưu token, thông tin người dùng và mã thiết bị.
///
/// Trên web dữ liệu nằm trong localStorage nên phiên đăng nhập vẫn còn
/// sau khi tải lại trang.
class StorageService {
  StorageService(this._prefs);

  final SharedPreferences _prefs;

  static Future<StorageService> create() async =>
      StorageService(await SharedPreferences.getInstance());

  String? get token => _prefs.getString(StorageKeys.token);

  User? get user {
    final raw = _prefs.getString(StorageKeys.user);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        return User.fromJson(Map<String, dynamic>.from(decoded));
      }
    } catch (_) {
      // Dữ liệu hỏng → bỏ qua.
    }
    return null;
  }

  Future<void> saveSession(String token, User user) async {
    await _prefs.setString(StorageKeys.token, token);
    await saveUser(user);
  }

  Future<void> saveUser(User user) =>
      _prefs.setString(StorageKeys.user, jsonEncode(user.toJson()));

  Future<void> clearSession() async {
    await _prefs.remove(StorageKeys.token);
    await _prefs.remove(StorageKeys.user);
  }

  /// Mã thiết bị ngẫu nhiên (64 ký tự hex), tạo một lần và giữ nguyên
  /// để máy chủ phát hiện nhiều người chấm công trên cùng một thiết bị.
  Future<String> deviceId() async {
    final existing = _prefs.getString(StorageKeys.deviceId);
    if (existing != null && RegExp(r'^[0-9a-f]{64}$').hasMatch(existing)) {
      return existing;
    }
    final rnd = Random.secure();
    final id = List.generate(
      32,
      (_) => rnd.nextInt(256),
    ).map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    await _prefs.setString(StorageKeys.deviceId, id);
    return id;
  }
}
