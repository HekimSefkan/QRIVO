<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller;

use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Health check controller.
 *
 * Provides a system health endpoint for monitoring.
 * Route: GET /api/v1/health
 */
final class HealthController extends BaseController
{
    public function check(Request $request): JsonResponse
    {
        $dbHealthy = $this->db->isConnected();

        $status = [
            'api'      => 'ok',
            'database' => $dbHealthy ? 'ok' : 'unavailable',
            'version'  => '1.0.0',
            'env'      => $this->config->getString('app.env', 'production'),
        ];

        $httpStatus = $dbHealthy ? 200 : 503;

        return JsonResponse::success($status, 'QRIVO API is running.', $httpStatus);
    }
}
