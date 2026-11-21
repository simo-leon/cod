<?php

namespace App\Cod\Analytics;

use App\Cod\Confirmation\AssignmentServiceInterface;
use App\Cod\Delivery\DeliveryServiceInterface;
use App\Cod\Orders\OrderServiceInterface;
use App\Models\CodOrder;
use Webkul\User\Models\Admin;

class AnalyticsService implements AnalyticsServiceInterface
{
    /**
     * A confirmed order is one that left pending confirmation towards
     * delivery (or came back): confirmed, sent to delivery, delivered
     * or returned. A refused order becomes canceled and counts as
     * assigned but not confirmed.
     */
    public const CONFIRMED_STATUSES = [
        CodOrder::STATUS_CONFIRMED,
        CodOrder::STATUS_SENT_TO_DELIVERY,
        CodOrder::STATUS_DELIVERED,
        CodOrder::STATUS_RETURNED,
    ];

    public function __construct(
        protected OrderServiceInterface $orders,
        protected DeliveryServiceInterface $delivery,
        protected AssignmentServiceInterface $assignment,
    ) {}

    /**
     * Total number of orders in the database.
     */
    public function ordersCount(): int
    {
        return $this->orders->ordersCount();
    }

    /**
     * Number of parcels recorded in delivery_parcels, or null when the
     * table does not exist yet.
     */
    public function parcelsCount(): ?int
    {
        return $this->delivery->parcelsCount();
    }

    /**
     * Configured name of the COD System (Admin → Configuration → General
     * → COD System), default "COD System".
     */
    public function systemName(): string
    {
        $name = core()->getConfigData('general.cod_system.settings.system_name');

        return $name ? (string) $name : 'COD System';
    }

    /**
     * Confirmation and delivery rates of one confirmer:
     * ['admin', 'assigned', 'confirmed', 'delivered',
     * 'confirmation_rate', 'delivery_rate']. A rate is null when its
     * denominator is zero (no assigned orders, or no confirmed order).
     */
    public function confirmerRate(Admin $admin): array
    {
        $assigned = CodOrder::query()->where('assigned_to', $admin->id)->count();

        $confirmed = CodOrder::query()
            ->where('assigned_to', $admin->id)
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->count();

        $delivered = CodOrder::query()
            ->where('assigned_to', $admin->id)
            ->where('status', CodOrder::STATUS_DELIVERED)
            ->count();

        return [
            'admin' => $admin,
            'assigned' => $assigned,
            'confirmed' => $confirmed,
            'delivered' => $delivered,
            'confirmation_rate' => $assigned > 0 ? round($confirmed / $assigned * 100, 1) : null,
            'delivery_rate' => $confirmed > 0 ? round($delivered / $confirmed * 100, 1) : null,
        ];
    }

    /**
     * The same rates for every confirmer, ordered by name.
     */
    public function confirmerRates(): array
    {
        return $this->assignment->confirmers()
            ->map(fn (Admin $admin) => $this->confirmerRate($admin))
            ->all();
    }
}
