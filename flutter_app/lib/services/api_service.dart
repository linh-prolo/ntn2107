import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';

/// Lỗi khi gọi API (đã có thông báo tiếng Việt để hiển thị cho người dùng).
class ApiException implements Exception {
  final String message;
  final int? statusCode;
  final List<String> errors;

  const ApiException(this.message, {this.statusCode, this.errors = const []});

  bool get isUnauthorized => statusCode == 401;

  @override
  String toString() => message;
}

/// Phản hồi thành công: `{ ok: true, data: ..., msg?: ... }`.
class ApiResponse {
  final dynamic data;
  final String? message;

  const ApiResponse(this.data, [this.message]);

  Map<String, dynamic> get map => data is Map
      ? Map<String, dynamic>.from(data as Map)
      : <String, dynamic>{};
}

/// HTTP client cho /erp/api/mobile/* – tự gắn token, xử lý lỗi & timeout.
class ApiService {
  ApiService({http.Client? client, this._baseUrl})
    : _client = client ?? http.Client();

  final http.Client _client;
  final String? _baseUrl;
  String? _token;

  /// Được gọi khi máy chủ trả 401 cho request có token (token hết hạn/bị thu hồi).
  void Function()? onUnauthorized;

  String? get token => _token;
  bool get hasToken => _token != null && _token!.isNotEmpty;

  void setToken(String? token) => _token = token;

  Uri buildUri(String path, [Map<String, String>? query]) {
    if (_baseUrl == null) return ApiConfig.uri(path, query);
    final base = _baseUrl.endsWith('/')
        ? _baseUrl.substring(0, _baseUrl.length - 1)
        : _baseUrl;
    final uri = Uri.parse('$base$path');
    return (query == null || query.isEmpty)
        ? uri
        : uri.replace(queryParameters: query);
  }

  Future<ApiResponse> get(String path, {Map<String, String>? query}) =>
      _send('GET', path, query: query);

  Future<ApiResponse> post(
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) => _send('POST', path, body: body, query: query);

  Future<ApiResponse> patch(String path, {Map<String, dynamic>? body}) =>
      _send('PATCH', path, body: body);

  Future<ApiResponse> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final request = http.Request(method, buildUri(path, query));
    request.headers['Accept'] = 'application/json';
    if (hasToken) {
      request.headers['Authorization'] = _authorizationValue(_token!);
    }
    if (body != null) {
      request.headers['Content-Type'] = 'application/json; charset=utf-8';
      request.body = jsonEncode(body);
    }

    final sentWithToken = hasToken;
    http.Response response;
    try {
      final streamed = await _client.send(request).timeout(ApiConfig.timeout);
      response = await http.Response.fromStream(
        streamed,
      ).timeout(ApiConfig.timeout);
    } on TimeoutException {
      throw const ApiException('Máy chủ phản hồi quá lâu. Vui lòng thử lại.');
    } catch (_) {
      throw const ApiException(
        'Không thể kết nối tới máy chủ. Vui lòng kiểm tra kết nối mạng.',
      );
    }

    final status = response.statusCode;
    Map<String, dynamic>? json;
    try {
      final decoded = jsonDecode(utf8.decode(response.bodyBytes));
      if (decoded is Map) json = Map<String, dynamic>.from(decoded);
    } catch (_) {
      json = null;
    }

    if (status == 401 && sentWithToken) {
      onUnauthorized?.call();
    }

    if (json == null) {
      throw ApiException(
        'Phản hồi không hợp lệ từ máy chủ (HTTP $status).',
        statusCode: status,
      );
    }

    if (json['ok'] != true || status >= 400) {
      final errors = json['errors'] is List
          ? (json['errors'] as List).map((e) => e.toString()).toList()
          : const <String>[];
      final msg = json['msg']?.toString();
      throw ApiException(
        (msg == null || msg.isEmpty) ? 'Đã có lỗi xảy ra (HTTP $status).' : msg,
        statusCode: status,
        errors: errors,
      );
    }

    return ApiResponse(json['data'], json['msg']?.toString());
  }

  static String _authorizationValue(String token) => 'Bearer $token';

  void dispose() => _client.close();
}
