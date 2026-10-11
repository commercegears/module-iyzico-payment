<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Request;

use Magento\Customer\Model\AddressRegistry;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\Source\IdentityAttribute;

/**
 * Buyer identity number for iyzico: a valid T.C. Kimlik No (11 digits with checksum) read from the attribute
 * mapped in the configuration, otherwise the configured default number.
 */
class IdentityNumber
{
    /** Where the number sent to iyzico came from (kept on the payment, never the number itself). */
    public const SOURCE_MAPPED = 'mapped';
    public const SOURCE_NOT_MAPPED = 'not_mapped';
    public const SOURCE_EMPTY = 'empty';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRegistry $customerRegistry,
        private readonly AddressRegistry $addressRegistry
    ) {
    }

    public function resolve(OrderInterface $order): string
    {
        return $this->resolveWithSource($order)['value'];
    }

    /**
     * @return array{value: string, source: string}
     */
    public function resolveWithSource(OrderInterface $order): array
    {
        $storeId = (int)$order->getStoreId();
        $mapping = $this->config->getIdentityAttribute($storeId);
        if ($mapping === null) {
            return ['value' => $this->config->getDefaultIdentityNumber($storeId), 'source' => self::SOURCE_NOT_MAPPED];
        }
        [$type, $code] = $mapping;
        $candidates = $type === IdentityAttribute::CUSTOMER
            ? $this->fromCustomer($order, $code)
            : $this->fromAddresses($order, $code);
        foreach ($candidates as $candidate) {
            $digits = (string)preg_replace('/\D/', '', (string)$candidate);
            if (self::isValidTckn($digits)) {
                return ['value' => $digits, 'source' => self::SOURCE_MAPPED];
            }
        }
        return ['value' => $this->config->getDefaultIdentityNumber($storeId), 'source' => self::SOURCE_EMPTY];
    }

    /**
     * @return string[]
     */
    private function fromCustomer(OrderInterface $order, string $code): array
    {
        $values = [];
        // The order keeps its own copy of some customer fields (taxvat is also given by guests at checkout).
        $values[] = (string)$order->getData('customer_' . $code);
        $customerId = (int)$order->getCustomerId();
        if ($customerId && !$order->getCustomerIsGuest()) {
            try {
                $values[] = (string)$this->customerRegistry->retrieve($customerId)->getData($code);
            } catch (\Exception) {
                // Customer deleted meanwhile: fall back to the default number.
            }
        }
        return $values;
    }

    /**
     * @return string[]
     */
    private function fromAddresses(OrderInterface $order, string $code): array
    {
        $values = [];
        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if (!$address instanceof OrderAddressInterface) {
                continue;
            }
            $values[] = (string)$address->getData($code);
            // Custom address attributes are not copied to order addresses on Magento Open Source.
            $addressId = (int)$address->getCustomerAddressId();
            if ($addressId) {
                try {
                    $values[] = (string)$this->addressRegistry->retrieve($addressId)->getData($code);
                } catch (\Exception) {
                    // Address deleted meanwhile.
                }
            }
        }
        return $values;
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
