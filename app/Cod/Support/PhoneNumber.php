<?php

namespace App\Cod\Support;

/**
 * Phone number normalization, shared by the WhatsApp service and the
 * conversation flow so both sides compare the same digits.
 *
 * A Moroccan number becomes bare international digits: 06... or
 * +2126... both become 2126.... Comparison between two numbers is done
 * on the last 9 digits (see WhatsAppService::findPendingOrderByPhone).
 */
class PhoneNumber
{
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0')) {
            $digits = '212'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '212'.$digits;
        }

        return $digits;
    }
}
