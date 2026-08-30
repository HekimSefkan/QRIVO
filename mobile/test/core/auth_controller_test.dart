import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:qrivo_mobile/core/api/api_exception.dart';
import 'package:qrivo_mobile/core/auth/auth_controller.dart';
import 'package:qrivo_mobile/core/auth/auth_repository.dart';
import 'package:qrivo_mobile/core/auth/session.dart';
import 'package:qrivo_mobile/core/auth/session_store.dart';
import 'package:qrivo_mobile/core/models/user.dart';

const _student = User(
  uuid: 'u-1',
  email: 's@x.test',
  firstName: 'Sam',
  lastName: 'Lee',
  roles: ['STUDENT'],
);

Session _session({Duration ttl = const Duration(hours: 1), String access = 'a'}) => Session(
      accessToken: access,
      refreshToken: 'r',
      expiresAt: DateTime.now().add(ttl),
    );

class _FakeAuthRepo extends AuthRepository {
  _FakeAuthRepo() : super(MockClient((_) async => http.Response('', 200)));

  AuthResult Function(String email, String password)? onLogin;
  Session Function(String refreshToken)? onRefresh;
  User Function(String token)? onMe;
  int meCalls = 0;
  int refreshCalls = 0;
  int logoutCalls = 0;

  @override
  Future<AuthResult> login(String email, String password) async =>
      (onLogin ?? (_, __) => AuthResult(session: _session(), user: _student))(email, password);

  @override
  Future<Session> refresh(String refreshToken) async {
    refreshCalls++;
    final fn = onRefresh;
    if (fn == null) throw ApiException('no refresh', statusCode: 401);
    return fn(refreshToken);
  }

  @override
  Future<User> me(String accessToken) async {
    meCalls++;
    final fn = onMe;
    if (fn == null) return _student;
    return fn(accessToken);
  }

  @override
  Future<void> logout(String accessToken) async => logoutCalls++;
}

void main() {
  group('AuthController.bootstrap', () {
    test('with no stored session ends unauthenticated', () async {
      final c = AuthController(repository: _FakeAuthRepo(), store: InMemorySessionStore());
      await c.bootstrap();
      expect(c.status, AuthStatus.unauthenticated);
    });

    test('with a valid stored session validates against /me and authenticates', () async {
      final store = InMemorySessionStore()..write(_session());
      final repo = _FakeAuthRepo();
      final c = AuthController(repository: repo, store: store);

      await c.bootstrap();

      expect(repo.meCalls, 1);
      expect(c.status, AuthStatus.authenticated);
      expect(c.user?.uuid, 'u-1');
    });

    test('a rejected token clears the session and store', () async {
      final store = InMemorySessionStore()..write(_session());
      final repo = _FakeAuthRepo()..onMe = (_) => throw ApiException('bad', statusCode: 401);
      final c = AuthController(repository: repo, store: store);

      await c.bootstrap();

      expect(c.status, AuthStatus.unauthenticated);
      expect(await store.read(), isNull);
    });

    test('a network error keeps the stored session', () async {
      final store = InMemorySessionStore()..write(_session());
      final repo = _FakeAuthRepo()..onMe = (_) => throw ApiException.network();
      final c = AuthController(repository: repo, store: store);

      await c.bootstrap();

      expect(c.status, AuthStatus.authenticated);
      expect(await store.read(), isNotNull);
    });
  });

  group('AuthController.signIn / signOut', () {
    test('signIn persists the session and authenticates', () async {
      final store = InMemorySessionStore();
      final repo = _FakeAuthRepo();
      final c = AuthController(repository: repo, store: store);

      await c.signIn(' s@x.test ', 'pw');

      expect(c.status, AuthStatus.authenticated);
      expect((await store.read())?.accessToken, 'a');
    });

    test('signIn trims the email before calling the API', () async {
      String? seenEmail;
      final repo = _FakeAuthRepo()
        ..onLogin = (email, _) {
          seenEmail = email;
          return AuthResult(session: _session(), user: _student);
        };
      final c = AuthController(repository: repo, store: InMemorySessionStore());

      await c.signIn('  s@x.test  ', 'pw');

      expect(seenEmail, 's@x.test');
    });

    test('signOut calls the API, wipes the store, and de-authenticates', () async {
      final store = InMemorySessionStore()..write(_session());
      final repo = _FakeAuthRepo();
      final c = AuthController(repository: repo, store: store);
      await c.bootstrap();

      await c.signOut();

      expect(repo.logoutCalls, 1);
      expect(c.status, AuthStatus.unauthenticated);
      expect(await store.read(), isNull);
    });
  });

  group('AuthController token refresh', () {
    /// Signs in with a session that is already inside the refresh window.
    Future<AuthController> signedInNearExpiry(_FakeAuthRepo repo, SessionStore store) async {
      repo.onLogin = (_, __) => AuthResult(
            session: _session(ttl: const Duration(seconds: 5)),
            user: _student,
          );
      final c = AuthController(repository: repo, store: store);
      await c.signIn('s@x.test', 'pw');
      return c;
    }

    test('currentAccessToken refreshes a near-expiry token', () async {
      final store = InMemorySessionStore();
      final repo = _FakeAuthRepo()..onRefresh = (_) => _session(access: 'a2');
      final c = await signedInNearExpiry(repo, store);

      final token = await c.currentAccessToken();

      expect(token, 'a2');
      expect(repo.refreshCalls, 1);
      expect((await store.read())?.accessToken, 'a2');
    });

    test('concurrent refreshes are coalesced into one network call', () async {
      final store = InMemorySessionStore();
      final repo = _FakeAuthRepo()..onRefresh = (_) => _session(access: 'a2');
      final c = await signedInNearExpiry(repo, store);

      await Future.wait([
        c.currentAccessToken(),
        c.currentAccessToken(),
        c.handleUnauthorized(),
      ]);

      expect(repo.refreshCalls, 1);
    });

    test('a failed refresh clears the session', () async {
      final store = InMemorySessionStore();
      final repo = _FakeAuthRepo()
        ..onRefresh = (_) => throw ApiException('bad refresh', statusCode: 401);
      final c = await signedInNearExpiry(repo, store);

      final token = await c.currentAccessToken();

      expect(token, isNull);
      expect(c.status, AuthStatus.unauthenticated);
      expect(await store.read(), isNull);
    });
  });
}
