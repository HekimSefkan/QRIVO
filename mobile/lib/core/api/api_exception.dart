/// How a request failed, for PRESENTATION ONLY.
///
/// This classification never changes what the app is allowed to do — the
/// backend remains the sole authority for every security decision (AD-012).
/// It exists so the student sees a message that matches reality instead of one
/// generic "Could not reach the server" for four different problems.
enum ApiFailureKind {
  /// The phone itself has no working connection (DNS/socket failed outright).
  offline,

  /// The request reached the network but no answer arrived in time.
  timeout,

  /// The connection failed for some other transport reason — a dropped
  /// keep-alive socket after the app was backgrounded is the common one.
  unreachable,

  /// The server said 401 and the refresh could not produce a usable session.
  /// The SERVER decided this; the app only relays it.
  sessionExpired,

  /// The server answered with an error status. `message` is the server's own.
  server,
}

/// A failed API call.
///
/// The backend is authoritative and deliberately returns generic messages for
/// security-sensitive failures — this class just carries them through. The app
/// never infers anything security-relevant from a status code.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.kind = ApiFailureKind.server,
  });

  final String message;
  final int? statusCode;
  final ApiFailureKind kind;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isValidation => statusCode == 422;

  /// True for anything that failed below the HTTP layer. Kept as a getter so
  /// existing call sites (and tests) that ask `isNetworkError` still work.
  bool get isNetworkError =>
      kind == ApiFailureKind.offline ||
      kind == ApiFailureKind.timeout ||
      kind == ApiFailureKind.unreachable;

  /// Whether retrying the same request unchanged could plausibly succeed.
  /// Only ever consulted for idempotent requests — see [ApiClient].
  bool get isRetryable =>
      kind == ApiFailureKind.timeout || kind == ApiFailureKind.unreachable;

  factory ApiException.offline() => ApiException(
        'No internet connection. Check your Wi-Fi or mobile data and try again.',
        kind: ApiFailureKind.offline,
      );

  factory ApiException.timeout() => ApiException(
        'The server took too long to respond. Please try again.',
        kind: ApiFailureKind.timeout,
      );

  factory ApiException.unreachable() => ApiException(
        'Could not reach the server. Please try again.',
        kind: ApiFailureKind.unreachable,
      );

  factory ApiException.sessionExpired() => ApiException(
        'Your session has expired. Please sign in again.',
        statusCode: 401,
        kind: ApiFailureKind.sessionExpired,
      );

  /// Retained for compatibility; prefer the specific factories above.
  factory ApiException.network([Object? cause]) => ApiException.unreachable();

  factory ApiException.fromBody(int statusCode, Map<String, dynamic>? body) {
    final message = (body?['message'] as String?)?.trim();
    return ApiException(
      message == null || message.isEmpty
          ? 'Something went wrong (HTTP $statusCode).'
          : message,
      statusCode: statusCode,
    );
  }

  @override
  String toString() => 'ApiException(${kind.name}/$statusCode): $message';
}
