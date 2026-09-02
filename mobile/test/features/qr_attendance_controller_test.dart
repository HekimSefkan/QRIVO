import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_client.dart';
import 'package:qrivo_mobile/core/api/student_api.dart';
import 'package:qrivo_mobile/features/attendance/attendance_failure.dart';
import 'package:qrivo_mobile/features/attendance/qr_attendance_controller.dart';

// The backend is mocked here, so only the local `qrivo.` prefix filter matters.
const _qr = 'qrivo.v1.11111111-1111-1111-1111-111111111111.1735725600.abc.def';

typedef Responder = FutureOr<http.Response> Function(http.Request req);

http.Response _data(Map<String, dynamic> data, [int code = 200]) =>
    http.Response(jsonEncode({'data': data}), code,
        headers: {'content-type': 'application/json'},);

http.Response _err(int code, String message) =>
    http.Response(jsonEncode({'message': message}), code,
        headers: {'content-type': 'application/json'},);

class _Backend {
  final calls = <String>[];

  Responder preflight = (_) => _data({'valid': true, 'reason': 'VALID'});
  Responder challenge =
      (_) => _data({'challenge_id': 'c-1', 'nonce': 'nonce-1'}, 201);
  Responder verify = (_) => _data({
        'status': 'PRESENT',
        'source': 'QR',
        'session_uuid': 's-1',
        'marked_at': '2026-01-01T09:01:00Z',
        'risk': {'level': 'LOW', 'outcome': 'PRESENT'},
      });

  late final StudentApi api = StudentApi(
    ApiClient(
      httpClient: MockClient((req) async {
        final p = req.url.path;
        if (p.endsWith('/student/attendance/qr/verify')) {
          calls.add('preflight');
          return preflight(req);
        }
        if (p.endsWith('/student/attendance/challenge')) {
          calls.add('challenge');
          return challenge(req);
        }
        if (p.endsWith('/student/attendance/verify')) {
          calls.add('verify');
          return verify(req);
        }
        return _err(404, 'no route');
      }),
      tokenProvider: () async => 'token',
    ),
  );
}

void main() {
  group('QrAttendanceController — happy path', () {
    test('preflight → challenge → verify → PRESENT', () async {
      final b = _Backend();
      final c = QrAttendanceController(b.api);

      await c.submit(_qr);

      expect(b.calls, ['preflight', 'challenge', 'verify']);
      expect(c.stage, QrFlowStage.success);
      expect(c.result?.status, 'PRESENT');
      expect(c.failure, isNull);
    });

    test('PENDING_REVIEW still counts as success, flagged for review', () async {
      final b = _Backend()
        ..verify = (_) => _data({
              'status': 'PENDING_REVIEW',
              'source': 'QR',
              'risk': {'level': 'HIGH', 'outcome': 'PENDING_REVIEW'},
            });
      final c = QrAttendanceController(b.api);

      await c.submit(_qr);

      expect(c.stage, QrFlowStage.success);
      expect(c.result?.needsReview, isTrue);
    });
  });

  group('QrAttendanceController — local filter', () {
    test('a non-QRIVO barcode never touches the network', () async {
      final b = _Backend();
      final c = QrAttendanceController(b.api);

      await c.submit('https://example.com/promo');

      expect(b.calls, isEmpty);
      expect(c.stage, QrFlowStage.failure);
      expect(c.failure?.kind, QrFailureKind.notQrivoCode);
      expect(c.failure?.retryable, isTrue);
    });
  });

  group('QrAttendanceController — QR validation failures (preflight)', () {
    Future<AttendanceFailure> failWith(String reason) async {
      final b = _Backend()
        ..preflight = (_) => _data({'valid': false, 'reason': reason});
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(b.calls, ['preflight'], reason: 'must stop before requesting a challenge');
      expect(c.stage, QrFlowStage.failure);
      return c.failure!;
    }

    test('EXPIRED', () async {
      expect((await failWith('EXPIRED')).kind, QrFailureKind.expiredQr);
    });
    test('BAD_SIGNATURE → tampered', () async {
      expect((await failWith('BAD_SIGNATURE')).kind, QrFailureKind.tamperedQr);
    });
    test('SESSION_NOT_ACTIVE → session unavailable', () async {
      expect((await failWith('SESSION_NOT_ACTIVE')).kind,
          QrFailureKind.sessionUnavailable,);
    });
    test('REPLAYED → already recorded', () async {
      expect((await failWith('REPLAYED')).kind, QrFailureKind.alreadyRecorded);
    });
    test('MALFORMED → invalid', () async {
      expect((await failWith('MALFORMED')).kind, QrFailureKind.invalidQr);
    });
  });

  group('QrAttendanceController — challenge failures', () {
    test('409 already-used QR', () async {
      final b = _Backend()
        ..challenge = (_) => _err(409, 'This QR has already been used.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(b.calls, ['preflight', 'challenge']);
      expect(c.failure?.kind, QrFailureKind.alreadyRecorded);
      expect(c.failure?.retryable, isFalse);
    });

    test('429 rate limited', () async {
      final b = _Backend()
        ..challenge = (_) => _err(429, 'Too many attempts. Please wait and try again.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.rateLimited);
      expect(c.failure?.retryable, isTrue);
    });

    test('403 not enrolled', () async {
      final b = _Backend()
        ..challenge = (_) => _err(403, 'You are not enrolled in this course.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.notEnrolled);
      expect(c.failure?.retryable, isFalse);
    });
  });

  group('QrAttendanceController — verification failures', () {
    test('409 duplicate attendance', () async {
      final b = _Backend()
        ..verify = (_) =>
            _err(409, 'Attendance has already been recorded for this session.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(b.calls, ['preflight', 'challenge', 'verify']);
      expect(c.failure?.kind, QrFailureKind.alreadyRecorded);
    });

    test('409 challenge expired is retryable', () async {
      final b = _Backend()
        ..verify = (_) => _err(409, 'This challenge has expired.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.challengeExpired);
      expect(c.failure?.retryable, isTrue);
    });

    test('403 risk-blocked', () async {
      final b = _Backend()
        ..verify = (_) => _err(403, 'Attendance could not be recorded.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.blocked);
      expect(c.failure?.retryable, isFalse);
    });

    test('401 → must re-authenticate', () async {
      final b = _Backend()..verify = (_) => _err(401, 'Unauthenticated.');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.notAuthenticated);
      expect(c.failure?.retryable, isFalse);
    });
  });

  group('QrAttendanceController — transport & concurrency', () {
    test('network error is retryable', () async {
      final b = _Backend()
        ..challenge = (_) => throw http.ClientException('offline');
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.failure?.kind, QrFailureKind.network);
      expect(c.failure?.retryable, isTrue);
    });

    test('a second submit while busy is ignored', () async {
      final gate = Completer<http.Response>();
      final b = _Backend()..preflight = (_) => gate.future;
      final c = QrAttendanceController(b.api);

      final first = c.submit(_qr);
      expect(c.isBusy, isTrue);
      await c.submit(_qr); // must no-op
      gate.complete(_data({'valid': true, 'reason': 'VALID'}));
      await first;

      expect(b.calls.where((e) => e == 'preflight').length, 1);
      expect(c.stage, QrFlowStage.success);
    });

    test('reset() returns to idle for another scan', () async {
      final b = _Backend()
        ..preflight = (_) => _data({'valid': false, 'reason': 'EXPIRED'});
      final c = QrAttendanceController(b.api);
      await c.submit(_qr);
      expect(c.stage, QrFlowStage.failure);

      c.reset();

      expect(c.stage, QrFlowStage.idle);
      expect(c.failure, isNull);
      expect(c.result, isNull);
    });
  });
}
