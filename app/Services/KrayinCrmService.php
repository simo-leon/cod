<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KrayinCrmService
{
    /**
     * Check if Krayin CRM integration is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData('general.krayin_crm.settings.status');
    }

    /**
     * Get the Krayin CRM API base URL.
     */
    public function getApiUrl(): string
    {
        $url = (string) core()->getConfigData('general.krayin_crm.settings.api_url');

        return rtrim($url ?: 'http://127.0.0.1:8002/api', '/');
    }

    /**
     * Get the API Access Token.
     */
    public function getApiToken(): ?string
    {
        return core()->getConfigData('general.krayin_crm.settings.api_token');
    }

    /**
     * Test connection with Krayin CRM API.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return [
                'success' => false,
                'message' => 'Krayin CRM integration is disabled in configuration.',
            ];
        }

        $token = $this->getApiToken();

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'API Access Token is missing.',
            ];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->acceptJson()
                ->get("{$this->getApiUrl()}/users");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to Krayin CRM!',
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to connect. HTTP Status: '.$response->status().' - '.$response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Sync a customer as a Person in Krayin CRM.
     */
    public function syncCustomer(mixed $customer): ?int
    {
        if (! $this->isEnabled() || ! core()->getConfigData('general.krayin_crm.settings.sync_customers')) {
            return null;
        }

        $token = $this->getApiToken();

        if (empty($token)) {
            return null;
        }

        try {
            $payload = [
                'name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
                'emails' => [
                    ['value' => $customer->email, 'label' => 'work'],
                ],
            ];

            if (! empty($customer->phone)) {
                $payload['contact_numbers'] = [
                    ['value' => $customer->phone, 'label' => 'work'],
                ];
            }

            $response = Http::withToken($token)
                ->timeout(10)
                ->acceptJson()
                ->post("{$this->getApiUrl()}/persons", $payload);

            if ($response->successful()) {
                return (int) $response->json('data.id');
            }

            Log::warning('Krayin CRM syncCustomer failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Krayin CRM syncCustomer exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Sync an order as a Lead in Krayin CRM.
     */
    public function syncOrder(mixed $order): ?int
    {
        if (! $this->isEnabled() || ! core()->getConfigData('general.krayin_crm.settings.sync_orders')) {
            return null;
        }

        $token = $this->getApiToken();

        if (empty($token)) {
            return null;
        }

        try {
            // 1. Create or resolve Person contact for the customer
            $personId = null;

            $customerEmail = $order->customer_email;
            $customerName = trim(($order->customer_first_name ?? '').' '.($order->customer_last_name ?? ''));
            $phone = $order->billing_address?->phone ?? $order->shipping_address?->phone;

            if ($customerEmail) {
                $personPayload = [
                    'name' => $customerName ?: $customerEmail,
                    'emails' => [
                        ['value' => $customerEmail, 'label' => 'work'],
                    ],
                ];

                if (! empty($phone)) {
                    $personPayload['contact_numbers'] = [
                        ['value' => $phone, 'label' => 'work'],
                    ];
                }

                $personResponse = Http::withToken($token)
                    ->timeout(10)
                    ->acceptJson()
                    ->post("{$this->getApiUrl()}/persons", $personPayload);

                if ($personResponse->successful()) {
                    $personId = $personResponse->json('data.id');
                }
            }

            // 2. Create Lead for this Order
            $leadTitle = "Order #{$order->increment_id} - {$customerName}";
            $leadDescription = "New Store Order #{$order->increment_id}\nTotal: {$order->grand_total} {$order->order_currency_code}\nItems: {$order->total_item_count}";

            $leadPayload = [
                'title' => $leadTitle,
                'description' => $leadDescription,
                'lead_value' => (float) $order->grand_total,
                'status' => 1,
            ];

            if ($personId) {
                $leadPayload['person_id'] = $personId;
            }

            $leadResponse = Http::withToken($token)
                ->timeout(10)
                ->acceptJson()
                ->post("{$this->getApiUrl()}/leads", $leadPayload);

            if ($leadResponse->successful()) {
                return (int) $leadResponse->json('data.id');
            }

            Log::warning('Krayin CRM syncOrder failed', [
                'status' => $leadResponse->status(),
                'body' => $leadResponse->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Krayin CRM syncOrder exception: '.$e->getMessage());
        }

        return null;
    }
}
