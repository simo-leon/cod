<?php

namespace App\Cod\Confirmation;

/**
 * Confirmation settings and rules for the COD System.
 *
 * Phase 1 only exposes the settings that already exist in the admin
 * configuration (Admin → Configuration → General → COD System). The
 * confirmation queue, assignment and confirmation actions themselves
 * arrive in later phases.
 */
interface ConfirmationServiceInterface
{
    /**
     * Maximum number of orders one confirmer may handle per day.
     * Config: general.cod_system.settings.confirmer_daily_limit (default 50).
     */
    public function confirmerDailyLimit(): int;

    /**
     * Whether orders are assigned to confirmers automatically.
     * Config: general.cod_system.settings.auto_assignment (default false
     * until phase 3, when automatic distribution is built).
     */
    public function isAutoAssignmentEnabled(): bool;
}
