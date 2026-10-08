<?php

namespace App\Authorization;

use LogicException;

final class RbacCatalog
{
    public const string GUARD = 'web';

    public const array ROLES = [
        'owner', 'administrator', 'receptionist', 'cashier', 'laboratory_technician', 'viewer',
    ];

    public const array PERMISSIONS = [
        'branches.view',
        'patients.view', 'patients.create', 'patients.update', 'patients.change_status',
        'doctors.view', 'doctors.create', 'doctors.update', 'doctors.change_status',
        'laboratory_areas.view', 'laboratory_areas.create', 'laboratory_areas.update', 'laboratory_areas.change_status',
        'sample_types.view', 'sample_types.create', 'sample_types.update', 'sample_types.change_status',
        'laboratory_exams.view', 'laboratory_exams.create', 'laboratory_exams.update', 'laboratory_exams.change_status',
        'commercial_clients.view', 'commercial_clients.create', 'commercial_clients.update', 'commercial_clients.change_status',
        'price_lists.view', 'price_lists.create', 'price_lists.update', 'price_lists.change_status', 'price_lists.set_default',
        'exam_prices.view', 'exam_prices.manage',
        'commercial_price_assignments.view', 'commercial_price_assignments.manage',
        'pricing.resolve',
        'orders.view', 'orders.create', 'orders.add_exam', 'orders.remove_exam', 'orders.manage_discount', 'orders.change_status',
    ];

    private const array RECEPTIONIST_PERMISSIONS = [
        'branches.view',
        'patients.view', 'patients.create', 'patients.update', 'patients.change_status',
        'doctors.view', 'doctors.create', 'doctors.update', 'doctors.change_status',
        'laboratory_areas.view', 'sample_types.view', 'laboratory_exams.view',
        'commercial_clients.view',
        'price_lists.view', 'exam_prices.view', 'commercial_price_assignments.view', 'pricing.resolve',
        'orders.view', 'orders.create', 'orders.add_exam', 'orders.remove_exam',
    ];

    private const array CASHIER_PERMISSIONS = [
        'branches.view', 'patients.view', 'doctors.view',
        'laboratory_areas.view', 'sample_types.view', 'laboratory_exams.view',
        'commercial_clients.view',
        'price_lists.view', 'exam_prices.view', 'commercial_price_assignments.view', 'pricing.resolve',
        'orders.view', 'orders.manage_discount',
    ];

    private const array LABORATORY_TECHNICIAN_PERMISSIONS = [
        'branches.view', 'patients.view', 'doctors.view',
        'laboratory_areas.view', 'sample_types.view', 'laboratory_exams.view',
        'orders.view', 'orders.change_status',
    ];

    private const array VIEWER_PERMISSIONS = [
        'branches.view', 'patients.view', 'doctors.view',
        'laboratory_areas.view', 'sample_types.view', 'laboratory_exams.view',
        'commercial_clients.view',
        'price_lists.view', 'exam_prices.view', 'commercial_price_assignments.view', 'pricing.resolve',
        'orders.view',
    ];

    /** @return array<string, array<int, string>> */
    public static function rolePermissions(): array
    {
        return [
            'owner' => self::PERMISSIONS,
            'administrator' => self::PERMISSIONS,
            'receptionist' => self::RECEPTIONIST_PERMISSIONS,
            'cashier' => self::CASHIER_PERMISSIONS,
            'laboratory_technician' => self::LABORATORY_TECHNICIAN_PERMISSIONS,
            'viewer' => self::VIEWER_PERMISSIONS,
        ];
    }

    public static function assertValid(): void
    {
        if (count(self::PERMISSIONS) !== 41 || count(array_unique(self::PERMISSIONS)) !== 41) {
            throw new LogicException('The RBAC catalog must contain exactly 41 unique permissions.');
        }

        $matrix = self::rolePermissions();

        if (array_keys($matrix) !== self::ROLES) {
            throw new LogicException('The RBAC matrix must contain exactly the six ratified roles.');
        }

        foreach ($matrix as $role => $permissions) {
            if (array_diff($permissions, self::PERMISSIONS) !== []) {
                throw new LogicException("The {$role} role contains permissions outside the RBAC catalog.");
            }
        }
    }
}
