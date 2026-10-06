<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Softaware\IyzicoPayment\Model\Api\Signature;
use Softaware\IyzicoPayment\Model\Config;
use Softaware\IyzicoPayment\Model\LockBusyException;
use Softaware\IyzicoPayment\Model\Log;
use Softaware\IyzicoPayment\Model\OrderStatusService;
use Softaware\IyzicoPayment\Model\StatusChecker;
use Softaware\IyzicoPayment\Model\TransactionRepository;

/**
 * iyzico merchant notifications (webhook) and fraud review notifications, server to server (JSON POST).
 *
 * A notification is only a hint: when iyzico signs it (X-IYZ-SIGNATURE-V3, switched on by iyzico for the merchant
 * account) the signature must be valid, and in every case the payment's real state is fetched from the API before
 * anything changes. Unknown payments are acknowledged and ignored. Answers 200 quickly; 503 asks iyzico to retry.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_BODY = 20000;

    public function __construct(
        private readonly Http $request,
        private readonly TransactionRepository $transactions,
        private readonly OrderFactory $orderFactory,
        private readonly OrderStatusService $statusService,
        private readonly Signature $signature,
        private readonly Config $config,
        private readonly JsonFactory $jsonFactory,
        private readonly Log $log
    ) {
    }

    public function execute(): ResultInterface
    {
        $body = (string)$this->request->getContent();
        $payload = strlen($body) <= self::MAX_BODY ? json_decode($body, true) : null;
        if (!is_array($payload)) {
            return $this->answer(400, 'invalid');
        }
        $payload = array_map(static fn($value) => is_scalar($value) ? (string)$value : '', $payload);
        $token = $payload['token'] ?? '';
        $paymentId = $payload['iyziPaymentId'] ?? ($payload['paymentId'] ?? '');
        $conversationId = $payload['paymentConversationId'] ?? '';

        $transaction = $token !== '' ? $this->transactions->getByToken($token) : null;
        $transaction ??= $paymentId !== '' ? $this->transactions->getByPaymentId($paymentId) : null;
        $order = $transaction ? null : $this->orderByNumber($conversationId);
        if (!$transaction && !$order) {
            $this->log->info('Notification for a payment this store does not know', ['paymentId' => $paymentId]);
            return $this->answer(200, 'ignored');
        }
        $orderId = $transaction ? $transaction->getOrderId() : (int)$order->getId();
        $storeId = $transaction ? (int)$transaction->getData('store_id') : (int)$order->getStoreId();

        $header = trim((string)$this->request->getHeader('X-IYZ-SIGNATURE-V3'));
        if ($header !== '' && !$this->signature->isWebhookValid($payload, $header, $this->config->getSecretKey($storeId))) {
            $this->log->error('Notification with an invalid signature rejected', ['paymentId' => $paymentId]);
            return $this->answer(401, 'invalid signature');
        }

        $isFraudNotification = isset($payload['fraudStatus']) && $token === '';
        if ($header === '' && !$isFraudNotification && $this->transactions->wasCheckedRecently($orderId, 10)) {
            // Unsigned and the order was just checked: nothing new to learn, and no way to flood iyzico.
            // (Fraud notifications only reach iyzico for orders in payment review, see checkReview.)
            return $this->answer(200, 'ok');
        }
        try {
            if ($isFraudNotification) {
                $outcome = $this->statusService->checkReview($orderId, StatusChecker::SOURCE_WEBHOOK);
            } elseif ($transaction && $transaction->getToken() === $token) {
                $outcome = $this->statusService->handleToken($transaction, StatusChecker::SOURCE_WEBHOOK, false);
            } else {
                $outcome = $this->statusService->check($orderId, StatusChecker::SOURCE_WEBHOOK, false);
            }
        } catch (LockBusyException) {
            return $this->answer(503, 'busy');
        } catch (\Throwable $e) {
            $this->log->error('Notification could not be handled', ['paymentId' => $paymentId, 'error' => $e->getMessage()]);
            return $this->answer(500, 'error');
        }
        $this->log->debug('Notification handled', [
            'event' => $payload['iyziEventType'] ?? '', 'status' => $payload['status'] ?? '', 'outcome' => $outcome,
            'signed' => $header !== '',
        ], $storeId);
        return $this->answer(200, 'ok');
    }

    private function orderByNumber(string $incrementId): ?Order
    {
        if ($incrementId === '' || strlen($incrementId) > 50) {
            return null;
        }
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        return $order->getId() && $order->getPayment()?->getMethod() === Config::METHOD_CODE ? $order : null;
    }

    private function answer(int $code, string $status): ResultInterface
    {
        return $this->jsonFactory->create()->setHttpResponseCode($code)->setData(['status' => $status]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
