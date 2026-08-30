/// Compile-time configuration.
///
/// Values are injected with `--dart-define` and are NEVER secrets — the only
/// thing configured here is where the (authoritative) backend lives.
///
///   flutter run --dart-define=QRIVO_API_BASE_URL=https://api.qrivo.example
class AppConfig {
  const AppConfig._();

  /// Base URL of the QRIVO REST API (no trailing slash).
  ///
  /// Default targets the Android emulator's host loopback (`10.0.2.2`).
  static const String apiBaseUrl = String.fromEnvironment(
    'QRIVO_API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

  static const String apiPrefix = '/api/v1';

  /// Refresh the access token this many seconds before it actually expires.
  static const int tokenRefreshLeewaySeconds = 60;

  /// Network timeout for a single request.
  static const Duration requestTimeout = Duration(seconds: 20);

  static Uri endpoint(String path, [Map<String, dynamic>? query]) {
    final normalized = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$apiBaseUrl$apiPrefix$normalized').replace(
      queryParameters: query?.map((k, v) => MapEntry(k, '$v')),
    );
  }
}
