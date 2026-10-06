<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * One Checkout Form opened for an order (token) and what came of it.
 */
class Transaction extends AbstractModel
{
    public const STATUS_STARTED = 'started';
    public const STATUS_PAID = 'paid';
    public const STATUS_REVIEW = 'review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_MISMATCH = 'mismatch';

    protected $_eventPrefix = 'softaware_iyzico_transaction';

    protected function _construct()
    {
        $this->_init(ResourceModel\Transaction::class);
    }

    public function getToken(): string
    {
        return (string)$this->getData('token');
    }

    public function getOrderId(): int
    {
        return (int)$this->getData('order_id');
    }

    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    public function getPaymentId(): string
    {
        return (string)$this->getData('payment_id');
    }

    public function isInFrame(): bool
    {
        return (bool)$this->getData('in_frame');
    }

    public function isTokenUsable(int $marginSeconds = 300): bool
    {
        $expires = (string)$this->getData('token_expires_at');
        return $this->getStatus() === self::STATUS_STARTED
            && $expires !== ''
            && strtotime($expires . ' UTC') - $marginSeconds > time()
            && (string)$this->getData('payment_page_url') !== '';
    }
}
