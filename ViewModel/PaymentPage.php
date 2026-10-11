<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\ViewModel;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use CommerceGears\IyzicoPayment\Model\PageState;

/**
 * Data for the payment page and the result page (Luma and Hyvä templates).
 */
class PaymentPage implements ArgumentInterface
{
    public function __construct(
        private readonly PageState $state,
        private readonly FormKey $formKey,
        private readonly UrlInterface $url,
        private readonly AssetRepository $assetRepository
    ) {
    }

    public function getState(): string
    {
        return (string)$this->state->getData('state');
    }

    public function getIncrementId(): string
    {
        return (string)$this->state->getData('increment_id');
    }

    public function getAmount(): string
    {
        return (string)$this->state->getData('amount');
    }

    public function getFrameUrl(): string
    {
        return (string)$this->state->getData('frame_url');
    }

    public function getMessage(): string
    {
        return (string)$this->state->getData('message');
    }

    public function getRetryUrl(): string
    {
        return (string)$this->state->getData('retry_url');
    }

    public function getCheckUrl(): string
    {
        return (string)$this->state->getData('check_url');
    }

    public function getOrderToken(): string
    {
        return (string)$this->state->getData('order_token');
    }

    public function getCancelUrl(): string
    {
        return $this->url->getUrl('commercegears_iyzico/payment/cancel', ['_secure' => true]);
    }

    public function getCartUrl(): string
    {
        return $this->url->getUrl('checkout/cart');
    }

    public function getHomeUrl(): string
    {
        return $this->url->getBaseUrl();
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getLogoUrl(): string
    {
        return $this->assetRepository->getUrlWithParams('CommerceGears_IyzicoPayment::images/iyzico-band.svg', ['_secure' => true]);
    }
}
