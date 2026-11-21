<?php

namespace Webkul\Admin\DataGrids\Sales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Repositories\OrderRepository;

class OrderDataGrid extends DataGrid
{
    /**
     * Prepare query builder.
     *
     * @return Builder
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('orders')
            ->leftJoin('addresses as order_address_shipping', function ($leftJoin) {
                $leftJoin->on('order_address_shipping.order_id', '=', 'orders.id')
                    ->where('order_address_shipping.address_type', OrderAddress::ADDRESS_TYPE_SHIPPING);
            })
            ->leftJoin('addresses as order_address_billing', function ($leftJoin) {
                $leftJoin->on('order_address_billing.order_id', '=', 'orders.id')
                    ->where('order_address_billing.address_type', OrderAddress::ADDRESS_TYPE_BILLING);
            })
            ->leftJoin('order_payment', 'orders.id', '=', 'order_payment.order_id')
            ->select(
                'orders.id',
                DB::raw('GROUP_CONCAT('.DB::getTablePrefix().'order_payment.method SEPARATOR "|") as method'),
                'orders.increment_id',
                'orders.base_grand_total',
                'orders.created_at',
                'channel_name',
                'channel_id',
                'status',
                'customer_email',
                'orders.cart_id as items',
                DB::raw('CONCAT('.DB::getTablePrefix().'orders.customer_first_name, " ", '.DB::getTablePrefix().'orders.customer_last_name) as full_name'),
                DB::raw('CONCAT('.DB::getTablePrefix().'order_address_billing.city, ", ", '.DB::getTablePrefix().'order_address_billing.state,", ", '.DB::getTablePrefix().'order_address_billing.country) as location'),
                'order_address_billing.phone as phone',
                'order_address_billing.address as address',
                'order_address_billing.city as city'
            )
            ->groupBy('orders.id');

        $this->addFilter('full_name', DB::raw('CONCAT('.DB::getTablePrefix().'orders.customer_first_name, " ", '.DB::getTablePrefix().'orders.customer_last_name)'));
        $this->addFilter('created_at', 'orders.created_at');
        $this->addFilter('phone', 'order_address_billing.phone');

        return $queryBuilder;
    }

    /**
     * Add columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index' => 'increment_id',
            'label' => trans('admin::app.sales.orders.index.datagrid.order-id'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'status',
            'label' => trans('admin::app.sales.orders.index.datagrid.status'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'filterable_type' => 'dropdown',
            'filterable_options' => [
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.pending-confirmation'),
                    'value' => Order::STATUS_PENDING_CONFIRMATION,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.confirmed'),
                    'value' => Order::STATUS_CONFIRMED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.call-1-failed'),
                    'value' => Order::STATUS_CALL_1_FAILED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.call-2-failed'),
                    'value' => Order::STATUS_CALL_2_FAILED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.call-3-failed'),
                    'value' => Order::STATUS_CALL_3_FAILED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.out-for-delivery'),
                    'value' => Order::STATUS_OUT_FOR_DELIVERY,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.delivered'),
                    'value' => Order::STATUS_DELIVERED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.paid'),
                    'value' => Order::STATUS_PAID,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.returned'),
                    'value' => Order::STATUS_RETURNED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.canceled'),
                    'value' => Order::STATUS_CANCELED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.processing'),
                    'value' => Order::STATUS_PROCESSING,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.completed'),
                    'value' => Order::STATUS_COMPLETED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.closed'),
                    'value' => Order::STATUS_CLOSED,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.pending'),
                    'value' => Order::STATUS_PENDING,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.pending-payment'),
                    'value' => Order::STATUS_PENDING_PAYMENT,
                ],
                [
                    'label' => trans('admin::app.sales.orders.index.datagrid.fraud'),
                    'value' => Order::STATUS_FRAUD,
                ],
            ],
            'sortable' => true,
            'closure' => function ($row) {
                $statusColors = [
                    Order::STATUS_PENDING_CONFIRMATION => 'bg-yellow-500',
                    Order::STATUS_CONFIRMED => 'bg-blue-600',
                    Order::STATUS_CALL_1_FAILED => 'bg-amber-500',
                    Order::STATUS_CALL_2_FAILED => 'bg-orange-600',
                    Order::STATUS_CALL_3_FAILED => 'bg-red-600',
                    Order::STATUS_OUT_FOR_DELIVERY => 'bg-purple-600',
                    Order::STATUS_DELIVERED => 'bg-teal-600',
                    Order::STATUS_PAID => 'bg-emerald-600',
                    Order::STATUS_RETURNED => 'bg-rose-700',
                    Order::STATUS_PROCESSING => 'bg-cyan-600',
                    Order::STATUS_COMPLETED => 'bg-green-600',
                    Order::STATUS_CANCELED => 'bg-stone-500',
                    Order::STATUS_CLOSED => 'bg-slate-600',
                    Order::STATUS_PENDING => 'bg-sky-500',
                    Order::STATUS_PENDING_PAYMENT => 'bg-pink-600',
                    Order::STATUS_FRAUD => 'bg-red-800',
                ];

                $statusLabels = [
                    Order::STATUS_PENDING_CONFIRMATION => trans('admin::app.sales.orders.index.datagrid.pending-confirmation'),
                    Order::STATUS_CONFIRMED => trans('admin::app.sales.orders.index.datagrid.confirmed'),
                    Order::STATUS_CALL_1_FAILED => trans('admin::app.sales.orders.index.datagrid.call-1-failed'),
                    Order::STATUS_CALL_2_FAILED => trans('admin::app.sales.orders.index.datagrid.call-2-failed'),
                    Order::STATUS_CALL_3_FAILED => trans('admin::app.sales.orders.index.datagrid.call-3-failed'),
                    Order::STATUS_OUT_FOR_DELIVERY => trans('admin::app.sales.orders.index.datagrid.out-for-delivery'),
                    Order::STATUS_DELIVERED => trans('admin::app.sales.orders.index.datagrid.delivered'),
                    Order::STATUS_PAID => trans('admin::app.sales.orders.index.datagrid.paid'),
                    Order::STATUS_RETURNED => trans('admin::app.sales.orders.index.datagrid.returned'),
                    Order::STATUS_PROCESSING => trans('admin::app.sales.orders.index.datagrid.processing'),
                    Order::STATUS_COMPLETED => trans('admin::app.sales.orders.index.datagrid.completed'),
                    Order::STATUS_CANCELED => trans('admin::app.sales.orders.index.datagrid.canceled'),
                    Order::STATUS_CLOSED => trans('admin::app.sales.orders.index.datagrid.closed'),
                    Order::STATUS_PENDING => trans('admin::app.sales.orders.index.datagrid.pending'),
                    Order::STATUS_PENDING_PAYMENT => trans('admin::app.sales.orders.index.datagrid.pending-payment'),
                    Order::STATUS_FRAUD => trans('admin::app.sales.orders.index.datagrid.fraud'),
                ];

                $color = $statusColors[$row->status] ?? 'bg-gray-500';

                $label = $statusLabels[$row->status] ?? $row->status;

                return '<p class="text-[12px] text-white font-semibold py-px px-2 max-w-max rounded-[35px] '.$color.'">'.$label.'</p>';
            },
        ]);

        $this->addColumn([
            'index' => 'base_grand_total',
            'label' => trans('admin::app.sales.orders.index.datagrid.grand-total'),
            'type' => 'string',
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'method',
            'label' => trans('admin::app.sales.orders.index.datagrid.pay-via'),
            'type' => 'string',
            'closure' => function ($row) {
                return collect(explode('|', $row->method))
                    ->map(fn ($method) => core()->getConfigData('sales.payment_methods.'.$method.'.title'))
                    ->filter()
                    ->unique()
                    ->join(', ');
            },
        ]);

        $this->addColumn([
            'index' => 'channel_id',
            'label' => trans('admin::app.sales.orders.index.datagrid.channel-name'),
            'type' => 'string',
            'filterable' => true,
            'filterable_type' => 'dropdown',
            'filterable_options' => core()->getAllChannels()
                ->map(fn ($channel) => ['label' => $channel->name, 'value' => $channel->id])
                ->values()
                ->toArray(),
        ]);

        $this->addColumn([
            'index' => 'full_name',
            'label' => trans('admin::app.sales.orders.index.datagrid.customer'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'phone',
            'label' => trans('admin::app.sales.orders.index.datagrid.phone'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        /**
         * Searchable dropdown sample. In testing phase.
         */
        $this->addColumn([
            'index' => 'customer_email',
            'label' => trans('admin::app.sales.orders.index.datagrid.email'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        $this->addColumn([
            'index' => 'location',
            'label' => trans('admin::app.sales.orders.index.datagrid.location'),
            'type' => 'string',
        ]);

        $this->addColumn([
            'index' => 'items',
            'label' => trans('admin::app.sales.orders.index.datagrid.items'),
            'type' => 'string',
            'exportable' => false,
            'closure' => function ($value) {
                $order = app(OrderRepository::class)->with('items')->find($value->id);

                return view('admin::sales.orders.items', compact('order'))->render();
            },
        ]);

        $this->addColumn([
            'index' => 'whatsapp_confirm',
            'label' => trans('admin::app.sales.orders.index.datagrid.whatsapp-confirm'),
            'type' => 'string',
            'exportable' => false,
            'closure' => function ($row) {
                return $this->getWhatsAppConfirmationUrl($row);
            },
        ]);

        $this->addColumn([
            'index' => 'created_at',
            'label' => trans('admin::app.sales.orders.index.datagrid.date'),
            'type' => 'date',
            'filterable' => true,
            'filterable_type' => 'date_range',
            'sortable' => true,
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('sales.orders.view')) {
            $this->addAction([
                'icon' => 'icon-view',
                'title' => trans('admin::app.sales.orders.index.datagrid.view'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.sales.orders.view', $row->id);
                },
            ]);
        }
    }

    /**
     * Prepare mass actions.
     *
     * @return void
     */
    public function prepareMassActions()
    {
        if (bouncer()->hasPermission('sales.orders')) {
            $this->addMassAction([
                'title' => trans('admin::app.sales.orders.index.datagrid.update-status'),
                'url' => route('admin.sales.orders.mass_update'),
                'method' => 'POST',
                'options' => [
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.pending-confirmation'),
                        'value' => Order::STATUS_PENDING_CONFIRMATION,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.confirmed'),
                        'value' => Order::STATUS_CONFIRMED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.call-1-failed'),
                        'value' => Order::STATUS_CALL_1_FAILED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.call-2-failed'),
                        'value' => Order::STATUS_CALL_2_FAILED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.call-3-failed'),
                        'value' => Order::STATUS_CALL_3_FAILED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.out-for-delivery'),
                        'value' => Order::STATUS_OUT_FOR_DELIVERY,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.delivered'),
                        'value' => Order::STATUS_DELIVERED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.paid'),
                        'value' => Order::STATUS_PAID,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.returned'),
                        'value' => Order::STATUS_RETURNED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.canceled'),
                        'value' => Order::STATUS_CANCELED,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.processing'),
                        'value' => Order::STATUS_PROCESSING,
                    ],
                    [
                        'label' => trans('admin::app.sales.orders.index.datagrid.completed'),
                        'value' => Order::STATUS_COMPLETED,
                    ],
                ],
            ]);
        }
    }

    /**
     * Build the WhatsApp confirmation URL for an order, using the
     * admin-configured message template.
     */
    protected function getWhatsAppConfirmationUrl($row): string
    {
        $phone = $this->normalizePhone($row->phone ?? null);

        if (! $phone) {
            return '';
        }

        $order = app(OrderRepository::class)->with('items')->find($row->id);

        if (! $order) {
            return '';
        }

        $products = $order->items->map(fn ($item) => $item->name)->filter()->unique()->join(', ');

        $template = core()->getConfigData('general.general.whatsapp.admin_confirmation_message');

        if (! $template) {
            $template = 'السلام عليكم {name}، معك {store}. بغيت نأكد معك الطلب ديالك رقم {order_id}: {products} — المجموع {total}. واش نأكدو؟';
        }

        $message = str_replace(
            ['{order_id}', '{name}', '{total}', '{products}', '{store}'],
            [
                $row->increment_id,
                trim((string) $row->full_name),
                core()->formatPrice((float) $row->base_grand_total),
                $products,
                $row->channel_name ?? '',
            ],
            $template
        );

        return 'https://api.whatsapp.com/send?phone='.$phone.'&text='.rawurlencode($message);
    }

    /**
     * Normalize a Moroccan phone number to international format (212...).
     */
    protected function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);

        if (str_starts_with($digits, '212')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '212'.substr($digits, 1);
        }

        return $digits;
    }
}
