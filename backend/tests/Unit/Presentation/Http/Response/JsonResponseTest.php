<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Presentation\Http\Response;

use PHPUnit\Framework\TestCase;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Tests for JsonResponse.
 */
final class JsonResponseTest extends TestCase
{
    public function test_success_creates_200_response(): void
    {
        $response = JsonResponse::success(['id' => 1], 'Created OK', 200);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('Created OK', $data['message']);
        $this->assertSame(['id' => 1], $data['data']);
    }

    public function test_success_defaults(): void
    {
        $response = JsonResponse::success();
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('OK', $data['message']);
        $this->assertNull($data['data']);
    }

    public function test_error_creates_error_response(): void
    {
        $response = JsonResponse::error('Something failed.', 400);

        $this->assertSame(400, $response->getStatusCode());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Something failed.', $data['message']);
        $this->assertArrayNotHasKey('errors', $data);
    }

    public function test_error_with_errors_field(): void
    {
        $response = JsonResponse::error('Bad request.', 400, ['field' => 'error detail']);

        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame(['field' => 'error detail'], $data['errors']);
    }

    public function test_validationError_creates_422_response(): void
    {
        $errors   = ['email' => ['The email field is required.']];
        $response = JsonResponse::validationError('Validation failed.', $errors);

        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData();
        $this->assertFalse($data['success']);
        $this->assertSame('Validation failed.', $data['message']);
        $this->assertSame($errors, $data['errors']);
    }

    public function test_created_creates_201_response(): void
    {
        $response = JsonResponse::created(['id' => 42], 'Resource created.');

        $this->assertSame(201, $response->getStatusCode());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(['id' => 42], $data['data']);
    }

    public function test_created_defaults(): void
    {
        $response = JsonResponse::created();
        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_noContent_creates_204_response(): void
    {
        $response = JsonResponse::noContent();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($response->getData());
    }

    public function test_withHeaders_returns_new_instance(): void
    {
        $original = JsonResponse::success();
        $modified = $original->withHeaders(['X-Custom' => 'value']);

        $this->assertNotSame($original, $modified);
        $this->assertSame(200, $modified->getStatusCode());
    }

    public function test_error_returns_404_for_not_found(): void
    {
        $response = JsonResponse::error('Not found.', 404);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_error_returns_401_for_unauthorized(): void
    {
        $response = JsonResponse::error('Authentication required.', 401);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_error_returns_403_for_forbidden(): void
    {
        $response = JsonResponse::error('Access denied.', 403);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_error_returns_500_for_internal_server_error(): void
    {
        $response = JsonResponse::error('An internal server error occurred.', 500);
        $this->assertSame(500, $response->getStatusCode());
        $data = $response->getData();
        // Security: internal error details must NOT be exposed
        $this->assertSame('An internal server error occurred.', $data['message']);
    }
}
