import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final int statusCode;
  final String code;
  final String message;

  const ApiException(this.statusCode, this.code, this.message);

  @override
  String toString() => '[$statusCode:$code] $message';
}

class ApiClient {
  static const _storage = FlutterSecureStorage();
  static const _keyBaseUrl = 'qa_base_url';
  static const _keyAccess = 'qa_access_token';
  static const _keyRefresh = 'qa_refresh_token';
  static const _keyLease = 'qa_offline_lease';
  static const _keyUserJson = 'qa_user_json';

  Future<String> getBaseUrl() async {
    return (await _storage.read(key: _keyBaseUrl)) ?? 'https://your-school-domain.org/qidus-asset/api/v1';
  }

  Future<void> setBaseUrl(String url) async {
    final clean = url.trim().replaceAll(RegExp(r'/+$'), '');
    await _storage.write(key: _keyBaseUrl, value: clean);
  }

  Future<String?> getAccessToken() => _storage.read(key: _keyAccess);
  Future<String?> getOfflineLease() => _storage.read(key: _keyLease);

  Future<Map<String, dynamic>?> getSavedUser() async {
    final raw = await _storage.read(key: _keyUserJson);
    if (raw == null || raw.isEmpty) return null;
    return jsonDecode(raw) as Map<String, dynamic>;
  }

  Future<void> clearSession() async {
    await _storage.delete(key: _keyAccess);
    await _storage.delete(key: _keyRefresh);
  }

  Future<Map<String, dynamic>> login({
    required String username,
    required String password,
    required String deviceUuid,
  }) async {
    final data = await request(
      'POST',
      '/auth/login',
      body: {'username': username, 'password': password, 'device_uuid': deviceUuid},
      authenticated: false,
    );
    await _storage.write(key: _keyAccess, value: data['access_token'] as String);
    await _storage.write(key: _keyRefresh, value: data['refresh_token'] as String);
    await _storage.write(key: _keyUserJson, value: jsonEncode(data['user']));
    return data;
  }

  Future<bool> tryRefresh() async {
    final refresh = await _storage.read(key: _keyRefresh);
    if (refresh == null || refresh.isEmpty) return false;
    try {
      final data = await request(
        'POST',
        '/auth/refresh',
        body: {'refresh_token': refresh},
        authenticated: false,
      );
      await _storage.write(key: _keyAccess, value: data['access_token'] as String);
      await _storage.write(key: _keyRefresh, value: data['refresh_token'] as String);
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<Map<String, dynamic>> enrollDevice({
    required String deviceUuid,
    required String deviceLabel,
    required String keyAlgorithm,
    required String publicKeyPem,
  }) async {
    return request('POST', '/devices/register', body: {
      'device_uuid': deviceUuid,
      'device_label': deviceLabel,
      'key_algorithm': keyAlgorithm,
      'public_key_pem': publicKeyPem,
      'biometric_bound': true,
    });
  }

  Future<Map<String, dynamic>> acquireOfflineLease(String deviceUuid) async {
    final res = await request('POST', '/offline-lease', body: {'device_uuid': deviceUuid});
    if (res['lease_token'] is String) {
      await _storage.write(key: _keyLease, value: res['lease_token'] as String);
    }
    return res;
  }

  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? idempotencyKey,
    String? deviceUuid,
    bool authenticated = true,
  }) async {
    final base = await getBaseUrl();
    final uri = Uri.parse('$base$path');
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    if (authenticated) {
      final token = await getAccessToken();
      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }
    if (idempotencyKey != null && idempotencyKey.isNotEmpty) {
      headers['Idempotency-Key'] = idempotencyKey;
    }
    if (deviceUuid != null && deviceUuid.isNotEmpty) {
      headers['X-Device-UUID'] = deviceUuid;
    }

    http.Response resp;
    final encoded = body != null ? jsonEncode(body) : null;
    switch (method.toUpperCase()) {
      case 'GET':
        resp = await http.get(uri, headers: headers).timeout(const Duration(seconds: 20));
        break;
      case 'POST':
        resp = await http.post(uri, headers: headers, body: encoded).timeout(const Duration(seconds: 25));
        break;
      case 'PATCH':
        resp = await http.patch(uri, headers: headers, body: encoded).timeout(const Duration(seconds: 25));
        break;
      default:
        throw ApiException(400, 'METHOD_UNSUPPORTED', 'Unsupported HTTP method $method');
    }

    if (resp.statusCode == 401 && authenticated && await tryRefresh()) {
      return request(method, path, body: body, idempotencyKey: idempotencyKey, deviceUuid: deviceUuid, authenticated: true);
    }

    final decoded = jsonDecode(resp.body) as Map<String, dynamic>;
    if (resp.statusCode >= 400 || decoded['status'] == 'error') {
      throw ApiException(
        resp.statusCode,
        (decoded['code'] ?? 'API_ERROR').toString(),
        (decoded['message'] ?? 'Request failed').toString(),
      );
    }

    if (decoded.containsKey('data') && decoded['data'] is Map<String, dynamic>) {
      return decoded['data'] as Map<String, dynamic>;
    }
    return decoded;
  }
}
