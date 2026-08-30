import '../config/app_config.dart';

/// The tokens issued by `POST /api/v1/auth/login` (and rotated by `/refresh`).
///
/// This is the ONLY security material the app persists, and it lives only in the
/// platform secure store (Keychain / Keystore). It is never logged.
class Session {
  const Session({
    required this.accessToken,
    required this.refreshToken,
    required this.expiresAt,
  });

  final String accessToken;
  final String refreshToken;

  /// Server-provided absolute expiry of [accessToken].
  final DateTime expiresAt;

  bool get isAccessTokenExpired => DateTime.now().isAfter(expiresAt);

  /// True when the access token is expired or within the refresh leeway.
  bool get needsRefresh => DateTime.now().isAfter(
        expiresAt.subtract(
          Duration(seconds: AppConfig.tokenRefreshLeewaySeconds),
        ),
      );

  Map<String, dynamic> toJson() => {
        'access_token': accessToken,
        'refresh_token': refreshToken,
        'expires_at': expiresAt.toIso8601String(),
      };

  static Session? fromJson(Map<String, dynamic> json) {
    final access = json['access_token'] as String?;
    final refresh = json['refresh_token'] as String?;
    final expiresRaw = json['expires_at'] as String?;
    if (access == null || refresh == null || expiresRaw == null) return null;

    final expires = DateTime.tryParse(expiresRaw);
    if (expires == null) return null;

    return Session(
      accessToken: access,
      refreshToken: refresh,
      expiresAt: expires,
    );
  }
}
