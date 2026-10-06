<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Block;

use Magento\Catalog\Block\Product\View\AbstractView;
use Magento\Catalog\Block\Product\Context;
use Magento\Framework\Stdlib\ArrayUtils;
use Softaware\IyzicoPayment\Model\Config;

/**
 * "Installment options" placeholder on the product page; the table is loaded after the page (FPC-safe).
 */
class InstallmentTable extends AbstractView
{
    public function __construct(
        Context $context,
        ArrayUtils $arrayUtils,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $arrayUtils, $data);
    }

    public function isShown(): bool
    {
        $product = $this->getProduct();
        return $product
            && $this->config->isInstallmentTableEnabled((int)$this->_storeManager->getStore()->getId())
            && $this->_storeManager->getStore()->getCurrentCurrencyCode() === 'TRY';
    }

    public function getEndpointUrl(): string
    {
        return $this->getUrl('softaware_iyzico/installment/table', ['_query' => ['product' => (int)$this->getProduct()->getId()]]);
    }

    public function getScriptUrl(): string
    {
        return $this->getViewFileUrl('Softaware_IyzicoPayment::js/installments.js');
    }
}
