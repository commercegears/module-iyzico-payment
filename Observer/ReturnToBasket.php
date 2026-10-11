<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\Log;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\ResultProcessor;
use CommerceGears\IyzicoPayment\Model\StatusChecker;
use CommerceGears\IyzicoPayment\Model\TransactionRepository;
use CommerceGears\IyzicoPayment\Model\UrlProvider;

/**
 * The customer left iyzico's page (back button, closed the form) and opened the basket or checkout while their
 * just placed order still waits for payment: ask iyzico. Paid -> success page. Not paid -> the order is cancelled
 * (stock released) and the basket restored, so they can pay again with any method. Still under way -> left alone.
 *
 * Runs only for that visitor's own last order (checkout session), only on the basket and checkout pages.
 */
class ReturnToBasket implements ObserverInterface
{
    private const MAX_AGE = 86400;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderStatusService $statusService,
        private readonly TransactionRepository $transactions,
        private readonly UrlProvider $urlProvider,
        private readonly ManagerInterface $messageManager,
        private readonly ActionFlag $actionFlag,
        private readonly Http $request,
        private readonly Log $log
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->request->isGet() || $this->request->isAjax() || !$this->checkoutSession->getLastRealOrderId()) {
            return;
        }
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId()
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
            || $order->getPayment()?->getMethod() !== Config::METHOD_CODE
            || strtotime((string)$order->getCreatedAt() . ' UTC') < time() - self::MAX_AGE
        ) {
            return;
        }
        if ($this->transactions->wasCheckedRecently((int)$order->getId(), 15)) {
            return;
        }
        try {
            $outcome = $this->statusService->check((int)$order->getId(), StatusChecker::SOURCE_CUSTOMER, true, 8);
        } catch (\Throwable $e) {
            $this->log->error('Return to basket check failed', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            return;
        }
        if (in_array($outcome, [ResultProcessor::OUTCOME_PAID, ResultProcessor::OUTCOME_REVIEW, ResultProcessor::OUTCOME_ALREADY], true)) {
            /** @var ActionInterface $controller */
            $controller = $observer->getData('controller_action');
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
            /** @var HttpResponse $response */
            $response = $controller->getResponse();
            $response->setRedirect($this->urlProvider->getResultUrl((int)$order->getStoreId(), (int)$order->getId()));
            return;
        }
        if ($outcome === ResultProcessor::OUTCOME_FAILED) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addNoticeMessage(__('Your iyzico payment was not completed, so the order was cancelled. Your basket has been restored: you can pay again or choose another payment method.'));
            /** @var ActionInterface $controller */
            $controller = $observer->getData('controller_action');
            $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
            $controller->getResponse()->setRedirect($this->request->getUriString());
        }
    }
}
