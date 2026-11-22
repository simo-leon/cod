<?php

namespace App\Cod\Automation;

/**
 * WhatsApp automation settings (COD System → WhatsApp Automation,
 * general.cod_system.automation).
 *
 * Every value has a default here AND in the admin (system.php): the
 * defaults below are the fallback when nothing was ever saved, so the
 * automation behaves the same on a fresh install. The admin values
 * always win when they are set.
 *
 * Defaults are the ones from the automation plan: automation off,
 * first reminder after 120 minutes, one reminder, move to
 * `whatsapp_unconfirmed` after 24 hours without a reply, create the
 * order automatically and send the parcel automatically on
 * confirmation.
 *
 * The four collection questions and the full conversation prompt are
 * editable in the admin; the defaults below are the Darija texts from
 * the plan. The confirmation and reminder messages live in the
 * WhatsApp section (general.cod_system.whatsapp) and are NOT
 * duplicated here — only the thank-you and cancel messages are new.
 */
class AutomationSettings
{
    public const CONFIG_KEY = 'general.cod_system.automation';

    public const DEFAULT_REMINDER_DELAY_MINUTES = 120;

    public const DEFAULT_UNCONFIRMED_AFTER_HOURS = 24;

    public const DEFAULT_MAX_REMINDERS = 1;

    /**
     * The four questions the AI asks a customer who inquired about a
     * product on WhatsApp, one per line, in order: name, city,
     * address, phone. One question is asked at a time.
     */
    public const DEFAULT_QUESTIONS = "شنو هو الاسم الكامل ديالك؟\nمن أي مدينة نتا؟\nشنو هو العنوان الكامل ديالك؟\nشنو هو رقم التيليفون ديالك؟";

    /**
     * The full conversation prompt, in Darija: the AI is Jemla En
     * Ligne's assistant, speaks Darija respectfully, confirms orders
     * and collects missing information, in six steps, with the strict
     * rules (answer only with true COD facts, never invent anything,
     * never change the price, never cancel a confirmed order, no
     * delivery promise beyond the facts given, escalate to a human
     * when the customer is angry, asks for an exchange / refund, or
     * is not understood after two tries).
     */
    public const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
أنت مساعد Jemla En Ligne، متجر مغربي كيبيع بالدفع عند الاستلام. كتهضر بالدارجة المغربية وباحترام، وهدفك تأكيد الطلب وجمع المعلومات الناقصة.

خطوات المحادثة:
1. رحب بالعميل وذكر ليه المنتج والثمن والطلب إلا كان.
2. إلا كان الطلب موجود، سولو واش يأكدو.
3. إلا ما كانش طلب، سولو على المعلومات الناقصة واحدا بواحد: الاسم الكامل، المدينة، العنوان، رقم التيليفون.
4. ملي تجمع المعلومات كاملة، عرض عليه ملخص وسولو واش يأكد.
5. ملي يأكد، شكرو وقول ليه غادي نتواصلو معاه قبل التوصيل.
6. إلا قال لا أو طلب الإلغاء، ألغي الطلب باحترام.

قواعد مهمة:
- جاوب غير بالمعلومات الصحيحة اللي عطيناك: الثمن والمنتج وحالة الطلب. ما تخترع والو.
- ما تبدلش الثمن أبدا.
- ما تلغيش طلبا مؤكدا.
- ما تعطيش وعد على التوصيل إلا بالمعلومات الموجودة.
- إلا كان العميل غاضبا، أو طلب تبديل أو تعويض، أو ما فهمتيش رسالتو بعد محاولتين، حول المحادثة للإنسان وتوقف عن الرد.
PROMPT;

    public const DEFAULT_THANK_YOU_MESSAGE = 'شكرا {name}، تم تأكيد الطلب ديالك رقم {order_id} ({total}). غادي نتواصلو معك قبل التوصيل إن شاء الله.';

    public const DEFAULT_CANCEL_MESSAGE = 'واخا {name}، تم إلغاء الطلب ديالك رقم {order_id}. إلا بغيتي شي حاجة أخرى، مرحبا بيك.';

    /**
     * Message sent when a conversation is handed to a human because
     * the customer's message was not understood after two tries.
     * (When the AI itself escalates, its own reply is sent instead.)
     */
    public const DEFAULT_HANDOFF_MESSAGE = 'واخا، غادي نحولك لواحد من الفريق ديالنا باش يعاونك.';

    /**
     * Whether the WhatsApp automation is switched on. Default off:
     * nothing changes until the admin enables it.
     */
    public function isEnabled(): bool
    {
        return (bool) core()->getConfigData(self::CONFIG_KEY.'.status');
    }

    /**
     * Minutes to wait after the confirmation message (or after the
     * previous reminder) before sending the next reminder.
     * Default 120. Falls back to the older WhatsApp-section value
     * (general.cod_system.whatsapp.reminder_delay_minutes) when the
     * automation value was never saved.
     */
    public function reminderDelayMinutes(): int
    {
        $minutes = core()->getConfigData(self::CONFIG_KEY.'.reminder_delay_minutes');

        if ($minutes === null || $minutes === '') {
            $minutes = core()->getConfigData('general.cod_system.whatsapp.reminder_delay_minutes');
        }

        return max(1, (int) ($minutes ?? self::DEFAULT_REMINDER_DELAY_MINUTES));
    }

    /**
     * Hours without a reply after the confirmation message before the
     * order moves to `whatsapp_unconfirmed`. Default 24.
     */
    public function unconfirmedAfterHours(): int
    {
        return max(1, (int) (core()->getConfigData(self::CONFIG_KEY.'.unconfirmed_after_hours') ?? self::DEFAULT_UNCONFIRMED_AFTER_HOURS));
    }

    /**
     * How many reminders are sent per order at most. Default 1.
     */
    public function maxReminders(): int
    {
        return max(0, (int) (core()->getConfigData(self::CONFIG_KEY.'.max_reminders') ?? self::DEFAULT_MAX_REMINDERS));
    }

    /**
     * The four collection questions, in order, one per line in the
     * admin textarea. Empty lines are ignored; when nothing is
     * configured the four default questions are used.
     *
     * @return string[]
     */
    public function questions(): array
    {
        $raw = core()->getConfigData(self::CONFIG_KEY.'.questions');

        $raw = $raw !== null && trim((string) $raw) !== '' ? (string) $raw : self::DEFAULT_QUESTIONS;

        $questions = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []),
            fn (string $line) => $line !== '',
        ));

        return $questions !== [] ? $questions : explode("\n", self::DEFAULT_QUESTIONS);
    }

    /**
     * Whether a confirmed product inquiry creates the order
     * automatically. Default true. When false, the conversation waits
     * in `awaiting_confirmation` for a human to create it.
     */
    public function autoCreateOrder(): bool
    {
        $value = core()->getConfigData(self::CONFIG_KEY.'.auto_create_order');

        return $value === null || $value === '' ? true : (bool) $value;
    }

    /**
     * Whether the parcel is sent to the delivery provider
     * automatically when an order is confirmed on WhatsApp. Default
     * true. When false, nothing is sent automatically — the parcel is
     * sent manually from the admin order page.
     */
    public function autoSendToDelivery(): bool
    {
        $value = core()->getConfigData(self::CONFIG_KEY.'.auto_send_to_delivery');

        return $value === null || $value === '' ? true : (bool) $value;
    }

    /**
     * The full conversation prompt (system prompt) for the AI agent.
     * The admin text wins; the Darija default from the plan is used
     * when nothing was saved.
     */
    public function systemPrompt(): string
    {
        $prompt = core()->getConfigData(self::CONFIG_KEY.'.system_prompt');

        return $prompt !== null && trim((string) $prompt) !== '' ? (string) $prompt : self::DEFAULT_SYSTEM_PROMPT;
    }

    /**
     * Thank-you message sent when a customer confirms (placeholders
     * {order_id} {name} {total} {products} {store}).
     */
    public function thankYouMessage(): string
    {
        $message = core()->getConfigData(self::CONFIG_KEY.'.thank_you_message');

        return $message !== null && trim((string) $message) !== '' ? (string) $message : self::DEFAULT_THANK_YOU_MESSAGE;
    }

    /**
     * Cancel message sent when a customer cancels (same
     * placeholders).
     */
    public function cancelMessage(): string
    {
        $message = core()->getConfigData(self::CONFIG_KEY.'.cancel_message');

        return $message !== null && trim((string) $message) !== '' ? (string) $message : self::DEFAULT_CANCEL_MESSAGE;
    }
}
