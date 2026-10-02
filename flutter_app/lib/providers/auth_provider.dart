import 'package:flutter/foundation.dart';

import '../models/user.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

/// Trạng thái đăng nhập của ứng dụng.
class AuthProvider extends ChangeNotifier {
  AuthProvider(this._authService, ApiService api) {
    api.onUnauthorized = _handleUnauthorized;
  }

  final AuthService _authService;

  AuthStatus _status = AuthStatus.unknown;
  User? _user;
  bool _loading = false;
  String? _error;
  String? _notice;

  AuthStatus get status => _status;
  User? get user => _user;
  bool get isLoading => _loading;
  bool get isAuthenticated => _status == AuthStatus.authenticated;
  String? get error => _error;

  /// Thông báo một lần (ví dụ: phiên hết hạn) để hiển thị ở màn hình đăng nhập.
  String? takeNotice() {
    final n = _notice;
    _notice = null;
    return n;
  }

  /// Khôi phục phiên đã lưu và làm mới hồ sơ từ máy chủ.
  Future<void> init() async {
    _user = _authService.restoreSession();
    _status = _user == null
        ? AuthStatus.unauthenticated
        : AuthStatus.authenticated;
    notifyListeners();
    if (_user != null) {
      await refreshProfile();
    }
  }

  Future<bool> login(String username, String password) async {
    if (username.trim().isEmpty || password.isEmpty) {
      _error = 'Vui lòng nhập tài khoản và mật khẩu.';
      notifyListeners();
      return false;
    }
    _loading = true;
    _error = null;
    notifyListeners();
    try {
      _user = await _authService.login(username, password);
      _status = AuthStatus.authenticated;
      return true;
    } on ApiException catch (e) {
      _error = e.message;
      return false;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> refreshProfile() async {
    try {
      final user = await _authService.fetchProfile();
      if (_status == AuthStatus.authenticated) {
        _user = user;
        notifyListeners();
      }
    } on ApiException {
      // 401 đã được xử lý qua onUnauthorized; lỗi mạng giữ dữ liệu đã lưu.
    }
  }

  Future<void> logout() async {
    await _authService.logout();
    _setLoggedOut();
  }

  void clearError() {
    if (_error != null) {
      _error = null;
      notifyListeners();
    }
  }

  void _handleUnauthorized() {
    if (_status != AuthStatus.authenticated) return;
    _notice = 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.';
    _authService.clearLocalSession();
    _setLoggedOut();
  }

  void _setLoggedOut() {
    _user = null;
    _status = AuthStatus.unauthenticated;
    _loading = false;
    notifyListeners();
  }
}
