<?php

declare(strict_types=1);

namespace QRIVO\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\TestCase;
use QRIVO\Domain\Exception\ConflictException;
use QRIVO\Domain\Exception\DomainException;
use QRIVO\Domain\Exception\ForbiddenException;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Domain\Exception\TooManyRequestsException;
use QRIVO\Domain\Exception\UnauthorizedException;
use QRIVO\Domain\Exception\ValidationException;

/**
 * Tests for all Domain exception classes.
 */
final class DomainExceptionTest extends TestCase
{
    public function test_unauthorized_exception_has_code_401(): void
    {
        $e = new UnauthorizedException();
        $this->assertSame(401, $e->getCode());
        $this->assertSame('Authentication required.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_unauthorized_exception_custom_message(): void
    {
        $e = new UnauthorizedException('Token expired.');
        $this->assertSame('Token expired.', $e->getMessage());
        $this->assertSame(401, $e->getCode());
    }

    public function test_forbidden_exception_has_code_403(): void
    {
        $e = new ForbiddenException();
        $this->assertSame(403, $e->getCode());
        $this->assertSame('Access denied.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_forbidden_exception_custom_message(): void
    {
        $e = new ForbiddenException('You do not own this resource.');
        $this->assertSame('You do not own this resource.', $e->getMessage());
    }

    public function test_not_found_exception_has_code_404(): void
    {
        $e = new NotFoundException();
        $this->assertSame(404, $e->getCode());
        $this->assertSame('Resource not found.', $e->getMessage());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_not_found_exception_custom_message(): void
    {
        $e = new NotFoundException('User not found.');
        $this->assertSame('User not found.', $e->getMessage());
    }

    public function test_conflict_exception_has_code_409(): void
    {
        $e = new ConflictException();
        $this->assertSame(409, $e->getCode());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_conflict_exception_custom_message(): void
    {
        $e = new ConflictException('Attendance already recorded.');
        $this->assertSame('Attendance already recorded.', $e->getMessage());
    }

    public function test_validation_exception_has_code_422(): void
    {
        $errors = ['email' => ['The email field is required.']];
        $e      = new ValidationException('Validation failed.', $errors);

        $this->assertSame(422, $e->getCode());
        $this->assertSame('Validation failed.', $e->getMessage());
        $this->assertSame($errors, $e->getErrors());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_validation_exception_empty_errors(): void
    {
        $e = new ValidationException('Validation failed.');
        $this->assertSame([], $e->getErrors());
    }

    public function test_too_many_requests_exception_has_code_429(): void
    {
        $e = new TooManyRequestsException();
        $this->assertSame(429, $e->getCode());
        $this->assertInstanceOf(DomainException::class, $e);
    }

    public function test_too_many_requests_exception_custom_message(): void
    {
        $e = new TooManyRequestsException('Login rate limit exceeded.');
        $this->assertSame('Login rate limit exceeded.', $e->getMessage());
    }
}
