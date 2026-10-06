<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Softaware\IyzicoPayment\Model\Config;

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
