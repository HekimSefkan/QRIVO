import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'session.dart';

/// Persists the [Session]. Implementations must keep it out of plain storage.
abstract class SessionStore {
  Future<Session?> read();
  Future<void> write(Session session);
  Future<void> clear();
}

/// Production store — platform Keychain (iOS) / EncryptedSharedPreferences +
/// Keystore (Android). The whole session is a single JSON blob under one key.
class SecureSessionStore implements SessionStore {
  SecureSessionStore([FlutterSecureStorage? storage])
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock_this_device,
              ),
            );

  static const _key = 'qrivo.session';
  final FlutterSecureStorage _storage;

  @override
  Future<Session?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null || raw.isEmpty) return null;
    try {
      final decoded = jsonDecode(raw);
      return decoded is Map<String, dynamic> ? Session.fromJson(decoded) : null;
    } catch (_) {
      await clear();
      return null;
    }
  }

  @override
  Future<void> write(Session session) =>
      _storage.write(key: _key, value: jsonEncode(session.toJson()));

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// In-memory store — used by tests and never in production.
class InMemorySessionStore implements SessionStore {
  Session? _session;

  @override
  Future<Session?> read() async => _session;

  @override
  Future<void> write(Session session) async => _session = session;

  @override
  Future<void> clear() async => _session = null;
}
