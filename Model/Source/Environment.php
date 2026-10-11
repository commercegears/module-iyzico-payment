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

class Environment implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ENV_SANDBOX, 'label' => __('Sandbox')],
            ['value' => Config::ENV_LIVE, 'label' => __('Live')],
        ];
    }
}
