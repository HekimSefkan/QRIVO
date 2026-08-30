import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'core/api/api_client.dart';
import 'core/api/student_api.dart';
import 'core/auth/auth_controller.dart';
import 'features/auth/login_screen.dart';
import 'features/home/home_shell.dart';

/// Wires the object graph and swaps between the login screen and the home shell
/// based on [AuthController.status]. All state is provided, not global.
class QrivoApp extends StatelessWidget {
  const QrivoApp({
    super.key,
    required this.authController,
    required this.httpClient,
  });

  final AuthController authController;
  final http.Client httpClient;

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: authController),
        Provider<ApiClient>(
          create: (_) => ApiClient(
            httpClient: httpClient,
            tokenProvider: authController.currentAccessToken,
            onUnauthorized: authController.handleUnauthorized,
          ),
        ),
        ProxyProvider<ApiClient, StudentApi>(
          update: (_, client, __) => StudentApi(client),
        ),
      ],
      child: MaterialApp(
        title: 'QRIVO',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorSchemeSeed: const Color(0xFF2563EB),
          useMaterial3: true,
        ),
        home: Consumer<AuthController>(
          builder: (context, auth, _) {
            switch (auth.status) {
              case AuthStatus.unknown:
                return const _Splash();
              case AuthStatus.authenticated:
                return const HomeShell();
              case AuthStatus.unauthenticated:
                return const LoginScreen();
            }
          },
        ),
      ),
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) =>
      const Scaffold(body: Center(child: CircularProgressIndicator()));
}
