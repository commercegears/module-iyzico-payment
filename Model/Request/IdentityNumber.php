<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Request;

use Magento\Sales\Api\Data\OrderInterface;
use Softaware\IyzicoPayment\Model\Config;

/**
 * Buyer identity number for iyzico: a valid T.C. Kimlik No (11 digits with checksum) from the order's tax/VAT
 * fields, otherwise the configured default.
 */
class IdentityNumber
{
    public function __construct(private readonly Config $config)
    {
    }

    public function resolve(OrderInterface $order): string
    {
        $candidates = [(string)$order->getCustomerTaxvat()];
        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if ($address) {
                $candidates[] = (string)$address->getVatId();
            }
        }
        foreach ($candidates as $candidate) {
            $digits = (string)preg_replace('/\D/', '', $candidate);
            if (self::isValidTckn($digits)) {
                return $digits;
            }
        }
        return $this->config->getDefaultIdentityNumber((int)$order->getStoreId());
    }

    public static function isValidTckn(string $value): bool
    {
        if (!preg_match('/^[1-9]\d{10}$/', $value)) {
            return false;
        }
        $d = array_map('intval', str_split($value));
        $odd = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
        $even = $d[1] + $d[3] + $d[5] + $d[7];
        $tenth = (($odd * 7) - $even) % 10;
        if ($tenth < 0) {
            $tenth += 10;
        }
        return $tenth === $d[9] && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
    }
}
