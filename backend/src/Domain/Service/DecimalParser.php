<?php

declare(strict_types=1);

namespace App\Domain\Service;

class DecimalParser
{
    /**
     * Parses user input into an exact, non-negative decimal string with the given
     * scale, or null if it is not a plain decimal that fits the column
     * (numeric(integerDigits + scale, scale)). Rejects exponents, signs, commas
     * and extra decimals instead of truncating them — money is never rounded
     * silently.
     */
    public function parse(mixed $raw, int $integerDigits, int $scale): ?string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }

        $value   = trim($raw);
        $pattern = sprintf('/^\d{1,%d}(?:\.\d{1,%d})?$/', $integerDigits, $scale);

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        return bcadd($value, '0', $scale);
    }
}
