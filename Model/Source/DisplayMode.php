<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use CommerceGears\IyzicoPayment\Model\Config;

class DisplayMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::MODE_REDIRECT, 'label' => __('Redirect to iyzico\'s payment page')],
            ['value' => Config::MODE_IFRAME, 'label' => __('On this store\'s page')],
        ];
    }
}
