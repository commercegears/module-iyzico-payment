<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Api\PaymentUrlManagementInterface;

/**
 * The order must belong to the caller (customer token, or the guest cart it was placed from), use iyzico and still
 * wait for payment. Every other case answers "not found", so order IDs cannot be probed.
 */
class PaymentUrlManagement implements PaymentUrlManagementInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly UrlProvider $urlProvider
    ) {
    }

    public function getForCustomer(int $customerId, int $orderId): string
    {
        $order = $this->load($orderId);
        if (!$customerId || (int)$order->getCustomerId() !== $customerId || $order->getCustomerIsGuest()) {
            throw $this->notFound();
        }
        return $this->urlProvider->getPaymentPageUrl((int)$order->getStoreId(), (int)$order->getId());
    }

    public function getForGuest(string $cartId, int $orderId): string
    {
        $order = $this->load($orderId);
        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);
        } catch (\Exception) {
            throw $this->notFound();
        }
        if (!$quoteId || (int)$order->getQuoteId() !== (int)$quoteId || !$order->getCustomerIsGuest()) {
            throw $this->notFound();
        }
        return $this->urlProvider->getPaymentPageUrl((int)$order->getStoreId(), (int)$order->getId());
    }

    private function load(int $orderId): Order
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception) {
            throw $this->notFound();
        }
        if (!$order instanceof Order
            || $order->getPayment()?->getMethod() !== Config::METHOD_CODE
            || !in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true)
        ) {
            throw $this->notFound();
        }
        return $order;
    }

    private function notFound(): NoSuchEntityException
    {
        return new NoSuchEntityException(__('No order waiting for an iyzico payment was found.'));
    }
}
