import 'dart:convert';
import 'dart:typed_data';

/// Xác định định dạng ảnh theo "magic bytes" (jpeg/png/webp).
String? detectImageMime(Uint8List bytes) {
  if (bytes.length >= 3 &&
      bytes[0] == 0xFF &&
      bytes[1] == 0xD8 &&
      bytes[2] == 0xFF) {
    return 'image/jpeg';
  }
  if (bytes.length >= 8 &&
      bytes[0] == 0x89 &&
      bytes[1] == 0x50 &&
      bytes[2] == 0x4E &&
      bytes[3] == 0x47) {
    return 'image/png';
  }
  if (bytes.length >= 12 &&
      ascii.decode(bytes.sublist(0, 4), allowInvalid: true) == 'RIFF' &&
      ascii.decode(bytes.sublist(8, 12), allowInvalid: true) == 'WEBP') {
    return 'image/webp';
  }
  return null;
}

/// Chuyển ảnh thành data URL để gửi lên API; trả về null nếu không hỗ trợ.
String? imageDataUrl(Uint8List bytes) {
  final mime = detectImageMime(bytes);
  if (mime == null) return null;
  return 'data:$mime;base64,${base64Encode(bytes)}';
}
