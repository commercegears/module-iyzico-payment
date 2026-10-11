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
