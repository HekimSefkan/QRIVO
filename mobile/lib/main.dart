import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import 'app.dart';
import 'core/auth/auth_controller.dart';
import 'core/auth/auth_repository.dart';
import 'core/auth/session_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final httpClient = http.Client();
  final auth = AuthController(
    repository: AuthRepository(httpClient),
    store: SecureSessionStore(),
  );

  // Restore any persisted session before the first frame decides what to show.
  await auth.bootstrap();

  runApp(QrivoApp(authController: auth, httpClient: httpClient));
}
