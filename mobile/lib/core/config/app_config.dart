/// Compile-time configuration.
///
/// Values are injected with `--dart-define` and are NEVER secrets — the only
/// thing configured here is where the (authoritative) backend lives.
///
///   flutter run --dart-define=API_BASE_URL=https://api.qrivo.example
class AppConfig {
  const AppConfig._();

  /// Base URL of the QRIVO REST API (no trailing slash).
  ///
  /// Accepts either `API_BASE_URL` or the older, namespaced
  /// `QRIVO_API_BASE_URL`; if both are passed the namespaced one wins, so an
  /// existing build script cannot be silently overridden by the shorter name.
  ///
  /// Defaults to the Android emulator's route to the host (`10.0.2.2`). Other
  /// targets — see `mobile/README.md`:
  ///
  /// | Target           | Value                    |
  /// | ---------------- | ------------------------ |
  /// | Android emulator | `http://10.0.2.2:8000`   |
  /// | iOS simulator    | `http://127.0.0.1:8000`  |
  /// | Physical device  | `http://<LAN-IP>:8000`   |
  ///
  /// `bool.hasEnvironment` is used rather than checking for an empty string
  /// because only it is const-evaluable, and this must stay a compile-time
  /// constant.
  static const String apiBaseUrl = bool.hasEnvironment('QRIVO_API_BASE_URL')
      ? String.fromEnvironment('QRIVO_API_BASE_URL')
      : String.fromEnvironment(
          'API_BASE_URL',
          defaultValue: 'http://10.0.2.2:8000',
        );

  /// Where to LEARN the API address from, compiled in at build time.
  ///
  /// The tunnel URL changes on every laptop restart, so it is deliberately NOT
  /// baked into the app. This is: a fixed, publicly readable document that
  /// never changes, carrying an address and nothing else. See
  /// `endpoint_config.dart` for the security posture and the host pin.
  ///
  ///   flutter build apk --dart-define=CONFIG_URL=https://raw.githubusercontent.com/...
  static const String configUrl = String.fromEnvironment(
    'CONFIG_URL',
    defaultValue: '',
  );

  /// True when this build learns its address at runtime rather than using the
  /// compile-time [apiBaseUrl].
  static bool get usesRuntimeConfig => configUrl.isNotEmpty;

  /// The address learned at runtime, once [EndpointResolver] has resolved one.
  static String? _runtimeBaseUrl;

  /// Adopt an address. Only [EndpointResolver] calls this, and only with a
  /// value that has already passed `EndpointConfig.isAllowedApiBase`.
  static void setRuntimeBaseUrl(String value) {
    _runtimeBaseUrl = value.endsWith('/') ? value.substring(0, value.length - 1) : value;
  }

  /// Forget the learned address (used by tests).
  static void resetRuntimeBaseUrl() => _runtimeBaseUrl = null;

  /// The address requests actually go to: the runtime one when we have it,
  /// otherwise the compile-time default.
  static String get effectiveBaseUrl => _runtimeBaseUrl ?? apiBaseUrl;

  static const String apiPrefix = '/api/v1';

  /// Refresh the access token this many seconds before it actually expires.
  static const int tokenRefreshLeewaySeconds = 60;

  /// Network timeout for a single request.
  static const Duration requestTimeout = Duration(seconds: 20);

  static Uri endpoint(String path, [Map<String, dynamic>? query]) {
    final normalized = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$effectiveBaseUrl$apiPrefix$normalized').replace(
      queryParameters: query?.map((k, v) => MapEntry(k, '$v')),
    );
  }
}
