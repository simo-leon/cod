<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Admin reorganisation (2026-11-22): the COD System settings page
     * was one long pile of 50 fields. It is now split into separate
     * modules — Souk gets its own top-level section and the WhatsApp
     * message templates get their own section — so the stored config
     * keys move too:
     *
     * - general.cod_system.souk.*            -> general.souk.settings.*
     * - general.cod_system.whatsapp.<message> -> general.cod_system.whatsapp_messages.<message>
     *   (confirmation_message, reminder_message, reminder_delay_minutes,
     *   shipped/out_for_delivery/delivered/returned_message and the four
     *   meta_*_template_name fields; the connection fields stay under
     *   general.cod_system.whatsapp.*)
     *
     * This migration only renames core_config rows, so no saved value
     * is lost. It is idempotent (re-running it is a no-op) and
     * dedupe-safe: if a row already exists under the new key for the
     * same channel/locale, the old row is dropped and the new one wins.
     */
    public function up(): void
    {
        foreach (self::renames() as $old => $new) {
            $rows = DB::table('core_config')->where('code', $old)->get();

            foreach ($rows as $row) {
                $exists = DB::table('core_config')
                    ->where('code', $new)
                    ->where('channel_code', $row->channel_code)
                    ->where('locale_code', $row->locale_code)
                    ->exists();

                if ($exists) {
                    DB::table('core_config')->where('id', $row->id)->delete();
                } else {
                    DB::table('core_config')->where('id', $row->id)->update(['code' => $new]);
                }
            }
        }
    }

    /**
     * Reverse the renames (same dedupe rule, new key wins).
     */
    public function down(): void
    {
        foreach (self::renames() as $old => $new) {
            $rows = DB::table('core_config')->where('code', $new)->get();

            foreach ($rows as $row) {
                $exists = DB::table('core_config')
                    ->where('code', $old)
                    ->where('channel_code', $row->channel_code)
                    ->where('locale_code', $row->locale_code)
                    ->exists();

                if ($exists) {
                    DB::table('core_config')->where('id', $row->id)->delete();
                } else {
                    DB::table('core_config')->where('id', $row->id)->update(['code' => $old]);
                }
            }
        }
    }

    /**
     * @return array<string, string> old config key => new config key
     */
    private static function renames(): array
    {
        $renames = [];

        foreach (['status', 'claim_timeout_hours', 'default_price_cap', 'default_min_margin_percent', 'payment_model'] as $field) {
            $renames['general.cod_system.souk.'.$field] = 'general.souk.settings.'.$field;
        }

        foreach (['confirmation_message', 'reminder_message', 'reminder_delay_minutes', 'shipped_message', 'out_for_delivery_message', 'delivered_message', 'returned_message', 'meta_shipped_template_name', 'meta_out_for_delivery_template_name', 'meta_delivered_template_name', 'meta_returned_template_name'] as $field) {
            $renames['general.cod_system.whatsapp.'.$field] = 'general.cod_system.whatsapp_messages.'.$field;
        }

        return $renames;
    }
};
