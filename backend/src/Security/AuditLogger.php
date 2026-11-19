<?php
declare(strict_types=1);

namespace QidusAsset\Security;

use PDO;

/**
 * Append-only audit writer (`qa_audit_logs`) with optional SSMS activity_logs mirror.
 */
final class AuditLogger
{
    /**
     * @param array<string,mixed> $entry
     */
    public static function log(PDO $pdo, array $entry): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO qa_audit_logs (
                actor_user_id, actor_role, impersonated_by_user_id, department_id,
                device_uuid, device_key_id, action, entity_type, entity_id,
                before_json, after_json, evidence_sha256, outcome,
                ip_address, user_agent, device_at
            ) VALUES (
                :actor_user_id, :actor_role, :impersonated_by, :department_id,
                :device_uuid, :device_key_id, :action, :entity_type, :entity_id,
                :before_json, :after_json, :evidence_sha256, :outcome,
                :ip_address, :user_agent, :device_at
            )'
        );

        $before = isset($entry['before']) ? json_encode($entry['before'], JSON_UNESCAPED_UNICODE) : null;
        $after = isset($entry['after']) ? json_encode($entry['after'], JSON_UNESCAPED_UNICODE) : null;

        $stmt->execute([
            ':actor_user_id' => $entry['actor_user_id'] ?? null,
            ':actor_role' => (string)($entry['actor_role'] ?? 'system'),
            ':impersonated_by' => $entry['impersonated_by_user_id'] ?? null,
            ':department_id' => $entry['department_id'] ?? null,
            ':device_uuid' => $entry['device_uuid'] ?? null,
            ':device_key_id' => $entry['device_key_id'] ?? null,
            ':action' => (string)($entry['action'] ?? 'unknown'),
            ':entity_type' => (string)($entry['entity_type'] ?? 'system'),
            ':entity_id' => isset($entry['entity_id']) ? (string)$entry['entity_id'] : null,
            ':before_json' => $before,
            ':after_json' => $after,
            ':evidence_sha256' => $entry['evidence_sha256'] ?? null,
            ':outcome' => (string)($entry['outcome'] ?? 'accepted'),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            ':device_at' => $entry['device_at'] ?? null,
        ]);
    }
}
