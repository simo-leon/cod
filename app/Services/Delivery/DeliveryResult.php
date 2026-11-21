<?php

namespace App\Services\Delivery;

/**
 * Result of a createParcel() call. Never contains credentials.
 */
class DeliveryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $parcelCode = null,
        public readonly ?string $status = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
        public readonly bool $usedFormEncoding = false,
    ) {}

    public static function ok(?string $parcelCode, ?string $status = null, array $raw = [], bool $usedFormEncoding = false): self
    {
        return new self(true, $parcelCode, $status, null, $raw, $usedFormEncoding);
    }

    public static function failed(string $error, array $raw = [], bool $usedFormEncoding = false): self
    {
        return new self(false, null, null, $error, $raw, $usedFormEncoding);
    }
}
