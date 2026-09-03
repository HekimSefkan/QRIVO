import 'dart:async';
import 'dart:convert';
import 'dart:io' show SocketException, OSError;

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_client.dart';
import 'package:qrivo_mobile/core/api/api_exception.dart';

/// Resilience behaviour added after the "Could not reach the server" report.
///
/// Two root causes were confirmed on the real stack: the API was served by
/// `php -S`, which handles one request at a time on Windows, and the app made
/// no attempt to recover from the dead keep-alive socket that a phone almost
/// always has after returning from the background.
///
/// These tests pin the CLIENT half of that fix. They must not encode any
/// security decision: the server stays authoritative throughout.
ApiClient _client(
  MockClientHandler handler, {
  String? token = 'access-1',
  UnauthorizedHandler? onUnauthorized,
}) {
  return ApiClient(
    httpClient: MockClient(handler),
    tokenProvider: () async => token,
    onUnauthorized: onUnauthorized,
  );
}

http.Response _ok([Map<String, dynamic>? data]) => http.Response(
      jsonEncode({'success': true, 'data': data ?? {'ok': true}}),
      200,
      headers: {'content-type': 'application/json'},
    );

void main() {
  group('retry', () {
    test('a GET that fails once with a dropped socket succeeds on retry',
        () async {
      var calls = 0;
      final client = _client((req) async {
        calls++;
        if (calls == 1) {
          throw http.ClientException(
            'Connection closed before full header was received',
            req.url,
          );
        }
        return _ok({'id': 1});
      });

      final res = await client.get('/student/dashboard');

      expect(calls, 2, reason: 'the first attempt must be retried');
      expect(res.object['id'], 1);
    });

    test('a GET gives up after the retry budget and reports unreachable',
        () async {
      var calls = 0;
      final client = _client((req) async {
        calls++;
        throw http.ClientException('Connection reset by peer', req.url);
      });

      await expectLater(
        client.get('/student/dashboard'),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.unreachable),),
      );
      // one initial attempt + two retries
      expect(calls, 3);
    });

    test('a POST is NEVER retried, so an attendance submission cannot double',
        () async {
      var calls = 0;
      final client = _client((req) async {
        calls++;
        throw http.ClientException('Connection reset by peer', req.url);
      });

      await expectLater(
        client.post('/student/attendance/verify', body: {'nonce': 'n'}),
        throwsA(isA<ApiException>()),
      );

      expect(calls, 1,
          reason: 'retrying a POST could create a duplicate attendance record',);
    });
  });

  group('failure classification', () {
    test('a timeout is reported as a timeout, not as "no connection"',
        () async {
      final client = _client((req) async {
        throw TimeoutException('too slow');
      });

      await expectLater(
        client.post('/auth/login', body: {}),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.timeout)
            .having((e) => e.isNetworkError, 'isNetworkError', true),),
      );
    });

    test('a DNS failure is reported as the phone being offline', () async {
      final client = _client((req) async {
        throw http.ClientException(
          'Failed host lookup: qrivo.tailbf9d6c.ts.net',
          req.url,
        );
      });

      await expectLater(
        client.post('/auth/login', body: {}),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.offline),),
      );
    });

    test('a raw SocketException with no OS error is treated as offline',
        () async {
      final client = _client((req) async {
        throw const SocketException('no route');
      });

      await expectLater(
        client.post('/auth/login', body: {}),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.offline),),
      );
    });

    test('a connection refused is unreachable, not offline', () async {
      final client = _client((req) async {
        throw const SocketException(
          'connection refused',
          osError: OSError('refused', 10061),
        );
      });

      await expectLater(
        client.post('/auth/login', body: {}),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.unreachable),),
      );
    });
  });

  group('session expiry', () {
    test('a 401 whose refresh fails is reported as an expired session', () async {
      var calls = 0;
      final client = _client(
        (req) async {
          calls++;
          return http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401);
        },
        onUnauthorized: () async => null, // refresh could not recover
      );

      await expectLater(
        client.get('/student/dashboard'),
        throwsA(isA<ApiException>()
            .having((e) => e.kind, 'kind', ApiFailureKind.sessionExpired)
            .having((e) => e.statusCode, 'statusCode', 401)
            .having((e) => e.isNetworkError, 'isNetworkError', false),),
      );

      expect(calls, 1, reason: 'the 401 itself must not be retried');
    });

    test('a 401 whose refresh succeeds retries once and returns the data',
        () async {
      var calls = 0;
      final client = _client(
        (req) async {
          calls++;
          if (calls == 1) {
            return http.Response(jsonEncode({'message': 'expired'}), 401);
          }
          return _ok({'id': 9});
        },
        onUnauthorized: () async => 'fresh-token',
      );

      final res = await client.get('/student/dashboard');

      expect(calls, 2);
      expect(res.object['id'], 9);
    });
  });
}
