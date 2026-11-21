<?php

namespace App\Cod\Confirmation;

class ConfirmationService implements ConfirmationServiceInterface
{
    /**
     * Maximum number of orders one confirmer may handle per day.
     * Config: general.cod_system.settings.confirmer_daily_limit (default 50).
     */
    public function confirmerDailyLimit(): int
    {
        return (int) (core()->getConfigData('general.cod_system.settings.confirmer_daily_limit') ?? 50);
    }

    /**
     * Whether orders are assigned to confirmers automatically.
     * Config: general.cod_system.settings.auto_assignment (default false
     * until phase 3, when automatic distribution is built).
     */
    public function isAutoAssignmentEnabled(): bool
    {
        return (bool) core()->getConfigData('general.cod_system.settings.auto_assignment');
    }
}
