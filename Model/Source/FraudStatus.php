<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class FraudStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('Approved')],
            ['value' => '0', 'label' => __('In review')],
            ['value' => '-1', 'label' => __('Rejected')],
        ];
    }
}
