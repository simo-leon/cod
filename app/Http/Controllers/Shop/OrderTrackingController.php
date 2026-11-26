<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\StoreFeatures\StoreFeatureServiceInterface;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public order tracking by phone number (idea 6): a customer types
 * the phone number they ordered with and sees their orders' status
 * (placed / confirmed / out for delivery / delivered) with dates.
 * Read-only, no login.
 *
 * Privacy: only orders whose billing/shipping phone (or COD
 * snapshot phone) matches the exact normalized number are shown.
 * The lookup is throttled (see the route) so the endpoint cannot
 * be used to enumerate phone numbers at scale.
 */
class OrderTrackingController extends Controller
{
    public function __construct(
        protected StoreFeatureServiceInterface $features,
    ) {}

    /**
     * The tracking form. Returns 404 when Simo switched tracking
     * off in the admin — the page should not exist then.
     */
    public function index(): View
    {
        abort_unless($this->features->isTrackingEnabled(), 404);

        return view('shop::tracking.index', [
            'phone' => null,
            'orders' => null,
        ]);
    }

    /**
     * Look up the orders placed with this phone number.
     */
    public function lookup(Request $request): View
    {
        abort_unless($this->features->isTrackingEnabled(), 404);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $phone = trim($data['phone']);

        return view('shop::tracking.index', [
            'phone' => $phone,
            'orders' => $this->features->findOrdersByPhone($phone),
        ]);
    }
}
