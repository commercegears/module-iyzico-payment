<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use CommerceGears\IyzicoPayment\Model\ResourceModel\Transaction as TransactionResource;
use CommerceGears\IyzicoPayment\Model\ResourceModel\Transaction\CollectionFactory;

/**
 * Loads and saves iyzico payment rows (commercegears_iyzico_transaction).
 */
class TransactionRepository
{
    public function __construct(
        private readonly TransactionFactory $factory,
        private readonly TransactionResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function create(array $data = []): Transaction
    {
        // setData (not the constructor) so the new row counts as changed and is saved.
        $transaction = $this->factory->create();
        $transaction->setData($data);
        return $transaction;
    }

    public function save(Transaction $transaction): Transaction
    {
        $this->resource->save($transaction);
        return $transaction;
    }

    public function getByToken(string $token): ?Transaction
    {
        if ($token === '' || strlen($token) > 100) {
            return null;
        }
        return $this->loadBy('token', $token);
    }

    public function getByPaymentId(string $paymentId): ?Transaction
    {
        if ($paymentId === '' || strlen($paymentId) > 40) {
            return null;
        }
        return $this->loadBy('payment_id', $paymentId);
    }

    /**
     * @return Transaction[] newest first
     */
    public function getForOrder(int $orderId, int $limit = 20): array
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize($limit);
        return array_values($collection->getItems());
    }

    /**
     * True when iyzico was asked about this order within the last $seconds (limits checks that anyone can trigger,
     * such as unsigned notifications or reloading a page).
     */
    public function wasCheckedRecently(int $orderId, int $seconds): bool
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->addFieldToFilter('checked_at', ['gt' => gmdate('Y-m-d H:i:s', time() - $seconds)]);
        return $collection->getSize() > 0;
    }

    public function countForOrder(int $orderId): int
    {
        return (int)$this->collectionFactory->create()->addFieldToFilter('order_id', $orderId)->getSize();
    }

    private function loadBy(string $field, string $value): ?Transaction
    {
        $transaction = $this->factory->create();
        $this->resource->load($transaction, $value, $field);
        return $transaction->getId() ? $transaction : null;
    }
}
