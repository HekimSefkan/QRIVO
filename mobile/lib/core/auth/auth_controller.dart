import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_exception.dart';
import '../models/user.dart';
import 'auth_repository.dart';
import 'session.dart';
import 'session_store.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

/// Owns the authentication state for the whole app.
///
/// The backend is authoritative: this class only
/// - restores the persisted session on launch and validates it against `/auth/me`
/// - hands the current (auto-refreshed) access token to the [ApiClient]
/// - flips to `unauthenticated` and wipes the secure store when the server says so
class AuthController extends ChangeNotifier {
  AuthController({
    required AuthRepository repository,
    required SessionStore store,
  })  : _repo = repository,
        _store = store;

  final AuthRepository _repo;
  final SessionStore _store;

  AuthStatus _status = AuthStatus.unknown;
  User? _user;
  Session? _session;
  Completer<Session?>? _refreshInFlight;

  AuthStatus get status => _status;
  User? get user => _user;
  bool get isAuthenticated => _status == AuthStatus.authenticated;

  /// Called once at startup.
  Future<void> bootstrap() async {
    final stored = await _store.read();
    if (stored == null) {
      _setUnauthenticated();
      return;
    }

    _session = stored;
    try {
      final token = await currentAccessToken();
      if (token == null) {
        await _clear();
        return;
      }
      _user = await _repo.me(token);
      _status = AuthStatus.authenticated;
      notifyListeners();
    } on ApiException catch (e) {
      // A network hiccup shouldn't force a re-login if we still hold a session.
      if (e.isNetworkError && _session != null) {
        _status = AuthStatus.authenticated;
        notifyListeners();
      } else {
        await _clear();
      }
    }
  }

  Future<void> signIn(String email, String password) async {
    final result = await _repo.login(email.trim(), password);
    _session = result.session;
    _user = result.user;
    await _store.write(result.session);
    _status = AuthStatus.authenticated;
    notifyListeners();
  }

  Future<void> signOut() async {
    final token = _session?.accessToken;
    if (token != null) await _repo.logout(token);
    await _clear();
  }

  /// The token to use for an API call, refreshing first if it's near expiry.
  /// Returns null when the session is unrecoverable.
  Future<String?> currentAccessToken() async {
    final session = _session;
    if (session == null) return null;
    if (!session.needsRefresh) return session.accessToken;
    final refreshed = await _refresh();
    return refreshed?.accessToken;
  }

  /// Invoked by the [ApiClient] on a 401.
  Future<String?> handleUnauthorized() async {
    final refreshed = await _refresh();
    return refreshed?.accessToken;
  }

  Future<Session?> _refresh() {
    final existing = _refreshInFlight;
    if (existing != null) return existing.future;

    final completer = Completer<Session?>();
    _refreshInFlight = completer;

    () async {
      try {
        final token = _session?.refreshToken;
        if (token == null) {
          completer.complete(null);
          return;
        }
        final fresh = await _repo.refresh(token);
        _session = fresh;
        await _store.write(fresh);
        completer.complete(fresh);
      } on ApiException catch (e) {
        if (e.isNetworkError) {
          completer.complete(null); // keep the session; try again later
        } else {
          await _clear();
          completer.complete(null);
        }
      } finally {
        _refreshInFlight = null;
      }
    }();

    return completer.future;
  }

  Future<void> _clear() async {
    await _store.clear();
    _session = null;
    _user = null;
    _setUnauthenticated();
  }

  void _setUnauthenticated() {
    _status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
