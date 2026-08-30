<?php

declare(strict_types=1);

namespace QRIVO\Application\Service\Security;

use QRIVO\Application\Service\BaseService;
use QRIVO\Domain\Contract\LoggerInterface;
use QRIVO\Domain\Security\LogSanitizer;
use QRIVO\Infrastructure\Repository\AuditLogRepository;
use QRIVO\Infrastructure\Repository\SecurityEventRepository;

/**
 * Read side of the security-event and audit trail
 * (PROJECT_SPECIFICATION.md §6.15, SECURITY_RULES.md §10 / §11).
 *
 * Authorization is enforced by the controller (`security.event.view` /
 * `audit.log.view`). This service only shapes the rows: it decodes the JSON
 * columns and runs them through {@see LogSanitizer} once more on the way out, so
 * that even rows written before the write-side sweep existed cannot leak a
 * secret through the API.
 */
final class AuditQueryService extends BaseService
{
    private readonly LogSanitizer $sanitizer;

    public function __construct(
        LoggerInterface $logger,
        private readonly SecurityEventRepository $securityEvents,
        private readonly AuditLogRepository $auditLogs,
    ) {
        parent::__construct($logger);
        $this->sanitizer = new LogSanitizer();
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function securityEvents(array $query): array
    {
        [$page, $perPage] = $this->pagination($query);
        $result = $this->securityEvents->paginate($this->filters($query, [
            'event_type', 'severity', 'user_id', 'attendance_session_id', 'from', 'to',
        ]), $page, $perPage);

        $data = array_map(function (array $row): array {
            $row['details'] = $this->decode($row['details'] ?? null);
            return $row;
        }, $result['data']);

        return ['data' => $data, 'meta' => $this->meta($page, $perPage, $result['total'])];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function auditLogs(array $query): array
    {
        [$page, $perPage] = $this->pagination($query);
        $result = $this->auditLogs->paginate($this->filters($query, [
            'event_type', 'actor_user_id', 'target_entity', 'target_id', 'from', 'to',
        ]), $page, $perPage);

        $data = array_map(function (array $row): array {
            $row['old_value'] = $this->decode($row['old_value'] ?? null);
            $row['new_value'] = $this->decode($row['new_value'] ?? null);
            return $row;
        }, $result['data']);

        return ['data' => $data, 'meta' => $this->meta($page, $perPage, $result['total'])];
    }

    // ─── internals ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query
     * @return array{0: int, 1: int}
     */
    private function pagination(array $query): array
    {
        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(100, (int) ($query['per_page'] ?? 25))),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $allowed
     * @return array<string, mixed>
     */
    private function filters(array $query, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $out[$key] = $query[$key];
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    private function meta(int $page, int $perPage, int $total): array
    {
        return [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $this->sanitizer->sanitize($decoded) : null;
    }
}
