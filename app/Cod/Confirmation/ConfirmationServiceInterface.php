<?php

namespace App\Cod\Confirmation;

/**
 * Confirmation settings and rules for the COD System.
 *
 * Phase 1 only exposes the settings that already exist in the admin
 * configuration (Admin → Configuration → General → COD System). The
 * assignment itself lives in the AssignmentService (phase 3) and the
 * call outcomes in the CallLogService (phase 3).
 */
interface ConfirmationServiceInterface
{
    /**
     * Maximum number of orders one confirmer may handle per day.
     * Config: general.cod_system.settings.confirmer_daily_limit (default 50).
     */
    public function confirmerDailyLimit(): int;

    /**
     * Whether orders are assigned to confirmers automatically on
     * import. Config: general.cod_system.settings.auto_assignment
     * (default false).
     */
    public function isAutoAssignmentEnabled(): bool;
}
