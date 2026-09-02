import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_exception.dart';
import 'package:qrivo_mobile/core/auth/auth_repository.dart';

void main() {
  group('AuthRepository', () {
    test('login parses the session tokens and the user', () async {
      late http.Request seen;
      final repo = AuthRepository(MockClient((req) async {
        seen = req;
        return http.Response(
          jsonEncode({
            'data': {
              'access_token': 'a',
              'refresh_token': 'r',
              'expires_at': '2030-01-01T00:00:00Z',
              'user': {
                'uuid': 'u-1',
                'email': 's@x.test',
                'first_name': 'Sam',
                'last_name': 'Lee',
                'roles': ['STUDENT'],
              },
            },
          }),
          200,
        );
      }),);

      final result = await repo.login('s@x.test', 'pw');

      expect(seen.url.path, endsWith('/api/v1/auth/login'));
      expect(jsonDecode(seen.body), {'email': 's@x.test', 'password': 'pw'});
      expect(result.session.accessToken, 'a');
      expect(result.session.refreshToken, 'r');
      expect(result.user.uuid, 'u-1');
      expect(result.user.isStudent, isTrue);
    });

    test('login maps a rejected credential to ApiException', () async {
      final repo = AuthRepository(MockClient((req) async => http.Response(
            jsonEncode({'message': 'Invalid credentials.'}),
            401,
          ),),);

      await expectLater(
        repo.login('s@x.test', 'bad'),
        throwsA(isA<ApiException>()
            .having((e) => e.message, 'message', 'Invalid credentials.'),),
      );
    });

    test('refresh returns a rotated session', () async {
      final repo = AuthRepository(MockClient((req) async => http.Response(
            jsonEncode({
              'data': {
                'access_token': 'a2',
                'refresh_token': 'r2',
                'expires_at': '2030-01-01T00:00:00Z',
              },
            }),
            200,
          ),),);

      final session = await repo.refresh('r1');

      expect(session.accessToken, 'a2');
      expect(session.refreshToken, 'r2');
    });

    test('me returns the current user from the data envelope', () async {
      late http.Request seen;
      final repo = AuthRepository(MockClient((req) async {
        seen = req;
        return http.Response(
          jsonEncode({
            'data': {
              'uuid': 'u-9',
              'email': 'me@x.test',
              'first_name': 'Mo',
              'last_name': 'K',
              'roles': ['STUDENT'],
            },
          }),
          200,
        );
      }),);

      final user = await repo.me('token-x');

      expect(seen.headers['Authorization'], 'Bearer token-x');
      expect(user.uuid, 'u-9');
    });

    test('me maps a 401 to ApiException', () async {
      final repo = AuthRepository(MockClient((req) async =>
          http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401),),);

      await expectLater(repo.me('dead'), throwsA(isA<ApiException>()));
    });

    test('logout never throws even when the server errors', () async {
      final repo = AuthRepository(
        MockClient((req) async => http.Response('nope', 500)),
      );

      await expectLater(repo.logout('token'), completes);
    });
  });
}
