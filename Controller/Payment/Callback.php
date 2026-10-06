<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Escaper;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Softaware\IyzicoPayment\Model\Log;
use Softaware\IyzicoPayment\Model\OrderStatusService;
use Softaware\IyzicoPayment\Model\StatusChecker;
use Softaware\IyzicoPayment\Model\TransactionRepository;
use Softaware\IyzicoPayment\Model\UrlProvider;

/**
 * iyzico sends the customer here (POST with the form's token) after the payment, successful or not.
 *
 * Nothing posted is trusted: the token only identifies one of our own payment forms, and the result is fetched from
 * iyzico's API and checked against the order. Then the visitor is redirected (GET) to the result page; from a frame
 * the whole page is redirected. Runs without a session (see NoSessionOnCallback), so it is not CSRF-protected by a
 * form key; a forged POST can only make the store ask iyzico for the real state of its own payment form.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly TransactionRepository $transactions,
        private readonly OrderStatusService $statusService,
        private readonly UrlProvider $urlProvider,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory,
        private readonly AssetRepository $assetRepository,
        private readonly Escaper $escaper,
        private readonly Log $log
    ) {
    }

    public function execute(): ResultInterface
    {
        $token = trim((string)$this->request->getParam('token', ''));
        $transaction = preg_match('/^[A-Za-z0-9\-]{8,100}$/', $token) ? $this->transactions->getByToken($token) : null;
        if (!$transaction) {
            $this->log->error('Callback for an unknown payment form', ['token' => $token]);
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        try {
            $outcome = $this->statusService->handleToken($transaction, StatusChecker::SOURCE_CALLBACK);
            $this->log->debug('Callback handled', ['order' => $transaction->getData('increment_id'), 'outcome' => $outcome], (int)$transaction->getData('store_id'));
        } catch (\Throwable $e) {
            // The result page shows "waiting for confirmation"; cron or the webhook finishes the order.
            $this->log->error('Callback could not be handled', [
                'order' => $transaction->getData('increment_id'), 'error' => $e->getMessage(),
            ]);
        }

        $resultUrl = $this->urlProvider->getResultUrl((int)$transaction->getData('store_id'), $transaction->getOrderId());
        if ($transaction->isInFrame()) {
            return $this->breakOutOfFrame($resultUrl);
        }
        return $this->redirectFactory->create()->setUrl($resultUrl)->setHttpResponseCode(303);
    }

    /**
     * The form was shown in a frame on our page: load the result page in the whole window.
     */
    private function breakOutOfFrame(string $url): ResultInterface
    {
        $script = $this->assetRepository->getUrlWithParams('Softaware_IyzicoPayment::js/breakout.js', ['_secure' => true]);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
            . '<meta name="referrer" content="no-referrer"><title>' . $this->escaper->escapeHtml(__('Payment')) . '</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:2em">'
            . '<p><a href="' . $this->escaper->escapeUrl($url) . '" target="_top">'
            . $this->escaper->escapeHtml(__('Continue')) . '</a></p>'
            . '<script src="' . $this->escaper->escapeUrl($script) . '" data-url="' . $this->escaper->escapeHtmlAttr($url) . '"></script>'
            . '</body></html>';
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setContents($html);
        return $result;
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
