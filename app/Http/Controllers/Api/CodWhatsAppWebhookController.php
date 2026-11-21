<?php

namespace App\Http\Controllers\Api;

use App\Cod\WhatsApp\WhatsAppService;
use App\Cod\WhatsApp\WhatsAppServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp webhooks for the COD System (phase 4).
 *
 * Two unauthenticated endpoints (webhooks cannot carry the admin
 * session); each verifies the sender the way its provider allows:
 *
 * Meta (POST /api/cod/whatsapp/meta):
 * - GET verification: Meta calls GET with hub.mode=subscribe,
 *   hub.verify_token and hub.challenge when the webhook is registered
 *   in the Meta app. The challenge is returned verbatim only when the
 *   token matches general.cod_system.whatsapp.meta_verify_token.
 * - POST signature: when general.cod_system.whatsapp.meta_app_secret
 *   is set, the X-Hub-Signature-256 header must be
 *   'sha256=' + HMAC-SHA256(raw body, app secret), compared with
 *   hash_equals. When no app secret is configured the signature
 *   CANNOT be checked: the request is accepted and a warning is
 *   logged — configure the app secret before production use. A
 *   present-but-wrong signature is always rejected (403).
 * - Payload: entry[].changes[].value.messages[] — text messages
 *   (text.body) and interactive button replies
 *   (interactive.button_reply.id + title). Statuses (sent/delivered/
 *   read) are ignored. The reply is matched to a pending COD order by
 *   the sender phone (WhatsAppService::processInbound).
 *
 * Evolution (POST /api/cod/whatsapp/evolution):
 * - Event "messages.upsert": data.key.remoteJid (phone),
 *   data.key.fromMe (our own messages are ignored), data.message
 *   .conversation or .extendedTextMessage.text (or
 *   .buttonsResponseMessage for button taps).
 * - Evolution v2 has NO documented webhook signature, so the sender
 *   cannot be verified cryptographically. The endpoint only accepts
 *   events whose `instance` matches the configured instance name
 *   (general.cod_system.whatsapp.evolution_instance); anything else
 *   is ignored. In a test setup, also restrict the endpoint by IP /
 *   reverse proxy — see docs/evolution-api-local-setup.md.
 *
 * Both endpoints always answer 200 quickly (except a failed Meta
 * signature, 403): providers retry failed deliveries, and a slow or
 * failing webhook must never block the reply processing.
 */
class CodWhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppServiceInterface $whatsapp,
    ) {}

    /**
     * Meta webhook verification (GET). Returns the hub.challenge
     * verbatim when mode=subscribe and the verify token matches.
     */
    public function verifyMeta(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $configuredToken = core()->getConfigData(WhatsAppService::CONFIG_KEY.'.meta_verify_token');

        if ($mode === 'subscribe' && $token && $configuredToken && hash_equals((string) $configuredToken, (string) $token)) {
            return response((string) $challenge, 200);
        }

        return response('Forbidden.', 403);
    }

    /**
     * Meta incoming messages webhook (POST).
     */
    public function handleMeta(Request $request)
    {
        if (! $this->verifyMetaSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $payload = $request->json()->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['messages'] ?? [] as $message) {
                    $this->handleMetaMessage($message);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * Evolution incoming messages webhook (POST).
     */
    public function handleEvolution(Request $request)
    {
        $payload = $request->json()->all();

        if (($payload['event'] ?? null) !== 'messages.upsert') {
            return response()->json(['received' => true, 'message' => 'Event ignored (not messages.upsert).']);
        }

        $configuredInstance = core()->getConfigData(WhatsAppService::CONFIG_KEY.'.evolution_instance');

        if ($configuredInstance && ($payload['instance'] ?? null) !== $configuredInstance) {
            Log::warning('COD WhatsApp (Evolution): webhook for an unknown instance ignored', [
                'instance' => $payload['instance'] ?? null,
            ]);

            return response()->json(['received' => true, 'message' => 'Instance ignored.']);
        }

        $data = $payload['data'] ?? [];
        $key = $data['key'] ?? [];

        // Messages we sent ourselves echo back through the webhook.
        if (! empty($key['fromMe'])) {
            return response()->json(['received' => true, 'message' => 'Own message ignored.']);
        }

        $remoteJid = (string) ($key['remoteJid'] ?? '');
        $phone = explode('@', $remoteJid)[0];

        if ($phone === '') {
            return response()->json(['received' => true, 'message' => 'No sender phone.']);
        }

        $message = $data['message'] ?? [];
        $text = $message['conversation']
            ?? $message['extendedTextMessage']['text']
            ?? $message['imageMessage']['caption']
            ?? null;
        $buttonId = $message['buttonsResponseMessage']['selectedButtonId'] ?? null;

        if ($buttonId && empty($text)) {
            $text = $message['buttonsResponseMessage']['selectedDisplayText'] ?? null;
        }

        $result = $this->whatsapp->processInbound(
            'evolution',
            $phone,
            $text !== null ? (string) $text : null,
            $buttonId !== null ? (string) $buttonId : null,
            isset($key['id']) ? (string) $key['id'] : null,
        );

        return response()->json(['received' => true, 'message' => $result['message'] ?? null]);
    }

    protected function handleMetaMessage(array $message): void
    {
        $from = (string) ($message['from'] ?? '');

        if ($from === '') {
            return;
        }

        $type = $message['type'] ?? null;
        $text = null;
        $buttonId = null;

        if ($type === 'text') {
            $text = $message['text']['body'] ?? null;
        } elseif ($type === 'interactive') {
            $reply = $message['interactive']['button_reply'] ?? $message['interactive']['list_reply'] ?? [];
            $buttonId = $reply['id'] ?? null;
            $text = $reply['title'] ?? null;
        } else {
            // Other types (image, audio, ...) carry no confirmation
            // answer; still record the contact attempt.
            $text = null;
        }

        $this->whatsapp->processInbound(
            'meta',
            $from,
            $text !== null ? (string) $text : null,
            $buttonId !== null ? (string) $buttonId : null,
            isset($message['id']) ? (string) $message['id'] : null,
        );
    }

    /**
     * Verify the Meta X-Hub-Signature-256 header. Returns true when
     * the signature is valid, or when no app secret is configured
     * (signature cannot be checked — logged as a warning). Returns
     * false only when a secret is configured and the signature is
     * missing or wrong.
     */
    protected function verifyMetaSignature(Request $request): bool
    {
        $secret = core()->getConfigData(WhatsAppService::CONFIG_KEY.'.meta_app_secret');

        if (empty($secret)) {
            Log::warning('COD WhatsApp (Meta): webhook received without an app secret configured — signature NOT verified.');

            return true;
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (empty($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);

        return hash_equals($expected, $signature);
    }
}
