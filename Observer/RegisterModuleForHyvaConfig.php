<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Observer;

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
        $path = (string)$this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'CommerceGears_IyzicoPayment');
        $extensions[] = ['src' => substr($path, strlen(BP) + 1)];
        $config->setData('extensions', $extensions);
    }
}
