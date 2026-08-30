import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import 'api_exception.dart';
import 'api_response.dart';

/// Supplies the current bearer token (or null when signed out).
typedef TokenProvider = FutureOr<String?> Function();

/// Invoked when the server rejects the token (401) so the auth layer can try a
/// one-shot refresh. Returns the fresh token, or null if the session is dead.
typedef UnauthorizedHandler = Future<String?> Function();

/// Thin REST client for the QRIVO API.
///
/// Responsibilities (presentation-layer only — NO security decisions):
/// - build URLs under `/api/v1`
/// - attach `Authorization: Bearer <token>`
/// - unwrap the `{ success, data, meta }` envelope
/// - on 401, ask the auth layer to refresh once, then retry
/// - map everything else to [ApiException]
class ApiClient {
  ApiClient({
    required http.Client httpClient,
    required TokenProvider tokenProvider,
    UnauthorizedHandler? onUnauthorized,
  })  : _http = httpClient,
        _tokenProvider = tokenProvider,
        _onUnauthorized = onUnauthorized;

  final http.Client _http;
  final TokenProvider _tokenProvider;
  final UnauthorizedHandler? _onUnauthorized;

  Future<ApiResponse> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<ApiResponse> post(String path, {Object? body}) =>
      _send('POST', path, body: body);

  Future<ApiResponse> patch(String path, {Object? body}) =>
      _send('PATCH', path, body: body);

  Future<ApiResponse> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Object? body,
    bool isRetry = false,
  }) async {
    final uri = AppConfig.endpoint(path, query);
    final token = await _tokenProvider();

    final headers = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };

    http.Response response;
    try {
      final request = http.Request(method, uri)..headers.addAll(headers);
      if (body != null) request.body = jsonEncode(body);
      final streamed = await _http.send(request).timeout(AppConfig.requestTimeout);
      response = await http.Response.fromStream(streamed);
    } on TimeoutException {
      throw ApiException.network();
    } catch (_) {
      throw ApiException.network();
    }

    if (response.statusCode == 401 && !isRetry && _onUnauthorized != null) {
      final refreshed = await _onUnauthorized();
      if (refreshed != null) {
        return _send(method, path, query: query, body: body, isRetry: true);
      }
    }

    return _decode(response);
  }

  ApiResponse _decode(http.Response response) {
    Map<String, dynamic>? body;
    if (response.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map<String, dynamic>) body = decoded;
      } catch (_) {
        // non-JSON body — treated as an opaque error below
      }
    }

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return ApiResponse(
        data: body?['data'],
        meta: body?['meta'] as Map<String, dynamic>?,
        message: body?['message'] as String?,
      );
    }

    throw ApiException.fromBody(response.statusCode, body);
  }
}
