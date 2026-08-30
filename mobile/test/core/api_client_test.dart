import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_client.dart';
import 'package:qrivo_mobile/core/api/api_exception.dart';

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

void main() {
  group('ApiClient', () {
    test('attaches the bearer token and unwraps the envelope', () async {
      late http.Request seen;
      final client = _client((req) async {
        seen = req;
        return http.Response(
          jsonEncode({'success': true, 'data': {'id': 7}}),
          200,
          headers: {'content-type': 'application/json'},
        );
      });

      final res = await client.get('/student/profile');

      expect(seen.headers['Authorization'], 'Bearer access-1');
      expect(seen.url.path, endsWith('/api/v1/student/profile'));
      expect(res.object['id'], 7);
    });

    test('serializes query parameters', () async {
      late Uri url;
      final client = _client((req) async {
        url = req.url;
        return http.Response(jsonEncode({'data': [], 'meta': {}}), 200);
      });

      await client.get('/student/attendance/history', query: {'page': 2, 'per_page': 20});

      expect(url.queryParameters['page'], '2');
      expect(url.queryParameters['per_page'], '20');
    });

    test('sends a JSON body on POST', () async {
      late http.Request seen;
      final client = _client((req) async {
        seen = req;
        return http.Response(jsonEncode({'data': {'ok': true}}), 200);
      });

      await client.post('/thing', body: {'a': 1});

      expect(seen.headers['Content-Type'], contains('application/json'));
      expect(jsonDecode(seen.body), {'a': 1});
    });

    test('maps a non-2xx response to ApiException with the server message', () async {
      final client = _client((req) async => http.Response(
            jsonEncode({'success': false, 'message': 'Nope.'}),
            403,
          ));

      await expectLater(
        client.get('/x'),
        throwsA(isA<ApiException>()
            .having((e) => e.statusCode, 'statusCode', 403)
            .having((e) => e.message, 'message', 'Nope.')
            .having((e) => e.isForbidden, 'isForbidden', true)),
      );
    });

    test('maps a transport failure to a network ApiException', () async {
      final client = _client((req) async => throw http.ClientException('boom'));

      await expectLater(
        client.get('/x'),
        throwsA(isA<ApiException>().having((e) => e.isNetworkError, 'isNetworkError', true)),
      );
    });

    test('on 401 refreshes once and retries with the new token', () async {
      var calls = 0;
      final tokens = <String?>[];
      final client = ApiClient(
        httpClient: MockClient((req) async {
          calls++;
          tokens.add(req.headers['Authorization']);
          if (calls == 1) {
            return http.Response(jsonEncode({'message': 'expired'}), 401);
          }
          return http.Response(jsonEncode({'data': {'ok': true}}), 200);
        }),
        tokenProvider: () async => 'stale',
        onUnauthorized: () async => 'fresh',
      );

      final res = await client.get('/x');

      expect(calls, 2);
      expect(tokens, ['Bearer stale', 'Bearer fresh']);
      expect(res.object['ok'], true);
    });

    test('on 401 with no recoverable session, surfaces the 401', () async {
      var calls = 0;
      final client = ApiClient(
        httpClient: MockClient((req) async {
          calls++;
          return http.Response(jsonEncode({'message': 'expired'}), 401);
        }),
        tokenProvider: () async => 'stale',
        onUnauthorized: () async => null,
      );

      await expectLater(
        client.get('/x'),
        throwsA(isA<ApiException>().having((e) => e.isUnauthorized, 'isUnauthorized', true)),
      );
      expect(calls, 1);
    });
  });
}
