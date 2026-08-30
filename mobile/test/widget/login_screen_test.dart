import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:qrivo_mobile/core/api/api_exception.dart';
import 'package:qrivo_mobile/core/auth/auth_controller.dart';
import 'package:qrivo_mobile/core/auth/auth_repository.dart';
import 'package:qrivo_mobile/core/auth/session_store.dart';
import 'package:qrivo_mobile/features/auth/login_screen.dart';

class _FakeAuth extends AuthController {
  _FakeAuth()
      : super(
          repository: AuthRepository(MockClient((_) async => http.Response('', 200))),
          store: InMemorySessionStore(),
        );

  int calls = 0;
  Object? throwOnSignIn;
  final creds = <List<String>>[];

  @override
  Future<void> signIn(String email, String password) async {
    calls++;
    creds.add([email, password]);
    final err = throwOnSignIn;
    if (err != null) throw err;
  }
}

Future<void> _pump(WidgetTester tester, _FakeAuth auth) {
  return tester.pumpWidget(
    ChangeNotifierProvider<AuthController>.value(
      value: auth,
      child: const MaterialApp(home: LoginScreen()),
    ),
  );
}

void main() {
  testWidgets('blocks submission when the email is invalid', (tester) async {
    final auth = _FakeAuth();
    await _pump(tester, auth);

    await tester.enterText(find.byKey(const Key('login_email')), 'not-an-email');
    await tester.enterText(find.byKey(const Key('login_password')), 'secret');
    await tester.tap(find.byKey(const Key('login_submit')));
    await tester.pump();

    expect(find.text('Enter a valid email'), findsOneWidget);
    expect(auth.calls, 0);
  });

  testWidgets('submits trimmed-valid credentials to the controller', (tester) async {
    final auth = _FakeAuth();
    await _pump(tester, auth);

    await tester.enterText(find.byKey(const Key('login_email')), 's@x.test');
    await tester.enterText(find.byKey(const Key('login_password')), 'secret');
    await tester.tap(find.byKey(const Key('login_submit')));
    await tester.pumpAndSettle();

    expect(auth.calls, 1);
    expect(auth.creds.single, ['s@x.test', 'secret']);
  });

  testWidgets('shows the server error message on a failed sign in', (tester) async {
    final auth = _FakeAuth()..throwOnSignIn = ApiException('Invalid credentials.', statusCode: 401);
    await _pump(tester, auth);

    await tester.enterText(find.byKey(const Key('login_email')), 's@x.test');
    await tester.enterText(find.byKey(const Key('login_password')), 'wrong');
    await tester.tap(find.byKey(const Key('login_submit')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('login_error')), findsOneWidget);
    expect(find.text('Invalid credentials.'), findsOneWidget);
  });
}
