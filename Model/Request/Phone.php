<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Request;

/**
 * Phone numbers in the form iyzico expects (Turkish numbers as +90XXXXXXXXXX, others with a leading + when the
 * customer gave an international number). At most 25 characters.
 */
class Phone
{
    public static function normalise(string $phone, string $countryId): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        $international = str_starts_with($phone, '+') || str_starts_with($phone, '00');
        $digits = (string)preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return '';
        }
        if (strtoupper($countryId) === 'TR' || str_starts_with($digits, '90')) {
            if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
                return '+' . $digits;
            }
            if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                return '+9' . $digits;
            }
            if (strlen($digits) === 10) {
                return '+90' . $digits;
            }
        }
        return substr(($international ? '+' : '') . $digits, 0, 25);
    }
}
