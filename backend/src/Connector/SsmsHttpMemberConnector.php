<?php
declare(strict_types=1);

namespace QidusAsset\Connector;

use RuntimeException;

/**
 * SSMS HTTP Connector: fetches members/tombstones from SSMS `/api/v1/sync` over HTTPS
 * and caches them in `qa_member_sync_cache` for offline-resilient reads.
 */
final class SsmsHttpMemberConnector extends StandaloneMemberConnector
{
    public function mode(): string
    {
        return 'ssms_http';
    }

    public function syncChanges(?string $cursor, int $limit = 200): array
    {
        $baseUrl = rtrim((string)qa_config('ssms.base_url', ''), '/');
        $serviceToken = (string)qa_config('ssms.service_token', '');
        $timeout = (int)qa_config('ssms.timeout_seconds', 10);

        if ($baseUrl === '' || $serviceToken === '') {
            return parent::syncChanges($cursor, $limit);
        }

        $query = http_build_query([
            'since' => $cursor ?? '0',
            'limit' => min(500, max(1, $limit)),
            'entities' => 'students,users',
        ]);
        $url = $baseUrl . '/api/v1/sync?' . $query;

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$serviceToken}\r\nAccept: application/json\r\n",
                'timeout' => max(3, $timeout),
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException('SSMS HTTP connector unreachable.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            throw new RuntimeException('SSMS HTTP connector returned invalid envelope.');
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

        $normalized = [];
        foreach ($changes as $change) {
            $entity = (string)($change['entity_type'] ?? 'student');
            $entityId = (string)($change['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }
            $op = (string)($change['operation'] ?? 'upsert');
            $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
            $prefix = $entity === 'user' ? 'ssms_usr_' : 'ssms_stu_';
            $isTomb = ($op === 'delete' || $op === 'tombstone');
            $normalized[] = [
                'external_member_id' => $prefix . $entityId,
                'member_code' => (string)($payload['student_code'] ?? ($prefix . $entityId)),
                'name_en' => (string)($payload['full_name'] ?? $payload['username'] ?? ($prefix . $entityId)),
                'name_am' => $payload['amharic_name'] ?? null,
                'department_name' => $payload['department'] ?? null,
                'class_name' => $payload['class_name'] ?? null,
                'section_name' => $payload['section'] ?? null,
                'source_status' => $isTomb ? 'archived' : (string)($payload['status'] ?? 'active'),
                'is_tombstoned' => $isTomb,
                'last_sync_revision' => (int)($change['id'] ?? 0),
            ];
        }

        if ($normalized !== []) {
            $this->upsertBatch($normalized, 'ssms_http');
        }

        return parent::syncChanges($cursor, $limit);
    }
}
