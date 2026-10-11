<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Framework\UrlFactory;

/**
 * Storefront URLs of the payment flow, always in the order's store (multi-store safe).
 */
class UrlProvider
{
    public function __construct(
        private readonly UrlFactory $urlFactory,
        private readonly OrderToken $orderToken
    ) {
    }

    public function getCallbackUrl(int $storeId): string
    {
        return $this->url($storeId, 'commercegears_iyzico/payment/callback');
    }

    public function getWebhookUrl(int $storeId): string
    {
        return $this->url($storeId, 'commercegears_iyzico/webhook/index');
    }

    /**
     * Signed result page URL for an order (valid 2 hours). Shows only the order number and payment status.
     */
    public function getResultUrl(int $storeId, int $orderId): string
    {
        return $this->url($storeId, 'commercegears_iyzico/payment/result', [
            '_query' => ['r' => $this->orderToken->create($orderId, OrderToken::PURPOSE_RESULT, 7200)],
        ]);
    }

    /**
     * Signed payment page URL for an order (valid 2 hours), for headless storefronts and links.
     */
    public function getPaymentPageUrl(int $storeId, int $orderId): string
    {
        return $this->url($storeId, 'commercegears_iyzico/payment/start', [
            '_query' => ['t' => $this->orderToken->create($orderId, OrderToken::PURPOSE_PAY, 7200)],
        ]);
    }

    public function url(int $storeId, string $route, array $params = []): string
    {
        $url = $this->urlFactory->create();
        $url->setScope($storeId);
        return $url->getUrl($route, $params + ['_secure' => true, '_nosid' => true]);
    }
}
