<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Sales\Model\Order;

/**
 * Entry points that take the order lock and then ask iyzico (StatusChecker). Used by the callback, webhook,
 * cron, the customer coming back to the basket, the admin button and the CLI.
 */
class OrderStatusService
{
    public function __construct(
        private readonly OrderLock $orderLock,
        private readonly StatusChecker $checker
    ) {
    }

    /**
     * iyzico sent the customer back (or notified us) for this payment form: fetch its result and apply it.
     */
    public function handleToken(Transaction $transaction, string $source, bool $cancelOnFailure = true): string
    {
        return $this->orderLock->run(
            $transaction->getOrderId(),
            fn(Order $order): string => $this->checker->checkToken($order, $transaction, $source, $cancelOnFailure)
        );
    }

    public function check(int $orderId, string $source, bool $mayCancel, ?int $timeout = null): string
    {
        return $this->orderLock->run(
            $orderId,
            fn(Order $order): string => $this->checker->check($order, $source, $mayCancel, $timeout)
        );
    }

    public function checkReview(int $orderId, string $source): string
    {
        return $this->orderLock->run(
            $orderId,
            fn(Order $order): string => $this->checker->checkReview($order, $source)
        );
    }
}
