<?php
declare(strict_types=1);

namespace QidusAsset\Connector;

/**
 * Replaceable Member-System Connector Contract (PRD FR-19 / Architecture §3).
 * Members are NEVER matched by display name; `external_member_id` is immutable.
 */
interface MemberConnectorInterface
{
    public function mode(): string;

    /**
     * @return array<string,mixed>|null
     */
    public function findByExternalId(string $externalMemberId): ?array;

    /**
     * Search active (non-tombstoned) cached or source members.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function search(string $query, int $page = 1, int $limit = 20, bool $includeInactive = false): array;

    /**
     * Incremental sync pull from cursor (`since` = monotonic sync ID or ISO timestamp).
     *
     * @return array{
     *   items:list<array<string,mixed>>,
     *   tombstones:list<string>,
     *   next_cursor:string,
     *   has_more:bool
     * }
     */
    public function syncChanges(?string $cursor, int $limit = 200): array;
}
