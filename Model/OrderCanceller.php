<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Cancels an unpaid order (stock is released by Magento's cancel) with an order comment.
 * Only called after iyzico confirmed there is no payment, inside OrderLock.
 */
class OrderCanceller
{
    public function __construct(private readonly OrderRepositoryInterface $orderRepository)
    {
    }

    public function cancel(Order $order, Phrase $comment): bool
    {
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT && $order->getState() !== Order::STATE_NEW) {
            return false;
        }
        $order->getPayment()->setData(PaymentInfo::FLAG_SKIP_CANCEL_CHECK, true);
        $cancelled = false;
        if ($order->canCancel()) {
            $order->cancel();
            $cancelled = $order->getState() === Order::STATE_CANCELED;
        }
        $order->addCommentToStatusHistory($comment);
        $this->orderRepository->save($order);
        return $cancelled;
    }
}
