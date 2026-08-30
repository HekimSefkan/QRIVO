/// A failed API call.
///
/// The backend is authoritative and deliberately returns generic messages for
/// security-sensitive failures — this class just carries them through. The app
/// never infers anything security-relevant from a status code.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.isNetworkError = false,
  });

  final String message;
  final int? statusCode;
  final bool isNetworkError;

  bool get isUnauthorized => statusCode == 401;
  bool get isForbidden => statusCode == 403;
  bool get isValidation => statusCode == 422;

  factory ApiException.network([Object? cause]) => ApiException(
        'Could not reach the server. Check your connection and try again.',
        isNetworkError: true,
      );

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
  String toString() => 'ApiException($statusCode): $message';
}
