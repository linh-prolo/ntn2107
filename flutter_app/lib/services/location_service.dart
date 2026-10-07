import 'package:geolocator/geolocator.dart';

/// Lỗi lấy vị trí, kèm thông báo tiếng Việt.
class LocationException implements Exception {
  final String message;
  const LocationException(this.message);

  @override
  String toString() => message;
}

/// Lấy vị trí GPS hiện tại (web: dùng Geolocation API của trình duyệt).
class LocationService {
  const LocationService();

  Future<Position> currentPosition() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const LocationException(
        '📍 Định vị đang tắt. Vui lòng bật GPS/định vị rồi thử lại.',
      );
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      throw const LocationException(
        '📍 Bạn chưa cho phép truy cập vị trí. Vui lòng cấp quyền vị trí cho ứng dụng/trình duyệt.',
      );
    }
    try {
      return await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 20),
        ),
      );
    } catch (_) {
      throw const LocationException(
        '📍 Không lấy được vị trí. Vui lòng di chuyển ra nơi thoáng và thử lại.',
      );
    }
  }

  /// Khoảng cách (mét) giữa hai tọa độ.
  double distance(double lat1, double lng1, double lat2, double lng2) =>
      Geolocator.distanceBetween(lat1, lng1, lat2, lng2);
}
