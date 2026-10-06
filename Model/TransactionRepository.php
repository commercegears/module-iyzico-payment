<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Softaware\IyzicoPayment\Model\ResourceModel\Transaction as TransactionResource;
use Softaware\IyzicoPayment\Model\ResourceModel\Transaction\CollectionFactory;

/**
 * Loads and saves iyzico payment rows (softaware_iyzico_transaction).
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
