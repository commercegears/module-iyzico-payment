<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Api;

/**
 * Payment page URL for headless storefronts: after placing an order with the iyzico method, send the customer to
 * this URL (signed, valid 2 hours). It opens iyzico's Checkout Form for that order.
 *
 * @api
 */
interface PaymentUrlManagementInterface
{
    /**
     * For a signed-in customer's own order.
     *
     * @param int $customerId
     * @param int $orderId
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getForCustomer(int $customerId, int $orderId): string;

    /**
     * For a guest order placed from the given (masked) guest cart.
     *
     * @param string $cartId
     * @param int $orderId
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getForGuest(string $cartId, int $orderId): string;
}
