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

class Installments implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        $options = [];
        foreach (Config::INSTALLMENT_OPTIONS as $count) {
            $options[] = [
                'value' => (string)$count,
                'label' => $count === 1 ? __('Single payment') : __('%1 installments', $count),
            ];
        }
        return $options;
    }
}
