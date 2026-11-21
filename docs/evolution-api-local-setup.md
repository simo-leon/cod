# Evolution API — Local Setup for COD System Testing (Phase 4)

> ⚠️ **WARNING — READ FIRST: Evolution API is UNOFFICIAL and FOR TESTING ONLY.**
>
> Evolution API drives a normal WhatsApp account through the WhatsApp Web
> protocol (Baileys library). It is **not** the official Meta WhatsApp
> Business Platform, and using it **violates WhatsApp's Terms of Service**.
> Meta can **ban the phone number at any time, without warning**, including
> permanently. **Never use it with the store's real business number, never
> in production, and never for customer traffic at scale.** It exists in
> this project only so the COD confirmation flow can be tested end-to-end
> with a **spare number** before the official Meta provider is configured.
> For production, use the Meta WhatsApp Cloud API provider instead
> (Admin → Configuration → General → COD System → WhatsApp → Provider:
> Meta). The same warning is shown in the admin next to the provider
> choice and in the `EvolutionWhatsAppProvider` code.

## What this document covers

Installing Evolution API locally with Docker, creating an instance,
scanning the QR code, and pointing its webhook at this Laravel app so
customer replies update COD orders. **Evolution API itself is an
external server — this project does not install it; it only connects
to it** (`app/Cod/WhatsApp/EvolutionWhatsAppProvider.php`).

## Verified vs not verified

Checked on 2026-11-21 against the official docs
(https://doc.evolution-api.com/v2), the
[EvolutionAPI/evolution-api](https://github.com/EvolutionAPI/evolution-api)
GitHub repo, and independent Evolution API references:

| Item | Status |
| --- | --- |
| Version 2.3.7, Docker image `evoapicloud/evolution-api:v2.3.7`, port 8080 | ✅ Verified (multiple sources, incl. Railway template docs) |
| Manager UI at `/manager`, image `evoapicloud/evolution-manager:latest`, login with the global API key | ✅ Verified |
| `POST {base}/message/sendText/{instance}`, header `apikey: <key>`, body `{"number": "<digits>", "text": "..."}` | ✅ Verified (official docs + references) |
| Webhook event `messages.upsert`: `data.key.remoteJid` (`212...@s.whatsapp.net`), `data.key.fromMe`, `data.message.conversation` / `extendedTextMessage.text` | ✅ Verified |
| Instance endpoints: `POST /instance/create`, `GET /instance/connect/{instance}` (QR), `GET /instance/connectionState/{instance}`, `POST /webhook/set/{instance}` | ✅ Verified |
| Session volume `/evolution/instances`, Postgres + Redis required | ✅ Verified |
| Whether `sendText` accepts bare digits or needs the `@s.whatsapp.net` suffix | ⚠️ **Not verified** — most v2 examples send bare digits; this project sends bare digits. If Evolution answers 400, retry with the suffix (fallback not implemented). |
| Exact success-response shape / message-id field of `sendText` | ⚠️ **Not verified** — the code looks for the id in `key.id`, `messageId`, `id` and tolerates its absence. |
| `/message/sendButtons` reliability over WhatsApp Web | ⚠️ **Not verified** — buttons render inconsistently, so this project degrades to numbered text instead (see below). |
| Any webhook signature for Evolution v2 | ⚠️ **None documented** — the inbound endpoint only checks the instance name. Restrict it by IP / reverse proxy (see §5). |

## 1. Prerequisites

- Docker + Docker Compose on your machine (or a small VPS).
- A **spare** WhatsApp number (a test SIM / second phone) with WhatsApp
  installed. Do **not** use the store's business number.
- This Laravel app reachable from the Evolution container. For local
  testing of *sending*, `http://localhost:8080` is enough. For testing
  *incoming replies*, Evolution must be able to POST to your app's
  webhook (`POST /api/cod/whatsapp/evolution`), so the app needs a
  public HTTPS URL (e.g. an ngrok tunnel or a staging server) — see §5.

## 2. Install with Docker Compose (recommended)

Create a folder (outside this repo), e.g. `~/evolution/`:

```yaml
# ~/evolution/docker-compose.yml
services:
  evolution-api:
    image: evoapicloud/evolution-api:v2.3.7
    container_name: evolution_api
    restart: always
    ports:
      - "127.0.0.1:8080:8080"
    volumes:
      - evolution_instances:/evolution/instances
    env_file:
      - .env
    depends_on:
      - evolution-postgres
      - evolution-redis

  evolution-manager:
    image: evoapicloud/evolution-manager:latest
    container_name: evolution_manager
    restart: always
    ports:
      - "127.0.0.1:8081:8080"
    env_file:
      - .env

  evolution-postgres:
    image: postgres:15
    container_name: evolution_postgres
    restart: always
    environment:
      POSTGRES_USER: evolution
      POSTGRES_PASSWORD: CHOOSE_A_STRONG_PASSWORD
      POSTGRES_DB: evolution
    volumes:
      - evolution_postgres:/var/lib/postgresql/data

  evolution-redis:
    image: redis:latest
    container_name: evolution_redis
    restart: always
    command: redis-server --appendonly yes
    volumes:
      - evolution_redis:/data

volumes:
  evolution_instances:
  evolution_postgres:
  evolution_redis:
```

```env
# ~/evolution/.env — replace the secrets with your own values.
SERVER_URL=http://localhost:8080
AUTHENTICATION_API_KEY=CHOOSE_A_LONG_RANDOM_KEY
DATABASE_PROVIDER=postgresql
DATABASE_CONNECTION_URI=postgresql://evolution:CHOOSE_A_STRONG_PASSWORD@evolution-postgres:5432/evolution
CACHE_REDIS_URI=redis://evolution-redis:6379
TELEMETRY_ENABLED=false
```

Start it:

```bash
cd ~/evolution
docker compose up -d
docker compose logs -f evolution-api   # wait until it listens on 8080
curl http://localhost:8080/            # should return a welcome/version JSON
```

Minimal alternative (single container, no Postgres/Redis — sessions only,
not recommended for anything beyond a smoke test):

```bash
docker run -d --name evolution-api \
  -p 8080:8080 \
  -e AUTHENTICATION_API_KEY=CHOOSE_A_LONG_RANDOM_KEY \
  -v evolution_instances:/evolution/instances \
  evoapicloud/evolution-api:v2.3.7
```

## 3. Create an instance and scan the QR code

Option A — Manager UI (easiest):

1. Open http://localhost:8080/manager (or http://localhost:8081 if you
   mapped the manager separately) and log in with your
   `AUTHENTICATION_API_KEY`.
2. Create an instance, e.g. name `jemla-test`.
3. Open the instance and scan the QR code with the **spare** phone:
   WhatsApp → Linked devices → Link a device. (On some versions the QR
   is also at `GET /instance/connect/{instance}` — see option B.)
4. Check the state: `GET /instance/connectionState/jemla-test` with
   header `apikey: <your key>` should report `open` / connected.

Option B — API:

```bash
# Create the instance (qrcode: true returns a base64 QR code)
curl -X POST http://localhost:8080/instance/create \
  -H "apikey: CHOOSE_A_LONG_RANDOM_KEY" \
  -H "Content-Type: application/json" \
  -d '{"instanceName": "jemla-test", "qrcode": true}'

# Get the QR code to scan
curl http://localhost:8080/instance/connect/jemla-test \
  -H "apikey: CHOOSE_A_LONG_RANDOM_KEY"

# Check the connection state
curl http://localhost:8080/instance/connectionState/jemla-test \
  -H "apikey: CHOOSE_A_LONG_RANDOM_KEY"
```

## 4. Connect this Laravel app to Evolution

In the store admin: **Configuration → General → COD System →
WhatsApp**:

| Field | Value |
| --- | --- |
| Enable WhatsApp (COD System) | ✅ on |
| WhatsApp Provider | **Evolution API** |
| Evolution API Server URL | `http://localhost:8080` (or your server URL) |
| Evolution API Key | your `AUTHENTICATION_API_KEY` |
| Evolution Instance Name | `jemla-test` (the instance you created) |

## 5. Point the Evolution webhook at Laravel (incoming replies)

Evolution must POST incoming messages to:

```
POST https://YOUR-APP-URL/api/cod/whatsapp/evolution
```

- In the Manager UI: open the instance → Events → Webhook, set the URL
  and enable at least `MESSAGES_UPSERT`.
- Or via API:

```bash
curl -X POST http://localhost:8080/webhook/set/jemla-test \
  -H "apikey: CHOOSE_A_LONG_RANDOM_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://YOUR-APP-URL/api/cod/whatsapp/evolution",
    "webhook_by_events": true,
    "webhook_base64": false,
    "events": ["MESSAGES_UPSERT", "CONNECTION_UPDATE"]
  }'
```

Notes:

- Evolution can only reach a **public** URL. Locally, expose the app
  with a tunnel (e.g. `ngrok http 8000`) and use the tunnel URL above.
  The webhook URL must be HTTPS in practice (tunnels provide it).
- Evolution v2 has **no webhook signature**: the Laravel endpoint only
  accepts events whose `instance` matches the configured instance
  name. Do not expose the Evolution server itself publicly without a
  strong API key, and restrict `/api/cod/whatsapp/evolution` by IP /
  reverse proxy when possible.
- Some references show the older form `PUT /webhook/set/{instance}`
  with a nested `{"webhook": {...}}` body. The form above (POST, flat
  body) matches the current v2 docs; if your version rejects it, set
  the webhook from the Manager UI instead.

## 6. How the confirmation flow behaves on Evolution

- **Outbound:** the confirmation and reminder texts are sent with
  `POST /message/sendText/{instance}`. Evolution / WhatsApp Web does
  **not** render Meta-style reply buttons reliably, so the options are
  appended as numbered text:
  `1- أكد الطلب / 2- أجل الطلب / 3- ألغي الطلب`.
- **Inbound:** the customer answers with a digit (`1`, `2`, `3`) or
  with words (Arabic, French or English — e.g. `أكد`, `أجل`, `ألغي`,
  `oui`, `non`, `cancel`). The webhook interprets the reply and updates
  the COD order: `1` → `confirmed`, `2` → stays `pending_confirmation`
  with a callback promised for tomorrow, `3` → `canceled`. Every sent
  and received message is logged in `cod_whatsapp_messages` with the
  provider `evolution`.
- **Difference vs Meta:** on Meta the same confirmation is sent as an
  approved template (or an interactive message with three real reply
  buttons when no template is configured), and the customer taps a
  button. The order status updates are identical either way.

## 7. Test checklist (spare number only)

1. Evolution running, instance state `open`.
2. Admin: WhatsApp enabled, provider Evolution, URL/key/instance set.
3. Place a test COD order (guest checkout) → a COD record is created →
   the confirmation message arrives on the **customer's** phone
   (the number on the order, not the Evolution number).
4. Reply `1` from the customer phone → order status becomes
   `confirmed` in the COD orders list, and a call-log entry
   `confirmed` (WhatsApp) appears.
5. New test order, reply `2` → order stays pending with a callback
   date; reply `3` on another order → `canceled`.
6. `php artisan cod:remind-unconfirmed` sends one reminder to an
   order confirmed more than 120 minutes ago with no reply (set the
   delay to a small value in the admin to test, then restore 120).

## 8. Switching to production (Meta)

When testing is done: in the admin, change Provider to **Meta**,
fill the Meta access token / Phone Number ID / template name / app
secret / verify token, create the approved template in Meta Business
Suite, and register the Meta webhook
(`GET/POST https://YOUR-APP-URL/api/cod/whatsapp/meta`) in the Meta
app. Then stop and remove the Evolution containers
(`docker compose down -v` in `~/evolution/`) so the test number is
unlinked.
