<?php

namespace App\Cod\Souk;

/**
 * Souk (market sourcing) settings (Configuration → Souk,
 * general.souk.settings).
 *
 * Every value has a default here AND in the admin (system.php): the
 * defaults below are the fallback when nothing was ever saved, so
 * the panel behaves the same on a fresh install. The admin values
 * always win when they are set.
 *
 * The panel is OFF by default: nothing is assigned to the souk and
 * no task is created until the owner switches it on. The claim
 * deadline defaults to 24 hours. The payment model decides who
 * fronts the purchase money: "advance" means the store gives the
 * buyer the money before / at purchase time, "reimbursement" means
 * the buyer pays in the souk and is paid back after the goods are
 * received and verified. Either way every payment is recorded
 * explicitly in souk_payments — the model only changes the policy
 * shown on the board and the reminder on the verification form.
 */
class SoukSettings
{
    public const CONFIG_KEY = 'general.souk.settings';

    public const DEFAULT_CLAIM_TIMEOUT_HOURS = 24;

    public const PAYMENT_MODEL_ADVANCE = 'advance';

    public const PAYMENT_MODEL_REIMBURSEMENT = 'reimbursement';

    public const DEFAULT_PAYMENT_MODEL = self::PAYMENT_MODEL_REIMBURSEMENT;

    /**
     * Whether the souk panel is switched on. Default off.
     */
    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    /**
     * Hours an assigned task waits for a buyer to claim it before
     * it is escalated and expires. Default 24.
     */
    public function claimTimeoutHours(): int
    {
        return max(1, (int) (core()->getConfigData(self::CONFIG_KEY.'.claim_timeout_hours') ?? self::DEFAULT_CLAIM_TIMEOUT_HOURS));
    }

    /**
     * Hours a buyer has to record the purchase after claiming a task
     * before the claim is flagged stalled (T5). Separate from the
     * claim timeout: claiming is fast, buying in the souk takes
     * longer. Default 24.
     */
    public function purchaseTimeoutHours(): int
    {
        return max(1, (int) (core()->getConfigData(self::CONFIG_KEY.'.purchase_timeout_hours') ?? self::DEFAULT_CLAIM_TIMEOUT_HOURS));
    }

    /**
     * Default purchase-price cap per unit (DH) for products without
     * their own cap. Null means no default cap.
     */
    public function defaultPriceCap(): ?float
    {
        $cap = core()->getConfigData(self::CONFIG_KEY.'.default_price_cap');

        return $cap === null || $cap === '' ? null : max(0, (float) $cap);
    }

    /**
     * Default minimum margin (percent of the sale price) for
     * products without their own minimum. Null means no default.
     */
    public function defaultMinMarginPercent(): ?float
    {
        $margin = core()->getConfigData(self::CONFIG_KEY.'.default_min_margin_percent');

        return $margin === null || $margin === '' ? null : max(0, (float) $margin);
    }

    /**
     * Who fronts the purchase money: "advance" or "reimbursement".
     * Default reimbursement: the buyer pays in the souk and is paid
     * back after the goods are received and verified.
     */
    public function paymentModel(): string
    {
        $model = (string) (core()->getConfigData(self::CONFIG_KEY.'.payment_model') ?? '');

        return in_array($model, [self::PAYMENT_MODEL_ADVANCE, self::PAYMENT_MODEL_REIMBURSEMENT], true)
            ? $model
            : self::DEFAULT_PAYMENT_MODEL;
    }
}
