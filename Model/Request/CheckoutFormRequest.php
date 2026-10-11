<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Request;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Logger as CustomerLogger;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Model\Order;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\PaymentInfo;

/**
 * Checkout Form initialize request for an order (docs: CF-Initialize).
 *
 * basketId and conversationId are the order number; both come back in the result and are checked.
 */
class CheckoutFormRequest
{
    public function __construct(
        private readonly BasketBuilder $basketBuilder,
        private readonly IdentityNumber $identityNumber,
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CustomerLogger $customerLogger,
        private readonly RemoteAddress $remoteAddress,
        private readonly DateTime $dateTime
    ) {
    }

    public function build(Order $order, string $callbackUrl): array
    {
        $storeId = (int)$order->getStoreId();
        $basket = $this->basketBuilder->build($order);
        $billing = $order->getBillingAddress();
        $shipping = $order->getIsVirtual() ? null : $order->getShippingAddress();
        $billing = $billing ?: $shipping;

        $request = [
            'locale' => $this->config->getLocale($storeId),
            'conversationId' => (string)$order->getIncrementId(),
            'price' => $basket['price'],
            'paidPrice' => $basket['price'],
            'currency' => strtoupper((string)$order->getOrderCurrencyCode()),
            'basketId' => (string)$order->getIncrementId(),
            'paymentGroup' => 'PRODUCT',
            'callbackUrl' => $callbackUrl,
            'buyer' => $this->buyer($order, $billing),
            'billingAddress' => $this->address($billing, $order),
        ];
        if ($shipping) {
            $request['shippingAddress'] = $this->address($shipping, $order);
        } elseif ($this->hasPhysicalItems($basket['items'])) {
            $request['shippingAddress'] = $request['billingAddress'];
        }
        $installments = $this->config->getInstallments($storeId);
        if ($installments) {
            $request['enabledInstallments'] = $installments;
        }
        $request['basketItems'] = $basket['items'];
        return $request;
    }

    private function buyer(Order $order, ?OrderAddressInterface $billing): array
    {
        $customerId = (int)$order->getCustomerId();
        $identity = $this->identityNumber->resolveWithSource($order);
        $order->getPayment()?->setAdditionalInformation(PaymentInfo::IDENTITY_SOURCE, $identity['source']);
        $firstname = (string)($billing?->getFirstname() ?: $order->getCustomerFirstname());
        $lastname = (string)($billing?->getLastname() ?: $order->getCustomerLastname());
        $buyer = [
            'id' => $customerId && !$order->getCustomerIsGuest()
                ? (string)$customerId
                : 'guest-' . $order->getIncrementId(),
            'name' => $this->text($firstname, 50, '-'),
            'surname' => $this->text($lastname, 50, '-'),
            'identityNumber' => $identity['value'],
            'email' => mb_substr(trim((string)$order->getCustomerEmail()), 0, 100),
            'gsmNumber' => $this->phone($order),
            'registrationAddress' => $this->street($billing),
            'city' => $this->city($billing),
            'country' => $this->country($billing),
            'ip' => $this->ip($order),
        ];
        $zip = $this->text((string)$billing?->getPostcode(), 20, '');
        if ($zip !== '') {
            $buyer['zipCode'] = $zip;
        }
        if ($customerId && !$order->getCustomerIsGuest()) {
            try {
                $customer = $this->customerRepository->getById($customerId);
                if ($customer->getCreatedAt()) {
                    $buyer['registrationDate'] = $this->dateTime->gmtDate('Y-m-d H:i:s', $customer->getCreatedAt());
                }
            } catch (\Exception) {
                // Customer deleted meanwhile: registration date is optional.
            }
            $lastLogin = $this->customerLogger->get($customerId)->getLastLoginAt();
            if ($lastLogin) {
                $buyer['lastLoginDate'] = $this->dateTime->gmtDate('Y-m-d H:i:s', $lastLogin);
            }
        }
        return $buyer;
    }

    private function address(?OrderAddressInterface $address, Order $order): array
    {
        $name = $address
            ? trim($address->getFirstname() . ' ' . $address->getLastname())
            : trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
        $result = [
            'contactName' => $this->text($name, 100, '-'),
            'city' => $this->city($address),
            'country' => $this->country($address),
            'address' => $this->street($address),
        ];
        $zip = $this->text((string)$address?->getPostcode(), 20, '');
        if ($zip !== '') {
            $result['zipCode'] = $zip;
        }
        return $result;
    }

    private function street(?OrderAddressInterface $address): string
    {
        $street = $address ? implode(' ', array_filter(array_map('trim', (array)$address->getStreet()))) : '';
        $parts = array_filter([$street, $address?->getRegion() ? (string)$address->getRegion() : '']);
        $value = $this->text(implode(', ', $parts), 255, '');
        // iyzico requires at least 5 characters.
        return mb_strlen($value) >= 5 ? $value : $this->text($value . ' ' . $this->city($address), 255, 'Not given');
    }

    private function city(?OrderAddressInterface $address): string
    {
        return $this->text((string)($address?->getCity() ?: $address?->getRegion()), 50, '-');
    }

    private function country(?OrderAddressInterface $address): string
    {
        $code = strtoupper((string)$address?->getCountryId());
        if ($code === '' || $code === 'TR') {
            return 'Turkey';
        }
        $name = class_exists(\Locale::class) ? (string)\Locale::getDisplayRegion('-' . $code, 'en') : '';
        return $name !== '' && $name !== $code ? $name : $code;
    }

    private function phone(Order $order): string
    {
        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if ($address && trim((string)$address->getTelephone()) !== '') {
                $phone = Phone::normalise((string)$address->getTelephone(), (string)$address->getCountryId());
                if ($phone !== '') {
                    return $phone;
                }
            }
        }
        return '';
    }

    private function ip(Order $order): string
    {
        foreach (explode(',', (string)$order->getRemoteIp()) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
        $ip = (string)$this->remoteAddress->getRemoteAddress();
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    private function text(string $value, int $max, string $fallback): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', strip_tags($value)));
        return $value === '' ? $fallback : mb_substr($value, 0, $max);
    }

    private function hasPhysicalItems(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['itemType'] === 'PHYSICAL') {
                return true;
            }
        }
        return false;
    }
}
