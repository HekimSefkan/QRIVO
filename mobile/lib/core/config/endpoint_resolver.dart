import 'dart:async';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import 'app_config.dart';
import 'endpoint_config.dart';

/// Learns where the API currently lives.
///
/// The tunnel address changes every time the laptop restarts, so it is NOT
/// compiled into the app. What IS compiled in is the address of a small,
/// publicly readable config document that never changes. At launch the app
/// reads that document, learns the current API address, and caches it. When a
/// request later fails at the transport level the app re-reads the document, so
/// it heals itself after the laptop restarts without anyone rebuilding the APK.
///
/// The cache is stored in the platform secure store. Not because the address is
/// a secret — it is not — but because that store is already a dependency, so
/// this adds no new package to the build.
class EndpointResolver {
  EndpointResolver({
    http.Client? httpClient,
    FlutterSecureStorage? storage,
    Duration? timeout,
  })  : _http = httpClient ?? http.Client(),
        _storage = storage ?? const FlutterSecureStorage(),
        _timeout = timeout ?? const Duration(seconds: 12);

  static const _cacheKey = 'qrivo.endpoint';

  final http.Client _http;
  final FlutterSecureStorage _storage;
  final Duration _timeout;

  /// The address in force right now, or null before the first successful
  /// resolve.
  EndpointConfig? get current => _current;
  EndpointConfig? _current;

  /// The last failure, so the UI can say something true and specific.
  EndpointFailure? get lastFailure => _lastFailure;
  EndpointFailure? _lastFailure;

  Future<void>? _inFlight;

  /// Load whatever we already know, without touching the network.
  ///
  /// Called first at launch so the app can make its first request immediately
  /// rather than waiting on GitHub. A cached address that turns out to be dead
  /// is handled by [refresh] on the failure path.
  Future<EndpointConfig?> loadCached() async {
    if (_current != null) return _current;
    try {
      final raw = await _storage.read(key: _cacheKey);
      if (raw == null || raw.isEmpty) return null;
      // Re-validate on read: a cached value must clear exactly the same bar as
      // a freshly fetched one, so a tampered cache cannot widen the pin.
      final config = EndpointConfig.parse(raw);
      _current = config;
      AppConfig.setRuntimeBaseUrl(config.apiBaseUrl);
      return config;
    } catch (_) {
      await _storage.delete(key: _cacheKey);
      return null;
    }
  }

  /// Addresses to try on the local network before consulting the published
  /// config.
  ///
  /// `192.168.137.1` is not a guess: Windows Mobile Hotspot always puts the
  /// host at that address. When the lecturer's laptop is the hotspot and the
  /// phone has joined it, this is where the API is — no internet, no tunnel, no
  /// address typed in by anyone.
  static const lanCandidates = <String>[
    'http://192.168.137.1:8000',
  ];

  /// Try the LAN first, briefly.
  ///
  /// Ordering matters. On the hotspot the LAN path is the only one that works
  /// (there may be no internet at all), and it is also faster and has fewer
  /// moving parts than the tunnel. Off the hotspot the probe fails in about a
  /// second and costs nothing.
  ///
  /// The timeout is deliberately short: this runs before the first screen, and
  /// a phone on mobile data must not wait on an address that cannot answer.
  Future<EndpointConfig?> probeLan({Duration timeout = const Duration(milliseconds: 1200)}) async {
    for (final base in lanCandidates) {
      // Belt and braces: the candidate must clear the same pin as anything
      // arriving from the config document.
      if (!EndpointConfig.isAllowedApiBase(base)) continue;
      try {
        final r = await _http
            .get(Uri.parse('$base/api/v1/health'))
            .timeout(timeout);
        if (r.statusCode == 200 && r.body.contains('"success"')) {
          final config = EndpointConfig(
            apiBaseUrl: base,
            generatedAt: DateTime.now().toUtc(),
          );
          _current = config;
          _lastFailure = null;
          AppConfig.setRuntimeBaseUrl(base);
          // Deliberately NOT cached: a LAN address is only meaningful while
          // this phone is on that hotspot, and caching it would send the app
          // to a dead address the next time it is somewhere else.
          return config;
        }
      } catch (_) {
        // Not on the hotspot. Entirely normal.
      }
    }
    return null;
  }

  /// Fetch the config document and adopt the address it advertises.
  ///
  /// Concurrent callers share one in-flight request: a burst of failing
  /// requests must not become a burst of config fetches.
  Future<EndpointConfig?> refresh() async {
    if (_inFlight != null) {
      await _inFlight;
      return _current;
    }
    final completer = Completer<void>();
    _inFlight = completer.future;
    try {
      await _doRefresh();
    } finally {
      _inFlight = null;
      completer.complete();
    }
    return _current;
  }

  /// Full resolution: LAN first, then the published config.
  ///
  /// This is what startup and the self-healing path both call, so the ordering
  /// is defined in exactly one place.
  Future<EndpointConfig?> resolve() async {
    final lan = await probeLan();
    if (lan != null) return lan;
    return refresh();
  }

  Future<void> _doRefresh() async {
    // Primary then fallback. See AppConfig.configUrlFallback for why the
    // GitHub API is primary: the raw CDN caches for 5 minutes and ignores a
    // cache-busting query string, so it can serve the PREVIOUS tunnel address
    // exactly when the app is trying to heal.
    final sources = <String>[
      if (AppConfig.configUrl.isNotEmpty) AppConfig.configUrl,
      if (AppConfig.configUrlFallback.isNotEmpty) AppConfig.configUrlFallback,
    ];

    http.Response? response;
    for (final source in sources) {
      try {
        final uri = Uri.parse(source).replace(
          queryParameters: {
            ...Uri.parse(source).queryParameters,
            't': DateTime.now().millisecondsSinceEpoch.toString(),
          },
        );
        final candidate = await _http.get(uri, headers: {
          // Asks the GitHub API for the file's raw bytes rather than its JSON
          // metadata envelope. Harmless on the raw CDN, which ignores it.
          'Accept': 'application/vnd.github.raw, application/json',
          'Cache-Control': 'no-cache',
        },).timeout(_timeout);
        if (candidate.statusCode == 200) {
          response = candidate;
          break;
        }
        // Non-200 (rate limited, 404) — try the next source.
      } catch (_) {
        // Transport failure — try the next source.
      }
    }

    if (response == null) {
      _lastFailure = EndpointFailure.configUnreachable;
      return;
    }

    try {
      final config = EndpointConfig.parse(response.body);
      _current = config;
      _lastFailure = null;
      AppConfig.setRuntimeBaseUrl(config.apiBaseUrl);
      await _storage.write(key: _cacheKey, value: config.toJson());
    } on EndpointConfigException catch (e) {
      // A malformed or rejected config must NOT overwrite a good cached
      // address: that would turn one bad publish into a bricked app.
      _lastFailure = e.failure;
    }
  }

  /// A true, specific sentence for the current state. Never "something went
  /// wrong".
  String describeFailure() {
    switch (_lastFailure) {
      case EndpointFailure.configUnreachable:
        return _current == null
            ? 'Cannot reach the QRIVO configuration service. Check your internet connection.'
            : 'Could not check for a new server address. Using the last known one.';
      case EndpointFailure.configMalformed:
        return 'The QRIVO configuration is unreadable. Please tell your lecturer.';
      case EndpointFailure.configRejected:
        return 'The QRIVO configuration points somewhere unexpected and was refused for safety.';
      case null:
        final config = _current;
        if (config == null) {
          return 'The QRIVO server address is not known yet.';
        }
        if (config.isStale) {
          final hours = config.age?.inHours ?? 0;
          return 'The server address is ${hours}h old and may be out of date. '
              'The lecturer\'s computer may be switched off.';
        }
        return 'The QRIVO server is not responding.';
    }
  }
}
