import 'package:flutter_test/flutter_test.dart';
import 'package:qrivo_mobile/core/models/attendance_challenge.dart';
import 'package:qrivo_mobile/core/models/attendance_result.dart';
import 'package:qrivo_mobile/core/models/qr_preflight.dart';

void main() {
  group('QrPreflight', () {
    test('parses a valid verdict', () {
      final p = QrPreflight.fromJson({
        'valid': true,
        'reason': 'VALID',
        'session_uuid': '11111111-1111-1111-1111-111111111111',
      });
      expect(p.valid, isTrue);
      expect(p.reason, 'VALID');
      expect(p.sessionUuid, isNotNull);
    });

    test('defaults to invalid / MALFORMED on an empty body', () {
      final p = QrPreflight.fromJson(const {});
      expect(p.valid, isFalse);
      expect(p.reason, 'MALFORMED');
    });
  });

  group('AttendanceChallenge', () {
    test('parses challenge_id / nonce / expires_at', () {
      final c = AttendanceChallenge.fromJson({
        'challenge_id': 'c-1',
        'nonce': 'abc',
        'expires_at': '2026-01-01T09:02:00Z',
      });
      expect(c.challengeId, 'c-1');
      expect(c.nonce, 'abc');
      expect(c.expiresAt, isNotNull);
    });
  });

  group('AttendanceResult', () {
    test('parses a PRESENT result with risk', () {
      final r = AttendanceResult.fromJson({
        'status': 'PRESENT',
        'source': 'QR',
        'session_uuid': 's-1',
        'marked_at': '2026-01-01T09:01:00Z',
        'risk': {'level': 'LOW', 'outcome': 'PRESENT'},
      });
      expect(r.isPresent, isTrue);
      expect(r.needsReview, isFalse);
      expect(r.riskLevel, 'LOW');
      expect(r.markedAt, isNotNull);
    });

    test('flags PENDING_REVIEW', () {
      final r = AttendanceResult.fromJson({
        'status': 'PENDING_REVIEW',
        'source': 'QR',
        'risk': {'level': 'HIGH', 'outcome': 'PENDING_REVIEW'},
      });
      expect(r.isPresent, isFalse);
      expect(r.needsReview, isTrue);
    });
  });
}
