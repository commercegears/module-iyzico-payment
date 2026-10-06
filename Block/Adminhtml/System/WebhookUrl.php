<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Block\Adminhtml\System;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;
use Softaware\IyzicoPayment\Model\UrlProvider;

/**
 * Shows the merchant notification (webhook) URL to register in the iyzico merchant panel.
 */
class WebhookUrl extends Field
{
    public function __construct(
        Context $context,
        private readonly UrlProvider $urlProvider,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $url = $this->urlProvider->getWebhookUrl($this->storeId());
        return '<code style="word-break:break-all">' . $this->escapeHtml($url) . '</code>'
            . '<p class="note"><span>' . $this->escapeHtml(__(
                'Register this URL in the iyzico merchant panel under Settings > Merchant Settings > Merchant Notifications. Ask iyzico (entegrasyon@iyzico.com) to switch on signed notifications (X-IYZ-SIGNATURE-V3). Notifications only make the store ask iyzico again; nothing in them is trusted.'
            )) . '</span></p>';
    }

    private function storeId(): int
    {
        $store = (int)$this->getRequest()->getParam('store');
        if ($store) {
            return $store;
        }
        $website = (int)$this->getRequest()->getParam('website');
        try {
            if ($website) {
                return (int)$this->storeManager->getWebsite($website)->getDefaultStore()->getId();
            }
            return (int)$this->storeManager->getDefaultStoreView()?->getId();
        } catch (\Exception) {
            return 0;
        }
    }
}
