<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * ZATCA Phase 1 — the instant QR (TLV, tags 1–5).
 *
 * Each mandatory field is a Tag-Length-Value record: [tag byte][length byte][value…].
 * The length is a single byte in phase 1, so every value is defensively truncated to
 * 255 UTF-8 bytes — a longer value would wrap the length byte and silently corrupt the
 * QR. strlen/substr operate on bytes in PHP, matching Buffer.subarray in the reference.
 */
class Zatca
{
    /**
     * Build the base64 QR payload from the five mandatory tags.
     */
    public static function qrPayload(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        string $grandTotal,
        string $vatTotal,
    ): string {
        $payload = self::tlv(1, $sellerName)
            .self::tlv(2, $vatNumber)
            .self::tlv(3, $timestamp)
            .self::tlv(4, $grandTotal)
            .self::tlv(5, $vatTotal);

        return base64_encode($payload);
    }

    /**
     * Encode one TLV record, truncating the value to 255 bytes.
     */
    public static function tlv(int $tag, string $value): string
    {
        $value = substr($value, 0, 255);

        return chr($tag).chr(strlen($value)).$value;
    }

    /**
     * Money as two decimals with a dot, no thousands separator (toFixed(2)).
     */
    public static function money(float|int|string $number): string
    {
        return number_format((float) $number, 2, '.', '');
    }

    /**
     * A UTC ISO-8601 timestamp with milliseconds and a Z suffix (toISOString()).
     */
    public static function timestamp(CarbonInterface|string|null $when): string
    {
        $carbon = $when instanceof CarbonInterface ? Carbon::instance($when) : ($when === null ? Carbon::now() : Carbon::parse($when));

        return $carbon->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
