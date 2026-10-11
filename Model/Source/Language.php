<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Language implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto', 'label' => __('Store view language (Turkish for Turkish store views, otherwise English)')],
            ['value' => 'tr', 'label' => __('Turkish')],
            ['value' => 'en', 'label' => __('English')],
        ];
    }
}
