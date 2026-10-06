<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Serialises everything that can change an order's payment state (callback, webhook, cron, customer returning,
 * admin check), so an order is invoiced or cancelled exactly once. Work runs on a freshly loaded order.
 */
class OrderLock
{
    private const TIMEOUT = 25;

    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource
    ) {
    }

    /**
     * @template T
     * @param callable(Order): T $work
     * @return T
     * @throws LockBusyException when another process holds the order for longer than the timeout
     */
    public function run(int $orderId, callable $work, int $timeout = self::TIMEOUT): mixed
    {
        $name = 'softaware_iyzico_order_' . $orderId;
        if (!$this->lockManager->lock($name, $timeout)) {
            throw new LockBusyException(__('The order is being updated. Please try again in a moment.'));
        }
        try {
            return $work($this->loadFresh($orderId));
        } finally {
            $this->lockManager->unlock($name);
        }
    }

    public function loadFresh(int $orderId): Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        if (!$order->getId()) {
            throw new NotFoundException(__('The order no longer exists.'));
        }
        return $order;
    }
}
