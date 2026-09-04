import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'core/api/api_client.dart';
import 'core/api/student_api.dart';
import 'core/auth/auth_controller.dart';
import 'core/config/endpoint_resolver.dart';
import 'features/auth/login_screen.dart';
import 'features/home/home_shell.dart';

/// Wires the object graph and swaps between the login screen and the home shell
/// based on [AuthController.status]. All state is provided, not global.
class QrivoApp extends StatefulWidget {
  const QrivoApp({
    super.key,
    required this.authController,
    required this.httpClient,
    this.endpointResolver,
  });

  final AuthController authController;
  final http.Client httpClient;

  /// Resolves the API address at runtime. Null in tests and in builds that use
  /// a compile-time address.
  final EndpointResolver? endpointResolver;

  @override
  State<QrivoApp> createState() => _QrivoAppState();
}

class _QrivoAppState extends State<QrivoApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Returning from the background is the moment both problems bite at once:
  /// the access token may have expired, and the OS has usually dropped the
  /// keep-alive socket. Revalidating here means the first screen the student
  /// touches starts from a known-good session instead of absorbing the failure.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      // Fire and forget: it must never block the UI, and a failure here is not
      // fatal — the request path still refreshes on a 401 as before.
      widget.authController.revalidateOnResume();
    }
  }

  @override
  Widget build(BuildContext context) {
    final authController = widget.authController;
    final httpClient = widget.httpClient;
    return MultiProvider(
      providers: [
        ChangeNotifierProvider.value(value: authController),
        Provider<ApiClient>(
          create: (_) {
            final client = ApiClient(
              httpClient: httpClient,
              tokenProvider: authController.currentAccessToken,
              onUnauthorized: authController.handleUnauthorized,
            );
            // Self-healing: when the address looks dead, re-read the published
            // config and report whether it changed, so the request can be
            // retried against the new tunnel without a rebuild.
            final resolver = widget.endpointResolver;
            if (resolver != null) {
              client.onAddressStale = () async {
                final before = resolver.current?.apiBaseUrl;
                await resolver.refresh();
                final after = resolver.current?.apiBaseUrl;
                return after != null && after != before;
              };
            }
            return client;
          },
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
