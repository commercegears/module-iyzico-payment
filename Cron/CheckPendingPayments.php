<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\LockBusyException;
use CommerceGears\IyzicoPayment\Model\Log;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\ResultProcessor;
use CommerceGears\IyzicoPayment\Model\StatusChecker;

/**
 * Every 5 minutes: finishes orders whose callback never came (customer closed the tab, network error) and orders
 * held in iyzico's fraud review. Works also when the method has been switched off since the order was placed.
 *
 * Waiting orders are checked once they are older than "Cancel Unpaid Orders After": paid ones are invoiced,
 * unpaid ones cancelled, ones still under way at iyzico (bank transfer) kept. Batches of 50 per run; each order is
 * locked while it is checked.
 */
class CheckPendingPayments
{
    private const BATCH = 50;
    private const MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly CollectionFactory $orderCollectionFactory,
        private readonly OrderStatusService $statusService,
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly Log $log
    ) {
    }

    public function execute(): void
    {
        $this->checkWaiting();
        $this->checkReview();
    }

    /**
     * @return array<string, int> outcome => count
     */
    public function checkWaiting(?int $limit = null): array
    {
        $counts = [];
        $collection = $this->collection(Order::STATE_PENDING_PAYMENT, $limit ?? self::BATCH)
            ->addFieldToFilter('main_table.created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - 35 * 60)]);
        foreach ($collection as $order) {
            $timeout = $this->config->getPendingTimeout((int)$order->getStoreId());
            if (strtotime((string)$order->getCreatedAt() . ' UTC') > time() - $timeout * 60) {
                continue;
            }
            $outcome = $this->run(fn() => $this->statusService->check((int)$order->getId(), StatusChecker::SOURCE_CRON, true), $order);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
            if (in_array($outcome, [ResultProcessor::OUTCOME_PENDING, ResultProcessor::OUTCOME_UNKNOWN, 'busy'], true)) {
                // Still under way at iyzico (or no answer): keep Magento's own "Pending Payment Order Lifetime"
                // clean-up from cancelling it; this job decides once iyzico has answered.
                $this->touch((int)$order->getId());
            }
        }
        return $counts;
    }

    /**
     * @return array<string, int> outcome => count
     */
    public function checkReview(?int $limit = null): array
    {
        $counts = [];
        foreach ($this->collection(Order::STATE_PAYMENT_REVIEW, $limit ?? self::BATCH) as $order) {
            $outcome = $this->run(fn() => $this->statusService->checkReview((int)$order->getId(), StatusChecker::SOURCE_CRON), $order);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        return $counts;
    }

    private function collection(string $state, int $limit): \Magento\Sales\Model\ResourceModel\Order\Collection
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->join(
            ['payment' => $collection->getTable('sales_order_payment')],
            'payment.parent_id = main_table.entity_id',
            []
        )->where('payment.method = ?', Config::METHOD_CODE);
        $collection->addFieldToFilter('main_table.state', $state)
            ->addFieldToFilter('main_table.created_at', ['gt' => gmdate('Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * 86400)])
            ->setOrder('main_table.created_at', 'ASC')
            ->setPageSize($limit)
            ->setCurPage(1);
        return $collection;
    }

    private function touch(int $orderId): void
    {
        $connection = $this->resource->getConnection('sales');
        $connection->update(
            $this->resource->getTableName('sales_order', 'sales'),
            ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => $orderId]
        );
    }

    private function run(callable $check, Order $order): string
    {
        try {
            return (string)$check();
        } catch (LockBusyException) {
            return 'busy';
        } catch (\Throwable $e) {
            $this->log->error('Scheduled check failed', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            return ResultProcessor::OUTCOME_UNKNOWN;
        }
    }
}
