import 'dart:convert';

/// Why resolving the API address failed.
///
/// These are deliberately distinct: "we could not reach the config", "the config
/// is not something we will trust", and "the address we have no longer answers"
/// are three different problems with three different fixes, and collapsing them
/// into one "could not reach the server" is what made the earlier failures
/// impossible to diagnose.
enum EndpointFailure {
  /// The config document itself could not be fetched (phone offline, GitHub
  /// unreachable, DNS failure).
  configUnreachable,

  /// The config was fetched but is not valid JSON, or is missing its address.
  configMalformed,

  /// The config was fetched and parsed, but the address it carries is not one
  /// we are willing to talk to. See [EndpointConfig.isAllowedApiBase].
  configRejected,
}

/// The published address of the QRIVO API.
///
/// SECURITY POSTURE — read before changing anything here.
///
/// This document carries an ADDRESS AND NOTHING ELSE. It never carries
/// credentials, tokens, feature flags or policy. The server remains the sole
/// authority for every security decision; learning where the server lives does
/// not grant the client any capability it did not already have.
///
/// It is fetched over HTTPS from a fixed, publicly readable URL that is baked
/// into the app at build time and never changes.
///
/// The address it advertises IS attacker-relevant: anyone who could rewrite the
/// config could point the app at a host they control and harvest the
/// credentials a student types into the login form. Two things bound that:
///
///  1. [isAllowedApiBase] pins the SHAPE of the address. Only `https://` and
///     only a `*.trycloudflare.com` hostname is accepted. An arbitrary host
///     cannot be injected, and plain `http://` is refused outright.
///  2. The config URL itself is compiled into the binary, so the app cannot be
///     redirected to a different config source at runtime.
///
/// This BOUNDS the blast radius; it does not eliminate it. Someone who both
/// controls the config source and stands up a `*.trycloudflare.com` host could
/// still impersonate the API. Stated plainly rather than glossed over: the real
/// mitigation is that the config source is a repository only its owner can push
/// to.
class EndpointConfig {
  const EndpointConfig({
    required this.apiBaseUrl,
    required this.generatedAt,
  });

  /// Base URL of the API, no trailing slash. Always `https://…trycloudflare.com`.
  final String apiBaseUrl;

  /// When the launcher published this address.
  final DateTime? generatedAt;

  /// A config older than this is reported as stale. It is still USABLE — a
  /// stale address is often still correct — but the app says so rather than
  /// silently trusting it, because the usual cause is that the laptop is off
  /// and the tunnel is therefore dead.
  static const Duration staleAfter = Duration(hours: 12);

  bool get isStale {
    final at = generatedAt;
    if (at == null) return false;
    return DateTime.now().toUtc().difference(at.toUtc()) > staleAfter;
  }

  /// How old this address is, or null when the publisher did not say.
  Duration? get age {
    final at = generatedAt;
    if (at == null) return null;
    return DateTime.now().toUtc().difference(at.toUtc());
  }

  /// The pin. Only an HTTPS Cloudflare quick-tunnel host is acceptable.
  ///
  /// Rejects, deliberately: plain http, a port, userinfo (`user:pass@host`),
  /// any other domain, and a bare host with no scheme.
  static bool isAllowedApiBase(String value) {
    final uri = Uri.tryParse(value);
    if (uri == null) return false;
    if (uri.scheme != 'https') return false;
    if (uri.hasPort) return false;
    if (uri.userInfo.isNotEmpty) return false;
    if (uri.host.isEmpty) return false;

    // A single label, then exactly trycloudflare.com. `evil.com/x.trycloudflare.com`
    // and `trycloudflare.com.evil.com` both fail this.
    return RegExp(r'^[a-z0-9][a-z0-9-]*\.trycloudflare\.com$').hasMatch(uri.host);
  }

  /// Parse and validate. Throws [EndpointConfigException] with a specific
  /// [EndpointFailure] rather than a generic error.
  static EndpointConfig parse(String body) {
    final Object? decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      throw const EndpointConfigException(EndpointFailure.configMalformed);
    }

    if (decoded is! Map<String, dynamic>) {
      throw const EndpointConfigException(EndpointFailure.configMalformed);
    }

    final raw = decoded['api_base_url'];
    if (raw is! String || raw.isEmpty) {
      throw const EndpointConfigException(EndpointFailure.configMalformed);
    }

    final normalized = raw.endsWith('/') ? raw.substring(0, raw.length - 1) : raw;

    if (!isAllowedApiBase(normalized)) {
      throw const EndpointConfigException(EndpointFailure.configRejected);
    }

    DateTime? generatedAt;
    final at = decoded['generated_at'];
    if (at is String) generatedAt = DateTime.tryParse(at);

    return EndpointConfig(apiBaseUrl: normalized, generatedAt: generatedAt);
  }

  String toJson() => jsonEncode({
        'api_base_url': apiBaseUrl,
        if (generatedAt != null) 'generated_at': generatedAt!.toUtc().toIso8601String(),
      });
}

class EndpointConfigException implements Exception {
  const EndpointConfigException(this.failure);
  final EndpointFailure failure;

  @override
  String toString() => 'EndpointConfigException($failure)';
}
