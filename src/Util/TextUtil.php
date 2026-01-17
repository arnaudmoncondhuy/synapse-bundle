<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseBundle\Util;

/**
 * Text utilities for sanitizing and processing strings.
 */
final class TextUtil
{
    /**
     * Ensures the string is valid UTF-8.
     * Critical for JSON encoding and API communication.
     */
    public static function sanitizeUtf8(string $input): string
    {
        if (mb_check_encoding($input, 'UTF-8')) {
            return $input;
        }

        return mb_convert_encoding($input, 'UTF-8', 'UTF-8');
    }

    /**
     * Recursively sanitizes all strings in an array.
     */
    public static function sanitizeArrayUtf8(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            $cleanKey = is_string($key) ? self::sanitizeUtf8($key) : $key;
            if (is_string($value)) {
                $cleaned[$cleanKey] = self::sanitizeUtf8($value);
            } elseif (is_array($value)) {
                $cleaned[$cleanKey] = self::sanitizeArrayUtf8($value);
            } else {
                $cleaned[$cleanKey] = $value;
            }
        }
        return $cleaned;
    }
}
