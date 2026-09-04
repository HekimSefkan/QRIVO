import 'dart:async';
import 'dart:convert';
import 'dart:io' show SocketException, HandshakeException;

import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import 'api_exception.dart';
import 'api_response.dart';

/// Supplies the current bearer token (or null when signed out).
typedef TokenProvider = FutureOr<String?> Function();

/// Invoked when the server rejects the token (401) so the auth layer can try a
/// one-shot refresh. Returns the fresh token, or null if the session is dead.
typedef UnauthorizedHandler = Future<String?> Function();

/// Re-resolves the API address. Returns true when a DIFFERENT address was
/// learned, meaning the failed request is worth retrying.
typedef AddressRecoveryHandler = Future<bool> Function();

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

  /// Called when the API address itself appears dead (unreachable, as opposed
  /// to this phone being offline). Should re-read the published config and
  /// return true when it learned a DIFFERENT address, in which case the request
  /// is retried once against the new one.
  ///
  /// This is what makes the app heal itself after the laptop restarts and the
  /// tunnel gets a new hostname, with nobody rebuilding the APK.
  AddressRecoveryHandler? onAddressStale;

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
    String? bearerOverride,
  }) async {
    final uri = AppConfig.endpoint(path, query);
    // On a retry we use the token [_onUnauthorized] just handed back rather
    // than asking the provider again: the provider may still be serving the
    // token that was just rejected, which would make the retry a guaranteed
    // second 401 and sign the student out mid-scan.
    final token = bearerOverride ?? await _tokenProvider();

    final headers = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };

    http.Response response;
    try {
      response = await _sendWithRetries(method, uri, headers, body);
    } on ApiException catch (error) {
      // The address we have looks dead — not "this phone is offline", which no
      // amount of re-resolving would fix. Re-read the published config; if it
      // now names a DIFFERENT address, the laptop restarted and the tunnel
      // moved, so try once against the new one.
      //
      // Deliberately not attempted for a body-carrying method: recovering a
      // POST here would re-send it to a second host, and an attendance
      // submission must never be duplicated.
      final recoverable = error.kind == ApiFailureKind.unreachable &&
          !isRetry &&
          body == null &&
          onAddressStale != null;

      if (!recoverable) rethrow;

      final moved = await onAddressStale!();
      if (!moved) rethrow;

      response = await _sendWithRetries(
        method,
        AppConfig.endpoint(path, query), // recomputed against the new address
        headers,
        body,
      );
    }

    if (response.statusCode == 401 && !isRetry && _onUnauthorized != null) {
      final refreshed = await _onUnauthorized();
      if (refreshed != null) {
        return _send(
          method,
          path,
          query: query,
          body: body,
          isRetry: true,
          bearerOverride: refreshed,
        );
      }
      // The server rejected the token and the auth layer could not mint a new
      // one. The SERVER made that call; we only translate it into a message a
      // student can act on instead of a generic network error.
      throw ApiException.sessionExpired();
    }

    return _decode(response);
  }

  /// Issue the request, retrying ONLY when it is safe to do so.
  ///
  /// Safety rule: a retry is only attempted for **idempotent** methods (GET).
  /// A POST is never retried — re-sending an attendance submission or a
  /// challenge response could duplicate it, and the fact that the server also
  /// defends against duplicates is not a reason for the client to create them.
  ///
  /// This exists because the first request after the app returns from the
  /// background very often rides a keep-alive socket the OS has already torn
  /// down. That single failure is what surfaced as "Could not reach the
  /// server"; one quick retry makes it invisible.
  Future<http.Response> _sendWithRetries(
    String method,
    Uri uri,
    Map<String, String> headers,
    Object? body,
  ) async {
    final canRetry = method == 'GET';
    const backoff = <Duration>[
      Duration(milliseconds: 300),
      Duration(milliseconds: 900),
    ];

    var attempt = 0;
    while (true) {
      try {
        final request = http.Request(method, uri)..headers.addAll(headers);
        if (body != null) request.body = jsonEncode(body);
        final streamed =
            await _http.send(request).timeout(AppConfig.requestTimeout);
        return await http.Response.fromStream(streamed);
      } catch (error) {
        final failure = _classify(error);

        if (canRetry && failure.isRetryable && attempt < backoff.length) {
          await Future<void>.delayed(backoff[attempt]);
          attempt++;
          continue;
        }
        throw failure;
      }
    }
  }

  /// Map a transport-level error to a specific, honest failure.
  ///
  /// Presentation only — nothing here grants or denies anything.
  ApiException _classify(Object error) {
    if (error is TimeoutException) return ApiException.timeout();

    if (error is SocketException) {
      // No route / DNS failure means this phone has no working connection.
      final code = error.osError?.errorCode;
      final looksOffline = error.osError == null ||
          code == 7 || // no address associated with hostname
          code == 11001 || // WSAHOST_NOT_FOUND
          code == 101 || // network unreachable
          code == 110; // connection timed out at the OS
      return looksOffline
          ? ApiException.offline()
          : ApiException.unreachable();
    }

    if (error is http.ClientException) {
      // package:http's IOClient wraps SocketException in a ClientException and
      // drops the cause, so the message is the only signal left. A DNS failure
      // means this phone has no usable connection; anything else is most often
      // "Connection closed before full header was received" — a dead
      // keep-alive socket after resume, which is exactly what a retry fixes.
      final m = error.message.toLowerCase();
      final looksOffline = m.contains('failed host lookup') ||
          m.contains('no address associated with hostname') ||
          m.contains('network is unreachable') ||
          m.contains('nodename nor servname');
      return looksOffline
          ? ApiException.offline()
          : ApiException.unreachable();
    }

    if (error is HandshakeException) return ApiException.unreachable();

    return ApiException.unreachable();
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
