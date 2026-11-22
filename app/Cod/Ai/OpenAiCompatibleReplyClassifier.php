<?php

namespace App\Cod\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-compatible chat completions client used to classify free-text
 * customer replies for the COD WhatsApp confirmation flow.
 *
 * It calls POST {base_url}/chat/completions with
 * `Authorization: Bearer {api_key}` and a low temperature, and expects
 * the model to answer with exactly one intent word (confirmed /
 * postponed / canceled / question / unclear).
 *
 * Settings live in the admin (COD System → AI, general.cod_system.ai):
 * status (default off), base_url, api_key (password field), model.
 * The API key is read from core_config on every call and is never
 * logged, never stored anywhere else and never included in any
 * exception message.
 *
 * IMPORTANT — Llama API availability (October 2026): Meta's Llama API
 * (https://api.llama.com/compat/v1, the default base URL) has been
 * gated / preview-only since October 2026, with no self-serve access:
 * a token cannot simply be created in a dashboard. This client is
 * deliberately provider-agnostic — it works with ANY OpenAI-compatible
 * endpoint (OpenRouter, Groq, ...). To switch provider, change only
 * the base URL, the API key and the model in the admin; no code change
 * is needed.
 *
 * The client NEVER throws: any failure (disabled, unconfigured,
 * network error, timeout, non-2xx, malformed or unparseable answer)
 * returns null, so the caller falls back to the built-in keyword
 * matcher and the confirmation flow keeps working without the AI.
 */
class OpenAiCompatibleReplyClassifier implements ReplyIntentClassifierInterface
{
    public const CONFIG_KEY = 'general.cod_system.ai';

    public const DEFAULT_BASE_URL = 'https://api.llama.com/compat/v1';

    public const DEFAULT_MODEL = 'Llama-4-Maverick-17B-128E-Instruct-FP8';

    /**
     * Short timeout: reply classification happens inside a webhook
     * request, it must never block the confirmation flow for long.
     */
    public const TIMEOUT_SECONDS = 10;

    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    public function isConfigured(): bool
    {
        return $this->getBaseUrl() !== null
            && $this->getApiKey() !== null
            && $this->getModel() !== null;
    }

    public function classify(string $text): ?string
    {
        if (! $this->isEnabled() || ! $this->isConfigured()) {
            return null;
        }

        $clean = trim($text);

        if ($clean === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->getApiKey(),
                'Accept' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($this->getBaseUrl().'/chat/completions', [
                    'model' => $this->getModel(),
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You classify a customer reply to an order-confirmation message from a Moroccan cash-on-delivery store. The customer writes in Moroccan Darija, Arabic, French or English. Answer with exactly one word and nothing else: confirmed (the customer confirms the order), postponed (the customer asks to delay the order or to be called later), canceled (the customer refuses or cancels the order), question (the customer asks something, e.g. about the price or the delivery), unclear (the reply cannot be understood as any of the above). Examples: "واخا أكدو" -> confirmed; "أجلو ليوماين" -> postponed; "ما بغيتوش" -> canceled; "شحال التوصيل؟" -> question.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $clean,
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            // Unreachable host, timeout, ... — never throw, the caller
            // falls back to keyword matching.
            Log::warning('COD AI: reply classification request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('COD AI: reply classification request failed', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            Log::warning('COD AI: reply classification answer has no usable content');

            return null;
        }

        return $this->parseIntent($content);
    }

    /**
     * Extract the first intent word from the model answer. The model
     * is asked for exactly one word, but tolerate surrounding text,
     * punctuation or quotes — anything else returns null so the
     * keyword matcher takes over.
     */
    protected function parseIntent(string $content): ?string
    {
        $clean = mb_strtolower(trim($content));

        if (preg_match('/\b(confirmed|postponed|canceled|question|unclear)\b/u', $clean, $matches)) {
            return $matches[1];
        }

        Log::warning('COD AI: reply classification answer is not a known intent', [
            'answer' => mb_substr($content, 0, 100),
        ]);

        return null;
    }

    public function getBaseUrl(): ?string
    {
        $url = core()->getConfigData(self::CONFIG_KEY.'.base_url');

        $url = $url ? rtrim((string) $url, '/') : '';

        return $url !== '' ? $url : null;
    }

    public function getApiKey(): ?string
    {
        $key = core()->getConfigData(self::CONFIG_KEY.'.api_key');

        return $key ? (string) $key : null;
    }

    public function getModel(): ?string
    {
        $model = core()->getConfigData(self::CONFIG_KEY.'.model');

        return $model ? (string) $model : null;
    }
}
