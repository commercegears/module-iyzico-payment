<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Softaware\IyzicoPayment\Model\Transaction;

class TransactionStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Transaction::STATUS_STARTED, 'label' => __('Form opened')],
            ['value' => Transaction::STATUS_PAID, 'label' => __('Paid')],
            ['value' => Transaction::STATUS_REVIEW, 'label' => __('Fraud review')],
            ['value' => Transaction::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => Transaction::STATUS_REJECTED, 'label' => __('Rejected by fraud review')],
            ['value' => Transaction::STATUS_REVERSED, 'label' => __('Given back (duplicate or late)')],
            ['value' => Transaction::STATUS_MISMATCH, 'label' => __('Needs attention')],
        ];
    }
}
