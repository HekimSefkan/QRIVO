import 'dart:async';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import 'app.dart';
import 'core/auth/auth_controller.dart';
import 'core/auth/auth_repository.dart';
import 'core/auth/session_store.dart';
import 'core/config/app_config.dart';
import 'core/config/endpoint_resolver.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final httpClient = http.Client();

  // Where does the API live today?
  //
  // The tunnel hostname changes every time the lecturer's laptop restarts, so
  // it is not compiled in. Load the cached address FIRST (instant, no network)
  // so the first request can go out immediately, then refresh from the
  // published config in the background. If the cached address turns out to be
  // dead, ApiClient.onAddressStale re-resolves and retries — that is what heals
  // the app after a restart without anyone rebuilding it.
  final resolver = EndpointResolver(httpClient: httpClient);
  if (AppConfig.usesRuntimeConfig) {
    await resolver.loadCached();
    if (resolver.current == null) {
      // Nothing cached — this is a first launch, so we must wait for the
      // config before the app can talk to anything.
      await resolver.refresh();
    } else {
      unawaited(resolver.refresh());
    }
  }

  final auth = AuthController(
    repository: AuthRepository(httpClient),
    store: SecureSessionStore(),
  );

  // Restore any persisted session before the first frame decides what to show.
  await auth.bootstrap();

  runApp(QrivoApp(
    authController: auth,
    httpClient: httpClient,
    endpointResolver: resolver,
  ),);
}
