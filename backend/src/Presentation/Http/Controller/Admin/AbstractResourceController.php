<?php

declare(strict_types=1);

namespace QRIVO\Presentation\Http\Controller\Admin;

use QRIVO\Application\Service\Academic\AbstractAcademicService;
use QRIVO\Application\Service\SecurityLogService;
use QRIVO\Domain\Enum\Permission;
use QRIVO\Domain\Exception\NotFoundException;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\ReferenceRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;
use QRIVO\Presentation\Http\BaseController;
use QRIVO\Presentation\Http\Request;
use QRIVO\Presentation\Http\Response\JsonResponse;

/**
 * Shared REST controller for the admin academic-structure resources.
 *
 * Exposes the standard 5 actions:
 *   GET    /...            index   (paginated, filterable)
 *   GET    /...\/{id}       show
 *   POST   /...            store
 *   PATCH  /...\/{id}       update
 *   DELETE /...\/{id}       destroy
 *
 * Every action:
 *   1. authenticates the bearer token (server-side, DB-checked)
 *   2. requires the resource's manage permission (RBAC — ADMIN / SUPER_ADMIN)
 *   3. delegates to the service
 *
 * Frontend visibility is never trusted — the permission check here is the gate.
 */
abstract class AbstractResourceController extends BaseController
{
    /** Permission required for every action on this resource. */
    abstract protected function managePermission(): Permission;

    abstract protected function makeService(): AbstractAcademicService;

    public function index(Request $request): JsonResponse
    {
        $actor  = $this->guard($request);
        $result = $this->makeService()->list($request->getQuery(), $actor);

        return JsonResponse::paginated($result['data'], $result['meta']);
    }

    public function show(Request $request): JsonResponse
    {
        $actor = $this->guard($request);

        return $this->success($this->makeService()->get($this->routeId($request), $actor));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->guard($request);

        return $this->created($this->makeService()->create($request->getBody(), $actor));
    }

    public function update(Request $request): JsonResponse
    {
        $actor = $this->guard($request);

        return $this->success(
            $this->makeService()->update($this->routeId($request), $request->getBody(), $actor),
            'Updated.',
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $actor = $this->guard($request);
        $this->makeService()->delete($this->routeId($request), $actor);

        return $this->success(null, 'Deleted.');
    }

    // ─── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed> actor context
     */
    protected function guard(Request $request): array
    {
        $actor = $this->authenticate($request);
        $this->authorization()->requirePermission($actor, $this->managePermission(), 'manage this resource');

        return $actor;
    }

    protected function routeId(Request $request): int
    {
        $id = $request->param('id');
        if (!is_numeric($id) || (int) $id < 1) {
            throw new NotFoundException('Resource not found.');
        }

        return (int) $id;
    }

    protected function securityLog(): SecurityLogService
    {
        return new SecurityLogService(
            $this->logger,
            new SecurityEventRepository($this->db),
            new AuditLogRepository($this->db),
        );
    }

    protected function reference(): ReferenceRepository
    {
        return new ReferenceRepository($this->db);
    }
}
