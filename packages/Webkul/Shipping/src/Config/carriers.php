<?php

return [
    'flatrate' => [
        'code' => 'flatrate',
        'title' => 'Flat Rate',
        'description' => 'Flat Rate Shipping',
        'active' => true,
        'default_rate' => '10',
        'type' => 'per_unit',
        'class' => 'Webkul\Shipping\Carriers\FlatRate',
    ],

    /*
     * Jemla: free shipping stays OFF by default. The free-delivery offer
     * (subtotal >= threshold, default 300 DH) does NOT use this carrier —
     * PromotionService::isFreeDelivery() zeroes the flat rate instead
     * (see FlatRate::getRate()). With active=true here and no admin row in
     * core_config, any customer could pick $0 delivery on any order, so
     * this carrier only appears when Simo enables it explicitly in the
     * admin (Configuration > Sales > Shipping Methods > Free Shipping).
     */
    'free' => [
        'code' => 'free',
        'title' => 'Free Shipping',
        'description' => 'Free Shipping',
        'active' => false,
        'default_rate' => '0',
        'class' => 'Webkul\Shipping\Carriers\Free',
    ],
];
