import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../api/api_exception.dart';
import '../config/app_config.dart';
import '../models/user.dart';
import 'session.dart';

class AuthResult {
  const AuthResult({required this.session, required this.user});
  final Session session;
  final User user;
}

/// Talks to `/api/v1/auth/*`. Uses a bare [http.Client] (not [ApiClient]) so the
/// token-refresh path can't recurse into itself.
///
/// No security logic lives here — the server decides everything. This just moves
/// tokens and identity across the wire and hands them to the secure store.
class AuthRepository {
  AuthRepository(this._http);

  final http.Client _http;

  Future<AuthResult> login(String email, String password) async {
    final data = await _post('/auth/login', {
      'email': email,
      'password': password,
    });
    return AuthResult(
      session: _sessionFrom(data),
      user: User.fromJson(data['user'] as Map<String, dynamic>? ?? const {}),
    );
  }

  Future<Session> refresh(String refreshToken) async {
    final data = await _post('/auth/refresh', {'refresh_token': refreshToken});
    return _sessionFrom(data);
  }

  Future<void> logout(String accessToken) async {
    try {
      await _http
          .post(
            AppConfig.endpoint('/auth/logout'),
            headers: {'Authorization': 'Bearer $accessToken'},
          )
          .timeout(AppConfig.requestTimeout);
    } catch (_) {
      // Local sign-out proceeds regardless — the token is discarded either way.
    }
  }

  Future<User> me(String accessToken) async {
    http.Response res;
    try {
      res = await _http.get(
        AppConfig.endpoint('/auth/me'),
        headers: {
          'Accept': 'application/json',
          'Authorization': 'Bearer $accessToken',
        },
      ).timeout(AppConfig.requestTimeout);
    } catch (_) {
      throw ApiException.network();
    }
    final body = _json(res);
    if (res.statusCode ~/ 100 != 2) throw ApiException.fromBody(res.statusCode, body);
    return User.fromJson(body?['data'] as Map<String, dynamic>? ?? const {});
  }

  // ── internals ──

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    http.Response res;
    try {
      res = await _http
          .post(
            AppConfig.endpoint(path),
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
            body: jsonEncode(body),
          )
          .timeout(AppConfig.requestTimeout);
    } catch (_) {
      throw ApiException.network();
    }

    final decoded = _json(res);
    if (res.statusCode ~/ 100 != 2) {
      throw ApiException.fromBody(res.statusCode, decoded);
    }
    final data = decoded?['data'];
    return data is Map<String, dynamic> ? data : const {};
  }

  Map<String, dynamic>? _json(http.Response res) {
    if (res.body.isEmpty) return null;
    try {
      final d = jsonDecode(res.body);
      return d is Map<String, dynamic> ? d : null;
    } catch (_) {
      return null;
    }
  }

  Session _sessionFrom(Map<String, dynamic> data) {
    final expiresRaw = data['expires_at'] as String?;
    return Session(
      accessToken: data['access_token'] as String? ?? '',
      refreshToken: data['refresh_token'] as String? ?? '',
      expiresAt: DateTime.tryParse(expiresRaw ?? '') ??
          DateTime.now().add(const Duration(minutes: 30)),
    );
  }
}
