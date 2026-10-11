<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\OrderToken;

/**
 * Finds the order a payment page request is about, without trusting an order ID from the request: either a signed,
 * expiring token for that purpose, or the order this visitor's own checkout session has just placed.
 */
class OrderResolver
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderToken $orderToken,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function resolve(RequestInterface $request, string $param, string $purpose): ?Order
    {
        $token = (string)$request->getParam($param, '');
        $order = null;
        if ($token !== '') {
            $orderId = $this->orderToken->verify($token, $purpose);
            if ($orderId) {
                try {
                    $order = $this->orderRepository->get($orderId);
                } catch (\Exception) {
                    $order = null;
                }
            }
        } else {
            $order = $this->checkoutSession->getLastRealOrder();
        }
        if (!$order instanceof Order || !$order->getId()
            || $order->getPayment()?->getMethod() !== Config::METHOD_CODE
        ) {
            return null;
        }
        return $order;
    }

    public function isSessionOrder(Order $order): bool
    {
        return (string)$this->checkoutSession->getLastRealOrderId() === (string)$order->getIncrementId();
    }
}
