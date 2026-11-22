<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * COD System roles and test admins (phase 1, assignment in phase 3).
 *
 * Roles (permission_type "custom", permissions are Bagisto ACL keys):
 * - Confirmation Supervisor (مشرف تأكيد): every COD module except settings,
 *   plus `cod.assignment` to manage the distribution rules (phase 3).
 * - Confirmer (مؤكِّد): COD dashboard, orders and confirmation only,
 *   plus `cod.analytics` — but only their own numbers: the analytics
 *   page filters every figure to the orders assigned to them (phase 7
 *   fix), so the permission grants the page, not the whole team's data.
 * - Delivery Manager (مسؤول توصيل): COD dashboard, orders and delivery only.
 *
 * The `cod` key guards the COD dashboard page (route admin.cod.index);
 * the module keys (cod.orders, cod.confirmation, cod.delivery,
 * cod.analytics, cod.settings) have no pages yet — their routes arrive
 * in later phases and the Bouncer enforces them automatically then.
 *
 * Test admins use the same password pattern as the default admin
 * (admin@example.com / admin123). These are local test accounts only.
 *
 * Idempotent: safe to run repeatedly, never touches the existing
 * administrator role or the admin@example.com account.
 */
class CodRolesSeeder extends Seeder
{
    /**
     * Test password for the COD test admins (same pattern as admin123).
     */
    public const TEST_PASSWORD = 'admin123';

    /**
     * Seed the COD roles and test admins.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Confirmation Supervisor',
                'description' => 'مشرف تأكيد — supervises order confirmation for the COD System.',
                'permissions' => [
                    'dashboard',
                    'cod',
                    'cod.orders',
                    'cod.confirmation',
                    'cod.assignment',
                    'cod.delivery',
                    'cod.analytics',
                ],
                'admins' => [
                    [
                        'name' => 'Confirmation Supervisor',
                        'email' => 'confirmation-supervisor@example.com',
                    ],
                ],
            ],
            [
                'name' => 'Confirmer',
                'description' => 'مؤكِّد — confirms cash-on-delivery orders.',
                'permissions' => [
                    'dashboard',
                    'cod',
                    'cod.orders',
                    'cod.confirmation',
                    'cod.analytics',
                ],
                'admins' => [
                    [
                        'name' => 'Confirmer',
                        'email' => 'confirmer@example.com',
                    ],
                ],
            ],
            [
                'name' => 'Delivery Manager',
                'description' => 'مسؤول توصيل — manages delivery of confirmed orders.',
                'permissions' => [
                    'dashboard',
                    'cod',
                    'cod.orders',
                    'cod.delivery',
                ],
                'admins' => [
                    [
                        'name' => 'Delivery Manager',
                        'email' => 'delivery-manager@example.com',
                    ],
                ],
            ],
        ];

        foreach ($roles as $roleData) {
            $admins = $roleData['admins'];
            unset($roleData['admins']);

            $role = Role::updateOrCreate(
                ['name' => $roleData['name']],
                [
                    'description' => $roleData['description'],
                    'permission_type' => 'custom',
                    'permissions' => $roleData['permissions'],
                ]
            );

            foreach ($admins as $adminData) {
                Admin::updateOrCreate(
                    ['email' => $adminData['email']],
                    [
                        'name' => $adminData['name'],
                        'password' => Hash::make(self::TEST_PASSWORD),
                        'api_token' => Str::random(80),
                        'status' => 1,
                        'role_id' => $role->id,
                    ]
                );
            }
        }
    }
}
