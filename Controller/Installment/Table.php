<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Installment;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;
use CommerceGears\IyzicoPayment\Model\InstallmentTable;

/**
 * Installment options for a product page (JSON, loaded after the page; the amount is the product's price in this
 * store, never taken from the request). Empty when switched off, not in Turkish lira or iyzico does not answer.
 */
class Table implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly InstallmentTable $installmentTable,
        private readonly JsonFactory $jsonFactory
    ) {
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $data = ['counts' => [], 'rows' => []];
        $store = $this->storeManager->getStore();
        $currency = (string)$store->getCurrentCurrencyCode();
        $productId = (int)$this->request->getParam('product');
        if ($productId > 0 && $currency === 'TRY') {
            try {
                $product = $this->productRepository->getById($productId, false, (int)$store->getId());
                if ($product->isSalable() && in_array((int)$store->getWebsiteId(), array_map('intval', (array)$product->getWebsiteIds()), true)) {
                    $price = (float)$product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                    $price = (float)$this->priceCurrency->convertAndRound($price, $store, $currency);
                    $data = $this->installmentTable->get($price, (int)$store->getId());
                    $data['currency'] = $currency;
                }
            } catch (\Exception) {
                // No table.
            }
        }
        $result->setHeader('Cache-Control', 'public, max-age=1800', true);
        return $result->setData($data);
    }
}
