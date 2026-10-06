<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\System;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Softaware\IyzicoPayment\Model\Config;

/**
 * Admin system message while iyzico is switched on for a website that has no T.C. Kimlik No attribute mapped:
 * every order there is sent to iyzico with the default identity number.
 */
class IdentityNotMappedMessage implements MessageInterface
{
    private ?array $websites = null;

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    public function getIdentity(): string
    {
        return 'softaware_iyzico_identity_not_mapped_' . implode('_', $this->getWebsites());
    }

    public function isDisplayed(): bool
    {
        return $this->authorization->isAllowed('Softaware_IyzicoPayment::config') && $this->getWebsites() !== [];
    }

    public function getText(): string
    {
        return (string)__(
            'iyzico: no T.C. Kimlik No attribute is mapped for %1, so every order there is sent to iyzico with the default identity number (%2). Map the attribute in <a href="%3">Stores &gt; Configuration &gt; Sales &gt; Payment Methods &gt; iyzico</a>.',
            implode(', ', array_map('htmlspecialchars', array_keys($this->getWebsites()))),
            htmlspecialchars($this->config->getDefaultIdentityNumber()),
            $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'payment'])
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * Websites (name => id) where the method is on and nothing is mapped.
     *
     * @return array<string, int>
     */
    private function getWebsites(): array
    {
        if ($this->websites === null) {
            $this->websites = [];
            foreach ($this->storeManager->getWebsites() as $website) {
                $store = $website->getDefaultStore();
                if (!$store) {
                    continue;
                }
                $storeId = (int)$store->getId();
                if ($this->config->isActive($storeId) && $this->config->getIdentityAttribute($storeId) === null) {
                    $this->websites[(string)$website->getName()] = (int)$website->getId();
                }
            }
        }
        return $this->websites;
    }
}
