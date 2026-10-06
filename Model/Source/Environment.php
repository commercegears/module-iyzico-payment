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

class Environment implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::ENV_SANDBOX, 'label' => __('Sandbox (test payments)')],
            ['value' => Config::ENV_LIVE, 'label' => __('Live')],
        ];
    }
}
