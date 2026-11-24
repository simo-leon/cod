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
 * - Souk Buyer (مشتري السوق): COD dashboard and the souk board only —
 *   claims tasks, records the price paid in the souk, and records
 *   that the goods entered the warehouse. Cannot verify goods or
 *   record payments.
 * - Warehouse Controller (مراقب المخزن): COD dashboard, orders and
 *   the souk board — verifies received goods (accept / refuse with a
 *   reason), records payments to buyers, and cancels tasks.
 *
 * The `cod` key guards the COD dashboard page (route admin.cod.index);
 * the module keys (cod.orders, cod.confirmation, cod.analytics)
 * guard real pages; the dead keys cod.delivery and cod.settings were
 * removed (M27) — they pointed at routes that never existed.
 * cod.conversations is granted to the supervisor only: confirmers
 * work from their own orders and my-tasks, and the inbox shows every
 * customer's messages, so it is not a confirmer page.
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
                    'cod.analytics',
                    'cod.conversations',
                    'cod.souk',
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
                ],
                'admins' => [
                    [
                        'name' => 'Delivery Manager',
                        'email' => 'delivery-manager@example.com',
                    ],
                ],
            ],
            [
                'name' => 'Souk Buyer',
                'description' => 'مشتري السوق — buys products in the souk for the COD System.',
                'permissions' => [
                    'dashboard',
                    'cod',
                    'cod.souk',
                ],
                'admins' => [
                    [
                        'name' => 'Souk Buyer',
                        'email' => 'souk-buyer@example.com',
                    ],
                ],
            ],
            [
                'name' => 'Warehouse Controller',
                'description' => 'مراقب المخزن — verifies souk goods and records buyer payments.',
                'permissions' => [
                    'dashboard',
                    'cod',
                    'cod.orders',
                    'cod.souk',
                ],
                'admins' => [
                    [
                        'name' => 'Warehouse Controller',
                        'email' => 'warehouse-controller@example.com',
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
