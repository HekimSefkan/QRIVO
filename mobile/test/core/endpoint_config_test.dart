import 'package:flutter_test/flutter_test.dart';
import 'package:qrivo_mobile/core/config/endpoint_config.dart';

void main() {
  group('EndpointConfig host pin', () {
    test('accepts a genuine cloudflare quick-tunnel host over https', () {
      expect(
        EndpointConfig.isAllowedApiBase('https://seeking-through-protocol.trycloudflare.com'),
        isTrue,
      );
    });

    test('rejects plain http, so the address can never be downgraded', () {
      expect(
        EndpointConfig.isAllowedApiBase('http://seeking-through-protocol.trycloudflare.com'),
        isFalse,
      );
    });

    test('rejects an arbitrary host', () {
      expect(EndpointConfig.isAllowedApiBase('https://evil.example.com'), isFalse);
    });

    test('rejects a suffix-confusion host', () {
      // trycloudflare.com appearing as a PREFIX of someone else's domain.
      expect(
        EndpointConfig.isAllowedApiBase('https://trycloudflare.com.evil.example'),
        isFalse,
      );
    });

    test('rejects the bare apex, which is not a tunnel', () {
      expect(EndpointConfig.isAllowedApiBase('https://trycloudflare.com'), isFalse);
    });

    test('rejects a path-based impersonation', () {
      expect(
        EndpointConfig.isAllowedApiBase('https://evil.example/x.trycloudflare.com'),
        isFalse,
      );
    });

    test('rejects userinfo, which some parsers read as the host', () {
      expect(
        EndpointConfig.isAllowedApiBase('https://a.trycloudflare.com@evil.example'),
        isFalse,
      );
    });

    test('rejects an explicit port', () {
      expect(
        EndpointConfig.isAllowedApiBase('https://a.trycloudflare.com:8443'),
        isFalse,
      );
    });

    test('rejects a value that is not a URL at all', () {
      expect(EndpointConfig.isAllowedApiBase('not a url'), isFalse);
      expect(EndpointConfig.isAllowedApiBase(''), isFalse);
    });
  });

  group('EndpointConfig.parse', () {
    String doc(String url, {String? at}) =>
        '{"api_base_url":"$url"${at == null ? '' : ',"generated_at":"$at"'}}';

    test('parses a valid document and strips a trailing slash', () {
      final c = EndpointConfig.parse(doc('https://abc-def.trycloudflare.com/'));
      expect(c.apiBaseUrl, 'https://abc-def.trycloudflare.com');
    });

    test('reads generated_at', () {
      final c = EndpointConfig.parse(
        doc('https://abc-def.trycloudflare.com', at: '2026-09-04T10:00:00Z'),
      );
      expect(c.generatedAt, isNotNull);
    });

    test('a disallowed host is REJECTED, distinctly from malformed', () {
      expect(
        () => EndpointConfig.parse(doc('https://evil.example')),
        throwsA(isA<EndpointConfigException>()
            .having((e) => e.failure, 'failure', EndpointFailure.configRejected),),
      );
    });

    test('invalid JSON is MALFORMED, distinctly from rejected', () {
      expect(
        () => EndpointConfig.parse('<html>404</html>'),
        throwsA(isA<EndpointConfigException>()
            .having((e) => e.failure, 'failure', EndpointFailure.configMalformed),),
      );
    });

    test('a missing address is malformed', () {
      expect(
        () => EndpointConfig.parse('{"generated_at":"2026-09-04T10:00:00Z"}'),
        throwsA(isA<EndpointConfigException>()
            .having((e) => e.failure, 'failure', EndpointFailure.configMalformed),),
      );
    });
  });

  group('staleness', () {
    test('a fresh config is not stale', () {
      final c = EndpointConfig(
        apiBaseUrl: 'https://a.trycloudflare.com',
        generatedAt: DateTime.now().toUtc(),
      );
      expect(c.isStale, isFalse);
    });

    test('an old config is stale but still carries its address', () {
      final c = EndpointConfig(
        apiBaseUrl: 'https://a.trycloudflare.com',
        generatedAt: DateTime.now().toUtc().subtract(const Duration(hours: 30)),
      );
      expect(c.isStale, isTrue);
      expect(c.apiBaseUrl, 'https://a.trycloudflare.com');
    });

    test('no generated_at means we do not claim staleness we cannot know', () {
      const c = EndpointConfig(apiBaseUrl: 'https://a.trycloudflare.com', generatedAt: null);
      expect(c.isStale, isFalse);
    });
  });
}
