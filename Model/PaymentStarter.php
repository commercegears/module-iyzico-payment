<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Api\Client;
use Softaware\IyzicoPayment\Model\Api\Signature;
use Softaware\IyzicoPayment\Model\Request\CheckoutFormRequest;

/**
 * Opens iyzico's Checkout Form for an unpaid order. A still valid form of the same order is reused, so opening the
 * payment page again (refresh, back button, second tab) shows the same form instead of a new one. Must run inside
 * OrderLock.
 */
class PaymentStarter
{
    /** Payment forms per order; protects the API and stops endless retries. */
    public const MAX_FORMS_PER_ORDER = 15;
    private const DEFAULT_TOKEN_LIFETIME = 1800;

    public function __construct(
        private readonly CheckoutFormRequest $requestBuilder,
        private readonly Client $client,
        private readonly Signature $signature,
        private readonly Config $config,
        private readonly TransactionRepository $transactions,
        private readonly UrlProvider $urlProvider,
        private readonly DateTime $dateTime,
        private readonly Log $log
    ) {
    }

    public function findReusable(Order $order, bool $inFrame): ?Transaction
    {
        foreach ($this->transactions->getForOrder((int)$order->getId(), 5) as $transaction) {
            if ($transaction->isTokenUsable()
                && $transaction->isInFrame() === $inFrame
                && Amount::toMinor($transaction->getData('amount')) === Amount::toMinor($order->getGrandTotal())
                && (string)$transaction->getData('currency') === (string)$order->getOrderCurrencyCode()
                && (string)$transaction->getData('environment') === $this->config->getEnvironment((int)$order->getStoreId())
            ) {
                return $transaction;
            }
        }
        return null;
    }

    public function hasOpenForms(Order $order): bool
    {
        foreach ($this->transactions->getForOrder((int)$order->getId(), 10) as $transaction) {
            if ($transaction->getStatus() === Transaction::STATUS_STARTED) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws PaymentStartException iyzico refused the request (customer-facing message included)
     * @throws ApiException iyzico could not be reached
     * @throws LocalizedException
     */
    public function start(Order $order, bool $inFrame): Transaction
    {
        $storeId = (int)$order->getStoreId();
        if ($this->transactions->countForOrder((int)$order->getId()) >= self::MAX_FORMS_PER_ORDER) {
            throw new PaymentStartException(
                __('This order has had too many payment attempts. Please place a new order or contact us.'),
                'too_many_attempts'
            );
        }
        if (!$this->config->isCurrencySupported((string)$order->getOrderCurrencyCode())) {
            throw new PaymentStartException(
                __('iyzico does not accept payments in %1.', $order->getOrderCurrencyCode()),
                'currency'
            );
        }

        $request = $this->requestBuilder->build($order, $this->urlProvider->getCallbackUrl($storeId));
        $response = $this->client->post(Client::PATH_CF_INITIALIZE, $request, $storeId);

        $token = (string)($response['token'] ?? '');
        $pageUrl = (string)($response['paymentPageUrl'] ?? '');
        if (($response['status'] ?? '') !== 'success' || $token === '' || !self::isIyzicoUrl($pageUrl)) {
            $code = (string)($response['errorCode'] ?? '');
            $this->log->error('Checkout Form could not be opened', [
                'order' => $order->getIncrementId(),
                'errorCode' => $code,
                'errorMessage' => $response['errorMessage'] ?? '',
            ]);
            if (Client::isTemporaryFailure($response)) {
                throw new ApiException('iyzico is temporarily unavailable (' . $code . ').');
            }
            if (Client::isCredentialFailure($response)) {
                $this->log->error('iyzico refused the API keys: check the iyzico payment method settings', ['errorCode' => $code]);
                throw new PaymentStartException(
                    __('Card payment with iyzico is not available at the moment. Please choose another payment method.'),
                    $code,
                    (string)($response['errorMessage'] ?? '')
                );
            }
            throw new PaymentStartException(
                __('iyzico could not start the payment. Please check your address and phone number, or choose another payment method.'),
                $code,
                (string)($response['errorMessage'] ?? '')
            );
        }
        if (!$this->signature->isResponseValid('cf_initialize', $response, $this->config->getSecretKey($storeId))) {
            $this->log->error('Checkout Form initialize signature is not valid', ['order' => $order->getIncrementId()]);
            throw new ApiException('The answer from iyzico could not be verified.');
        }

        $lifetime = (int)($response['tokenExpireTime'] ?? self::DEFAULT_TOKEN_LIFETIME);
        $lifetime = $lifetime > 60 ? min($lifetime, 86400) : self::DEFAULT_TOKEN_LIFETIME;
        $transaction = $this->transactions->create([
            'order_id' => (int)$order->getId(),
            'increment_id' => (string)$order->getIncrementId(),
            'store_id' => $storeId,
            'environment' => $this->config->getEnvironment($storeId),
            'token' => $token,
            'payment_page_url' => mb_substr($pageUrl, 0, 500),
            'token_expires_at' => $this->dateTime->gmtDate('Y-m-d H:i:s', time() + $lifetime),
            'in_frame' => $inFrame ? 1 : 0,
            'status' => Transaction::STATUS_STARTED,
            'amount' => (float)$request['price'],
            'currency' => (string)$request['currency'],
        ]);
        $this->transactions->save($transaction);

        $payment = $order->getPayment();
        $payment->setAdditionalInformation(PaymentInfo::TOKEN, $token);
        $payment->setAdditionalInformation(PaymentInfo::ENVIRONMENT, $this->config->getEnvironment($storeId));
        $payment->save();
        return $transaction;
    }

    /**
     * Only redirect or frame to iyzico's own HTTPS pages (no open redirect even if a response were tampered with).
     */
    public static function isIyzicoUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        return ($parts['scheme'] ?? '') === 'https'
            && (str_ends_with($host, '.iyzipay.com') || str_ends_with($host, '.iyzico.com'));
    }
}
