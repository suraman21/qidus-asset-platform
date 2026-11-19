<?php
declare(strict_types=1);

namespace QidusAsset\Security;

/**
 * Centralized Server-Authoritative RBAC for Both Departments + Governance.
 *
 * Roles:
 *   Cross-cutting: system_admin, chairperson, deputy_chairperson, auditor, requester
 *   Dept 1 (Asset): asset_admin, asset_head, asset_officer, event_operator, maintenance_officer
 *   Dept 2 (Finance & Related): finance_head, finance_staff, procurement_officer, document_controller
 */
final class Rbac
{
    public const ALL_ROLES = [
        'system_admin',
        'chairperson',
        'deputy_chairperson',
        'asset_admin',
        'asset_head',
        'asset_officer',
        'event_operator',
        'maintenance_officer',
        'finance_head',
        'finance_staff',
        'procurement_officer',
        'document_controller',
        'auditor',
        'requester',
    ];

    /** @var array<string,list<string>> */
    private const PERMISSIONS = [
        'system.admin' => ['system_admin'],
        'system.device_approve' => ['system_admin', 'asset_admin'],
        'system.year_manage' => ['system_admin', 'asset_admin', 'finance_head'],
        'system.audit_read' => ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor'],
        'system.audit_finding_write' => ['auditor'],
        'system.executive_summary' => ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor'],

        // Dept 1: Asset Department
        'asset.read' => ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'asset_admin', 'asset_head', 'asset_officer', 'event_operator', 'maintenance_officer', 'procurement_officer', 'requester'],
        'asset.write' => ['system_admin', 'asset_admin', 'asset_head', 'asset_officer'],
        'asset.stock_move' => ['system_admin', 'asset_admin', 'asset_head', 'asset_officer'],
        'asset.handover' => ['system_admin', 'asset_head', 'asset_officer'],
        'asset.request_submit' => self::ALL_ROLES,
        'asset.request_dept_head' => ['system_admin', 'asset_head', 'finance_head'],
        'asset.request_deputy' => ['system_admin', 'deputy_chairperson'],
        'asset.event_manage' => ['system_admin', 'asset_admin', 'asset_head', 'asset_officer'],
        'asset.event_operate' => ['system_admin', 'asset_head', 'asset_officer', 'event_operator'],
        'asset.maintenance_write' => ['system_admin', 'asset_head', 'asset_officer', 'maintenance_officer'],
        'asset.inspection_write' => ['system_admin', 'asset_head', 'asset_officer', 'auditor'],
        'asset.disposal_request' => ['system_admin', 'asset_head', 'asset_officer'],
        'asset.disposal_approve' => ['system_admin', 'deputy_chairperson', 'chairperson'],

        // Dept 2A: Budget & Finance
        'finance.read' => ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'finance_head', 'finance_staff', 'procurement_officer'],
        'finance.budget_manage' => ['system_admin', 'finance_head'],
        'finance.budget_approve' => ['system_admin', 'deputy_chairperson', 'chairperson'],
        'finance.ledger_post' => ['system_admin', 'finance_head', 'finance_staff'],
        'finance.ledger_adjust' => ['system_admin', 'finance_head', 'deputy_chairperson'],
        'finance.reconcile' => ['system_admin', 'finance_head', 'finance_staff'],
        'finance.contributions' => ['system_admin', 'finance_head', 'finance_staff'],

        // Dept 2B: Procurement
        'procurement.request_submit' => self::ALL_ROLES,
        'procurement.stock_check' => ['system_admin', 'asset_head', 'asset_officer'],
        'procurement.manage' => ['system_admin', 'finance_head', 'procurement_officer'],
        'procurement.approve_deputy' => ['system_admin', 'deputy_chairperson', 'chairperson'],
        'procurement.receive_goods' => ['system_admin', 'asset_head', 'asset_officer', 'procurement_officer'],

        // Dept 2C: Document Management
        'document.read' => ['system_admin', 'chairperson', 'deputy_chairperson', 'auditor', 'finance_head', 'finance_staff', 'procurement_officer', 'document_controller', 'asset_head', 'asset_officer'],
        'document.upload' => ['system_admin', 'finance_head', 'finance_staff', 'procurement_officer', 'document_controller', 'asset_head', 'asset_officer', 'auditor'],
        'document.approve' => ['system_admin', 'finance_head', 'deputy_chairperson', 'chairperson'],
        'document.archive' => ['system_admin', 'finance_head', 'document_controller'],

        // Member Connector
        'members.sync_read' => ['system_admin', 'asset_admin', 'asset_head', 'asset_officer', 'event_operator', 'finance_head', 'finance_staff', 'auditor'],
    ];

    /**
     * Permissions allowed inside a signed offline lease (sensitive admin/disposal/reallocation stay online-only).
     */
    public const OFFLINE_ELIGIBLE_PERMISSIONS = [
        'asset.read',
        'asset.handover',
        'asset.request_submit',
        'asset.event_operate',
        'asset.inspection_write',
        'asset.maintenance_write',
        'members.sync_read',
    ];

    public static function can(string $role, string $permission): bool
    {
        $allowed = self::PERMISSIONS[$permission] ?? [];
        return in_array($role, $allowed, true);
    }

    /**
     * @return list<string>
     */
    public static function capabilitiesForRole(string $role): array
    {
        $caps = [];
        foreach (self::PERMISSIONS as $perm => $roles) {
            if (in_array($role, $roles, true)) {
                $caps[] = $perm;
            }
        }
        return $caps;
    }

    /**
     * @return list<string>
     */
    public static function offlineCapabilitiesForRole(string $role): array
    {
        return array_values(array_intersect(
            self::capabilitiesForRole($role),
            self::OFFLINE_ELIGIBLE_PERMISSIONS
        ));
    }

    /**
     * Enforce separation of duty: a requester cannot approve their own request
     * unless explicit system_admin emergency mode applies.
     */
    public static function assertNotSelfApproval(int $actorUserId, int $requesterUserId, string $actorRole): void
    {
        if ($actorUserId > 0 && $actorUserId === $requesterUserId && $actorRole !== 'system_admin') {
            throw new \DomainException('Separation of duty violation: requester cannot approve their own request.');
        }
    }

    /**
     * Map an SSMS session/JWT role + optional qa_user_profiles override into a QIDUS role.
     */
    public static function mapSsmsRole(string $ssmsRole, ?string $qaProfileRole = null): string
    {
        if ($qaProfileRole !== null && in_array($qaProfileRole, self::ALL_ROLES, true)) {
            return $qaProfileRole;
        }
        return match ($ssmsRole) {
            'super_admin', 'school_admin' => 'system_admin',
            'asset_dept', 'material_dept' => 'asset_head',
            'finance_dept' => 'finance_head',
            'accounts_office' => 'auditor',
            default => 'requester',
        };
    }
}
