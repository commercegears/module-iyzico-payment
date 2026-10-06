<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

/**
 * Money helpers. Amounts are compared in minor units (cents/kuruş) so float noise never matters.
 */
class Amount
{
    /** Two decimals with a dot, as sent to iyzico ("1234.50"). */
    public static function format(float|int|string|null $amount): string
    {
        return number_format(round((float)$amount, 2), 2, '.', '');
    }

    public static function toMinor(float|int|string|null $amount): int
    {
        return (int)round(((float)$amount) * 100);
    }

    public static function fromMinor(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * Price as used in iyzico response signatures: trailing zeros removed ("10.50" -> "10.5", "10.00" -> "10").
     */
    public static function trimZeros(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $text = is_string($amount) && is_numeric($amount) && !str_contains(strtolower($amount), 'e')
            ? $amount
            : sprintf('%.8F', (float)$amount);
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
