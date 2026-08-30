<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http;

use PHPUnit\Framework\TestCase;
use QRIVO\Presentation\Http\Request;

/**
 * Tests for the HTTP Request value object.
 */
final class RequestTest extends TestCase
{
    private function makeRequest(
        string $method  = 'GET',
        string $uri     = '/api/v1/health',
        array  $query   = [],
        array  $body    = [],
        array  $headers = [],
        array  $server  = [],
        array  $params  = [],
    ): Request {
        return new Request($method, $uri, $query, $body, $headers, $server, $params);
    }

    public function test_getMethod_returns_method(): void
    {
        $request = $this->makeRequest('POST');
        $this->assertSame('POST', $request->getMethod());
    }

    public function test_getUri_returns_uri(): void
    {
        $request = $this->makeRequest('GET', '/api/v1/health');
        $this->assertSame('/api/v1/health', $request->getUri());
    }

    public function test_getQuery_returns_query_array(): void
    {
        $request = $this->makeRequest(query: ['page' => '1', 'limit' => '20']);
        $this->assertSame(['page' => '1', 'limit' => '20'], $request->getQuery());
    }

    public function test_query_returns_single_param_with_default(): void
    {
        $request = $this->makeRequest(query: ['page' => '2']);
        $this->assertSame('2', $request->query('page'));
        $this->assertNull($request->query('missing'));
        $this->assertSame('1', $request->query('missing', '1'));
    }

    public function test_getBody_returns_body_array(): void
    {
        $request = $this->makeRequest(body: ['email' => 'test@example.com', 'password' => 'secret']);
        $this->assertSame(['email' => 'test@example.com', 'password' => 'secret'], $request->getBody());
    }

    public function test_input_returns_single_field_with_default(): void
    {
        $request = $this->makeRequest(body: ['email' => 'test@example.com']);
        $this->assertSame('test@example.com', $request->input('email'));
        $this->assertNull($request->input('missing'));
        $this->assertSame('default', $request->input('missing', 'default'));
    }

    public function test_getHeader_returns_lowercase_header(): void
    {
        $request = $this->makeRequest(headers: ['content-type' => 'application/json']);
        $this->assertSame('application/json', $request->getHeader('content-type'));
        $this->assertSame('application/json', $request->getHeader('Content-Type'));
    }

    public function test_getHeader_returns_null_for_missing_header(): void
    {
        $request = $this->makeRequest();
        $this->assertNull($request->getHeader('x-missing'));
    }

    public function test_getHeaders_returns_all_headers(): void
    {
        $headers = ['content-type' => 'application/json', 'authorization' => 'Bearer token123'];
        $request = $this->makeRequest(headers: $headers);
        $this->assertSame($headers, $request->getHeaders());
    }

    public function test_getParams_returns_route_params(): void
    {
        $request = $this->makeRequest(params: ['id' => '42']);
        $this->assertSame(['id' => '42'], $request->getParams());
    }

    public function test_param_returns_single_route_param_with_default(): void
    {
        $request = $this->makeRequest(params: ['id' => '42']);
        $this->assertSame('42', $request->param('id'));
        $this->assertNull($request->param('missing'));
        $this->assertSame('0', $request->param('missing', '0'));
    }

    public function test_withParams_returns_new_instance_with_params(): void
    {
        $request  = $this->makeRequest();
        $request2 = $request->withParams(['id' => '99']);

        $this->assertNotSame($request, $request2);
        $this->assertEmpty($request->getParams());
        $this->assertSame(['id' => '99'], $request2->getParams());
    }

    public function test_isMethod_is_case_insensitive(): void
    {
        $request = $this->makeRequest('POST');
        $this->assertTrue($request->isMethod('POST'));
        $this->assertTrue($request->isMethod('post'));
        $this->assertFalse($request->isMethod('GET'));
    }

    public function test_getBearerToken_extracts_token(): void
    {
        $request = $this->makeRequest(headers: ['authorization' => 'Bearer mytoken123']);
        $this->assertSame('mytoken123', $request->getBearerToken());
    }

    public function test_getBearerToken_returns_null_when_no_auth_header(): void
    {
        $request = $this->makeRequest();
        $this->assertNull($request->getBearerToken());
    }

    public function test_getBearerToken_returns_null_for_non_bearer_scheme(): void
    {
        $request = $this->makeRequest(headers: ['authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertNull($request->getBearerToken());
    }

    public function test_getIp_returns_remote_addr(): void
    {
        $request = $this->makeRequest(server: ['REMOTE_ADDR' => '192.168.1.1']);
        $this->assertSame('192.168.1.1', $request->getIp());
    }

    public function test_getIp_prefers_x_forwarded_for(): void
    {
        $request = $this->makeRequest(server: [
            'REMOTE_ADDR'          => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);
        $this->assertSame('203.0.113.5', $request->getIp());
    }

    public function test_getIp_returns_fallback_when_no_server_vars(): void
    {
        $request = $this->makeRequest();
        $this->assertSame('0.0.0.0', $request->getIp());
    }

    public function test_getServer_returns_server_value(): void
    {
        $request = $this->makeRequest(server: ['REQUEST_METHOD' => 'GET']);
        $this->assertSame('GET', $request->getServer('REQUEST_METHOD'));
        $this->assertNull($request->getServer('NONEXISTENT'));
    }
}
