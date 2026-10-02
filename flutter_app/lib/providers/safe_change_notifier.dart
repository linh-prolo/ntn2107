import 'package:flutter/foundation.dart';

/// ChangeNotifier bỏ qua notifyListeners() sau khi đã dispose
/// (ví dụ: request vẫn đang chạy khi người dùng đăng xuất).
class SafeChangeNotifier extends ChangeNotifier {
  bool _disposed = false;

  bool get isDisposed => _disposed;

  @override
  void notifyListeners() {
    if (!_disposed) super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }
}
