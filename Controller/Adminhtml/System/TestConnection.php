<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Controller\Adminhtml\System;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Api\Client;
use Softaware\IyzicoPayment\Model\Config;

/**
 * Tests the saved keys with a harmless call (installment rates for an amount of 100, no card number).
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Softaware_IyzicoPayment::config';

    public function __construct(
        Context $context,
        private readonly Client $client,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $storeId = $this->storeId();
        $environment = $this->config->getEnvironment($storeId) === Config::ENV_LIVE ? __('live') : __('sandbox');
        if (!$this->config->hasCredentials($storeId)) {
            return $this->answer(false, (string)__('No API key and secret key are saved for this scope.'));
        }
        try {
            $response = $this->client->post(Client::PATH_INSTALLMENT, [
                'locale' => 'en',
                'conversationId' => 'connection-test',
                'price' => '100.00',
            ], $storeId, true, 15);
        } catch (ApiException $e) {
            return $this->answer(false, (string)__('iyzico could not be reached: %1', $e->getMessage()));
        }
        if (($response['status'] ?? '') === 'success') {
            return $this->answer(true, (string)__('Connected to iyzico (%1). The keys are valid.', $environment));
        }
        return $this->answer(false, (string)__(
            'iyzico (%1) refused the keys: %2 %3',
            $environment,
            (string)($response['errorCode'] ?? ''),
            mb_substr((string)($response['errorMessage'] ?? ''), 0, 200)
        ));
    }

    private function storeId(): int
    {
        $store = (int)$this->getRequest()->getParam('store');
        if ($store) {
            return $store;
        }
        $website = (int)$this->getRequest()->getParam('website');
        if ($website) {
            try {
                return (int)$this->storeManager->getWebsite($website)->getDefaultStore()->getId();
            } catch (\Exception) {
                return 0;
            }
        }
        return 0;
    }

    private function answer(bool $success, string $message): Json
    {
        return $this->jsonFactory->create()->setData(['success' => $success, 'message' => $message]);
    }
}
