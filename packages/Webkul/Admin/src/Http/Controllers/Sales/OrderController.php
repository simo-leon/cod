<?php

namespace Webkul\Admin\Http\Controllers\Sales;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Webkul\Admin\DataGrids\Sales\OrderDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassUpdateRequest;
use Webkul\Admin\Http\Resources\AddressResource;
use Webkul\Admin\Http\Resources\CartResource;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Inventory\Models\InventorySource;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderCommentRepository;
use Webkul\Sales\Repositories\OrderItemRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\ShipmentRepository;
use Webkul\Sales\Transformers\OrderResource;

class OrderController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected OrderRepository $orderRepository,
        protected OrderCommentRepository $orderCommentRepository,
        protected CartRepository $cartRepository,
        protected CustomerGroupRepository $customerGroupRepository,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(OrderDataGrid::class)->process();
        }

        $channels = core()->getAllChannels();

        $groups = $this->customerGroupRepository->findWhere([['code', '<>', 'guest']]);

        return view('admin::sales.orders.index', compact('channels', 'groups'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return View
     */
    public function create(int $cartId)
    {
        $cart = $this->cartRepository->find($cartId);

        if (! $cart) {
            return redirect()->route('admin.sales.orders.index');
        }

        $addresses = AddressResource::collection($cart->customer->addresses);

        $cart = new CartResource($cart);

        return view('admin::sales.orders.create', compact('cart', 'addresses'));
    }

    /**
     * Store order
     */
    public function store(int $cartId)
    {
        $cart = $this->cartRepository->findOrFail($cartId);

        Cart::setCart($cart);

        if (Cart::hasError()) {
            return response()->json([
                'message' => trans('admin::app.sales.orders.create.error'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        Cart::collectTotals();

        try {
            $this->validateOrder();
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $cart = Cart::getCart();

        if (! in_array($cart->payment->method, ['cashondelivery', 'moneytransfer'])) {
            return response()->json([
                'message' => trans('admin::app.sales.orders.create.payment-not-supported'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = (new OrderResource($cart))->jsonSerialize();

        $order = $this->orderRepository->create($data);

        Cart::removeCart($cart);

        session()->flash('order', trans('admin::app.sales.orders.create.order-placed-success'));

        return new JsonResource([
            'redirect' => true,
            'redirect_url' => route('admin.sales.orders.view', $order->id),
        ]);
    }

    /**
     * Show the view for the specified resource.
     *
     * @return View
     */
    public function view(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);

        return view('admin::sales.orders.view', compact('order'));
    }

    /**
     * Reorder action for the specified resource.
     *
     * @return Response
     */
    public function reorder(int $id)
    {
        $order = $this->orderRepository->findOrFail($id);

        if (! $order->customer) {
            session()->flash('error', trans('admin::app.sales.orders.view.reorder-customer-missing'));

            return redirect()->route('admin.sales.orders.view', $id);
        }

        $cart = Cart::createCart([
            'customer' => $order->customer,
            'is_active' => false,
        ]);

        Cart::setCart($cart);

        $skippedBooking = false;

        foreach ($order->items as $item) {
            if ($item->type === 'booking') {
                $skippedBooking = true;

                continue;
            }

            try {
                Cart::addProduct($item->product, $item->additional);
            } catch (\Exception $e) {
                // do nothing
            }
        }

        if ($skippedBooking) {
            session()->flash('info', trans('admin::app.sales.orders.view.reorder-booking-skipped'));
        }

        return redirect()->route('admin.sales.orders.create', $cart->id);
    }

    /**
     * Update order status from the order view page.
     */
    public function updateStatus(int $id): RedirectResponse
    {
        $order = $this->orderRepository->find($id);

        if (! $order) {
            session()->flash('error', trans('admin::app.sales.orders.view.create-error'));

            return redirect()->route('admin.sales.orders.view', $id);
        }

        $lockedStatuses = [Order::STATUS_DELIVERED, Order::STATUS_CANCELED];

        if (in_array($order->status, $lockedStatuses, true)) {
            session()->flash('error', trans('admin::app.sales.orders.view.status-locked'));

            return redirect()->route('admin.sales.orders.view', $id);
        }

        $newStatus = request()->input('status');

        $allowedStatuses = [
            Order::STATUS_PENDING_CONFIRMATION,
            Order::STATUS_CONFIRMED,
            Order::STATUS_CALL_1_FAILED,
            Order::STATUS_CALL_2_FAILED,
            Order::STATUS_CALL_3_FAILED,
            Order::STATUS_OUT_FOR_DELIVERY,
            Order::STATUS_DELIVERED,
            Order::STATUS_PAID,
            Order::STATUS_RETURNED,
            Order::STATUS_CANCELED,
        ];

        if (! in_array($newStatus, $allowedStatuses, true)) {
            session()->flash('error', trans('admin::app.sales.orders.view.create-error'));

            return redirect()->route('admin.sales.orders.view', $id);
        }

        if ($order->status === $newStatus) {
            return redirect()->route('admin.sales.orders.view', $id);
        }

        $previousStatus = $order->status;

        if (in_array($newStatus, [Order::STATUS_CANCELED, Order::STATUS_RETURNED], true)) {
            $this->restoreOrderInventory($order);
        }

        if (in_array($newStatus, [Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED], true)) {
            $this->autoGenerateShipment($order);
        }

        if (in_array($newStatus, [Order::STATUS_PAID, Order::STATUS_DELIVERED], true)) {
            $this->autoGenerateInvoice($order);
        }

        Event::dispatch('sales.order.update-status.before', [$order, $newStatus, $previousStatus]);

        $order->status = $newStatus;
        $order->save();

        Event::dispatch('sales.order.update-status.after', [$order, $newStatus, $previousStatus]);

        session()->flash('success', trans('admin::app.sales.orders.view.status-updated'));

        return redirect()->route('admin.sales.orders.view', $id);
    }

    /**
     * Cancel action for the specified resource.
     *
     * @return Response
     */
    public function cancel(int $id)
    {
        $result = $this->orderRepository->cancel($id, force: true);

        if ($result) {
            session()->flash('success', trans('admin::app.sales.orders.view.cancel-success'));
        } else {
            session()->flash('error', trans('admin::app.sales.orders.view.create-error'));
        }

        return redirect()->route('admin.sales.orders.view', $id);
    }

    /**
     * Add comment to the order
     *
     * @return Response
     */
    public function comment(int $id)
    {
        $validatedData = $this->validate(request(), [
            'comment' => 'required',
            'customer_notified' => 'sometimes|sometimes',
        ]);

        $validatedData['order_id'] = $id;

        Event::dispatch('sales.order.comment.create.before');

        $comment = $this->orderCommentRepository->create($validatedData);

        Event::dispatch('sales.order.comment.create.after', $comment);

        session()->flash('success', trans('admin::app.sales.orders.view.comment-success'));

        return redirect()->route('admin.sales.orders.view', $id);
    }

    /**
     * Result of search product.
     *
     * @return JsonResponse
     */
    public function search()
    {
        $orders = $this->orderRepository->scopeQuery(function ($query) {
            return $query->where('customer_email', 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere('status', 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere(DB::raw('CONCAT(customer_first_name, " ", customer_last_name)'), 'like', '%'.urldecode(request()->input('query')).'%')
                ->orWhere('increment_id', request()->input('query'))
                ->orderBy('created_at', 'desc');
        })->paginate(10);

        foreach ($orders as $key => $order) {
            $orders[$key]['formatted_created_at'] = core()->formatDate($order->created_at, 'd M Y');

            $orders[$key]['status_label'] = $order->status_label;

            $orders[$key]['customer_full_name'] = $order->customer_full_name;
        }

        return response()->json($orders);
    }

    /**
     * Validate order before creation.
     *
     * @return void|\Exception
     */
    public function validateOrder()
    {
        $cart = Cart::getCart();

        if (! Cart::haveMinimumOrderAmount()) {
            throw new \Exception(trans('admin::app.sales.orders.create.minimum-order-error', [
                'amount' => core()->formatPrice(core()->getConfigData('sales.order_settings.minimum_order.minimum_order_amount') ?: 0),
            ]));
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->shipping_address
        ) {
            throw new \Exception(trans('admin::app.sales.orders.create.check-shipping-address'));
        }

        if (! $cart->billing_address) {
            throw new \Exception(trans('admin::app.sales.orders.create.check-billing-address'));
        }

        if (
            $cart->haveStockableItems()
            && ! $cart->selected_shipping_rate
        ) {
            throw new \Exception(trans('admin::app.sales.orders.create.specify-shipping-method'));
        }

        if (! $cart->payment) {
            throw new \Exception(trans('admin::app.sales.orders.create.specify-payment-method'));
        }
    }

    /**
     * Mass update order status.
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest): JsonResponse
    {
        $indices = $massUpdateRequest->input('indices');
        $newStatus = $massUpdateRequest->input('value');

        $lockedStatuses = [
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELED,
        ];

        $lockedOrders = [];

        foreach ($indices as $id) {
            $order = $this->orderRepository->find($id);

            if (! $order) {
                continue;
            }

            // منع تغيير الحالة إذا كانت بالفعل "تم التوصيل" أو "تم الإلغاء"
            if (in_array($order->status, $lockedStatuses, true)) {
                $lockedOrders[] = $order->increment_id ?? $order->id;

                continue;
            }

            $previousStatus = $order->status;

            if ($previousStatus === $newStatus) {
                continue;
            }

            // Restore inventory when canceling or returning if not already canceled/returned
            if (in_array($newStatus, [Order::STATUS_CANCELED, Order::STATUS_RETURNED], true)) {
                $this->restoreOrderInventory($order);
            }

            if (in_array($newStatus, [Order::STATUS_OUT_FOR_DELIVERY, Order::STATUS_DELIVERED], true)) {
                $this->autoGenerateShipment($order);
            }

            if (in_array($newStatus, [Order::STATUS_PAID, Order::STATUS_DELIVERED], true)) {
                $this->autoGenerateInvoice($order);
            }

            Event::dispatch('sales.order.update-status.before', [$order, $newStatus, $previousStatus]);

            $order->status = $newStatus;
            $order->save();

            Event::dispatch('sales.order.update-status.after', [$order, $newStatus, $previousStatus]);
        }

        if (! empty($lockedOrders)) {
            return new JsonResponse([
                'message' => 'تم تحديث الطلبات المسموح بها، وتم تجاهل الطلبات النهائية (تم التوصيل / تم الإلغاء): #'.implode(', #', $lockedOrders),
            ]);
        }

        return new JsonResponse([
            'message' => trans('admin::app.sales.orders.index.datagrid.mass-update-success'),
        ]);
    }

    /**
     * Restore inventory for canceled or returned orders.
     */
    protected function restoreOrderInventory(Order $order): void
    {
        // Avoid restoring multiple times if already canceled or returned
        if (in_array($order->status, [Order::STATUS_CANCELED, Order::STATUS_RETURNED], true)) {
            return;
        }

        $orderItemRepository = app(OrderItemRepository::class);

        foreach ($order->items as $item) {
            $orderItems = $item->getTypeInstance()->isComposite() ? $item->children : [$item];

            foreach ($orderItems as $orderItem) {
                $orderItemRepository->returnQtyToProductInventory($orderItem);

                $remainingQty = ($orderItem->qty_ordered - $orderItem->qty_canceled - $orderItem->qty_refunded);

                if ($remainingQty > 0) {
                    $orderItem->qty_canceled += $remainingQty;
                    $orderItem->save();
                }

                // If goods were physically shipped, return them to inventory source
                if ($orderItem->qty_shipped > 0 && $orderItem->product && $orderItem->product->manage_stock) {
                    $inventory = $orderItem->product->inventories()->first();
                    if ($inventory) {
                        $inventory->update(['qty' => $inventory->qty + $orderItem->qty_shipped]);
                    }
                }
            }
        }
    }

    /**
     * Auto generate shipment for COD orders.
     */
    protected function autoGenerateShipment(Order $order): void
    {
        if (! $order->canShip()) {
            return;
        }

        $source = InventorySource::first();
        $sourceId = $source?->id ?? 1;

        $items = [];
        foreach ($order->items as $item) {
            if ($item->qty_to_ship > 0) {
                $items[$item->id][$sourceId] = $item->qty_to_ship;
            }
        }

        if (empty($items)) {
            return;
        }

        $shipmentData = [
            'order_id' => $order->id,
            'shipment' => [
                'carrier_title' => $order->shipping_title ?? 'COD Delivery',
                'track_number' => $order->increment_id ?? (string) $order->id,
                'source' => $sourceId,
                'items' => $items,
            ],
        ];

        try {
            app(ShipmentRepository::class)->create($shipmentData);
        } catch (\Throwable $e) {
            Log::warning('Auto shipment failed for order #'.$order->id, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto generate invoice for COD orders.
     */
    protected function autoGenerateInvoice(Order $order): void
    {
        if (! $order->canInvoice()) {
            return;
        }

        $items = [];
        foreach ($order->items as $item) {
            if ($item->qty_to_invoice > 0) {
                $items[$item->id] = $item->qty_to_invoice;
            }
        }

        if (empty($items)) {
            return;
        }

        $invoiceData = [
            'order_id' => $order->id,
            'invoice' => [
                'items' => $items,
            ],
        ];

        try {
            app(InvoiceRepository::class)->create($invoiceData);
        } catch (\Throwable $e) {
            Log::warning('Auto invoice failed for order #'.$order->id, [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
