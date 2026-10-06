<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Registers the module for Hyvä Tailwind source scanning (hyva_config_generate_before).
 */
class RegisterModuleForHyvaConfig implements ObserverInterface
{
    public function __construct(private readonly ComponentRegistrar $componentRegistrar)
    {
    }

    public function execute(Observer $observer): void
    {
        $config = $observer->getData('config');
        $extensions = $config->hasData('extensions') ? $config->getData('extensions') : [];
        $path = (string)$this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'Softaware_IyzicoPayment');
        $extensions[] = ['src' => substr($path, strlen(BP) + 1)];
        $config->setData('extensions', $extensions);
    }
}
