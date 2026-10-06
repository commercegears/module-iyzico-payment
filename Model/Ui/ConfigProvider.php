<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Softaware\IyzicoPayment\Model\Config;

/**
 * Checkout data for the iyzico payment method renderer.
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly UrlInterface $url,
        private readonly AssetRepository $assetRepository,
        private readonly Config $config
    ) {
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                Config::METHOD_CODE => [
                    'startUrl' => $this->url->getUrl('softaware_iyzico/payment/start', ['_secure' => true]),
                    'logoUrl' => $this->assetRepository->getUrlWithParams('Softaware_IyzicoPayment::images/iyzico-band.svg', ['_secure' => true]),
                    'isSandbox' => $this->config->getEnvironment() === Config::ENV_SANDBOX,
                ],
            ],
        ];
    }
}
