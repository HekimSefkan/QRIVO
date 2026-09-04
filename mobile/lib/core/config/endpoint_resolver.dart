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

  Future<void> _doRefresh() async {
    http.Response response;
    try {
      // Cache-buster: raw.githubusercontent sits behind a CDN that will happily
      // serve the previous address for minutes after a restart, which is
      // exactly when we need the new one.
      final uri = Uri.parse(AppConfig.configUrl).replace(
        queryParameters: {'t': DateTime.now().millisecondsSinceEpoch.toString()},
      );
      response = await _http.get(uri, headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache',
      }).timeout(_timeout);
    } catch (_) {
      _lastFailure = EndpointFailure.configUnreachable;
      return;
    }

    if (response.statusCode != 200) {
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
