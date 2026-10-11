<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Source;

use Magento\Sales\Model\Config\Source\Order\Status;
use Magento\Sales\Model\Order;

/**
 * Order statuses assigned to the "Payment Review" state.
 */
class ReviewStatus extends Status
{
    /**
     * @var string
     */
    protected $_stateStatuses = Order::STATE_PAYMENT_REVIEW;

    public function toOptionArray(): array
    {
        $options = parent::toOptionArray();
        return array_values(array_filter($options, static fn(array $option): bool => $option['value'] !== ''));
    }
}
