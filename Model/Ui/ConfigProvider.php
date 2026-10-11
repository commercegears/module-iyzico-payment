<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use CommerceGears\IyzicoPayment\Model\Config;

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
                    'startUrl' => $this->url->getUrl('commercegears_iyzico/payment/start', ['_secure' => true]),
                    'logoUrl' => $this->assetRepository->getUrlWithParams('CommerceGears_IyzicoPayment::images/iyzico-band.svg', ['_secure' => true]),
                    'isSandbox' => $this->config->getEnvironment() === Config::ENV_SANDBOX,
                ],
            ],
        ];
    }
}
