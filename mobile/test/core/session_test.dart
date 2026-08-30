import 'package:flutter_test/flutter_test.dart';
import 'package:qrivo_mobile/core/auth/session.dart';

void main() {
  group('Session', () {
    test('json round-trips', () {
      final s = Session(
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: DateTime.utc(2030, 1, 1, 12),
      );
      final back = Session.fromJson(s.toJson());
      expect(back, isNotNull);
      expect(back!.accessToken, 'a');
      expect(back.refreshToken, 'r');
      expect(back.expiresAt, s.expiresAt);
    });

    test('fromJson returns null on missing / bad fields', () {
      expect(Session.fromJson({'access_token': 'a'}), isNull);
      expect(
        Session.fromJson({
          'access_token': 'a',
          'refresh_token': 'r',
          'expires_at': 'not-a-date',
        }),
        isNull,
      );
    });

    test('isAccessTokenExpired reflects the clock', () {
      final expired = Session(
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: DateTime.now().subtract(const Duration(seconds: 1)),
      );
      final fresh = Session(
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: DateTime.now().add(const Duration(hours: 1)),
      );
      expect(expired.isAccessTokenExpired, isTrue);
      expect(fresh.isAccessTokenExpired, isFalse);
    });

    test('needsRefresh triggers within the leeway window', () {
      final soon = Session(
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: DateTime.now().add(const Duration(seconds: 30)),
      );
      final later = Session(
        accessToken: 'a',
        refreshToken: 'r',
        expiresAt: DateTime.now().add(const Duration(minutes: 10)),
      );
      expect(soon.needsRefresh, isTrue);
      expect(later.needsRefresh, isFalse);
    });
  });
}
