import 'package:flutter_test/flutter_test.dart';
import 'package:qrivo_mobile/core/config/endpoint_config.dart';

void main() {
  _lanAndCleartextTests();
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

void _lanAndCleartextTests() {
  group('LAN addresses (hotspot path)', () {
    test('accepts the Windows Mobile Hotspot address over http', () {
      // 192.168.137.1 is where Windows always puts the hotspot host.
      expect(EndpointConfig.isAllowedApiBase('http://192.168.137.1:8000'), isTrue);
    });

    test('accepts the other RFC1918 ranges', () {
      expect(EndpointConfig.isAllowedApiBase('http://10.0.0.5:8000'), isTrue);
      expect(EndpointConfig.isAllowedApiBase('http://172.16.4.9:8000'), isTrue);
      expect(EndpointConfig.isAllowedApiBase('http://172.31.255.254:8000'), isTrue);
      expect(EndpointConfig.isAllowedApiBase('https://192.168.1.20:8000'), isTrue);
    });

    test('rejects addresses just OUTSIDE the private ranges', () {
      expect(EndpointConfig.isAllowedApiBase('http://172.15.0.1:8000'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://172.32.0.1:8000'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://11.0.0.1:8000'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://192.169.1.1:8000'), isFalse);
    });

    test('rejects loopback: on a phone that is the phone itself', () {
      expect(EndpointConfig.isAllowedApiBase('http://127.0.0.1:8000'), isFalse);
    });

    test('rejects link-local, which any device on an open network can claim', () {
      expect(EndpointConfig.isAllowedApiBase('http://169.254.1.1:8000'), isFalse);
    });
  });

  group('cleartext is confined to private addresses', () {
    // The whole point of widening the pin: it must NOT have become a general
    // permission to talk plaintext to anything.
    test('http to a public hostname is REFUSED', () {
      expect(EndpointConfig.isAllowedApiBase('http://example.com'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://api.qrivo.example:8000'), isFalse);
    });

    test('http to a PUBLIC IP literal is REFUSED', () {
      expect(EndpointConfig.isAllowedApiBase('http://8.8.8.8:8000'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://93.184.216.34'), isFalse);
    });

    test('http to the tunnel domain is REFUSED - the tunnel is https only', () {
      expect(EndpointConfig.isAllowedApiBase('http://abc-def.trycloudflare.com'), isFalse);
    });

    test('a private-looking host NAME is not a private address', () {
      // Only an IP literal counts. A hostname could resolve anywhere.
      expect(EndpointConfig.isAllowedApiBase('http://192.168.1.1.evil.example'), isFalse);
      expect(EndpointConfig.isAllowedApiBase('http://localhost:8000'), isFalse);
    });

    test('octet tricks do not sneak past the parser', () {
      // Leading zeros and hex are classic bypasses for naive IP checks.
      expect(EndpointConfig.isPrivateIPv4('010.0.0.1'), isFalse);
      expect(EndpointConfig.isPrivateIPv4('0xC0.0xA8.0.1'), isFalse);
      expect(EndpointConfig.isPrivateIPv4('192.168.1'), isFalse);
      expect(EndpointConfig.isPrivateIPv4('192.168.1.256'), isFalse);
      expect(EndpointConfig.isPrivateIPv4('192.168.1.1.1'), isFalse);
      expect(EndpointConfig.isPrivateIPv4(''), isFalse);
    });

    test('userinfo confusion still fails with a private-looking prefix', () {
      expect(
        EndpointConfig.isAllowedApiBase('http://192.168.137.1@evil.example'),
        isFalse,
      );
    });
  });

  group('parse accepts a LAN document', () {
    test('a published LAN address is parsed and kept', () {
      final c = EndpointConfig.parse('{"api_base_url":"http://192.168.137.1:8000"}');
      expect(c.apiBaseUrl, 'http://192.168.137.1:8000');
    });

    test('a published PUBLIC http address is rejected, not merely ignored', () {
      expect(
        () => EndpointConfig.parse('{"api_base_url":"http://evil.example"}'),
        throwsA(isA<EndpointConfigException>()
            .having((e) => e.failure, 'failure', EndpointFailure.configRejected),),
      );
    });
  });
}
