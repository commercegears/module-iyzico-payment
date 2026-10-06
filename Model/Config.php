<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to Stores > Configuration > Sales > Payment Methods > iyzico (payment/softaware_iyzico/*).
 *
 * All values are read in the scope of the order's (or quote's) store, so websites can use their own iyzico
 * merchant account and environment.
 */
class Config
{
    public const METHOD_CODE = 'softaware_iyzico';
    private const PREFIX = 'payment/' . self::METHOD_CODE . '/';

    public const ENV_SANDBOX = 'sandbox';
    public const ENV_LIVE = 'live';

    public const MODE_REDIRECT = 'redirect';
    public const MODE_IFRAME = 'iframe';

    /** Currencies accepted by the Checkout Form (docs: CF-Initialize "currency"). */
    public const SUPPORTED_CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP', 'NOK', 'CHF'];

    /** Installment counts offered in the configuration. */
    public const INSTALLMENT_OPTIONS = [1, 2, 3, 4, 6, 9, 12];

    private const BASE_URLS = [
        self::ENV_SANDBOX => 'https://sandbox-api.iyzipay.com',
        self::ENV_LIVE => 'https://api.iyzipay.com',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getValue(string $field, ?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->getValue('active', $storeId) === '1';
    }

    public function getEnvironment(?int $storeId = null): string
    {
        return $this->getValue('environment', $storeId) === self::ENV_LIVE ? self::ENV_LIVE : self::ENV_SANDBOX;
    }

    public function getBaseUrl(?int $storeId = null): string
    {
        return self::BASE_URLS[$this->getEnvironment($storeId)];
    }

    public function getApiKey(?int $storeId = null): string
    {
        return $this->decrypt($this->getValue('api_key', $storeId));
    }

    public function getSecretKey(?int $storeId = null): string
    {
        return $this->decrypt($this->getValue('secret_key', $storeId));
    }

    public function hasCredentials(?int $storeId = null): bool
    {
        return $this->getApiKey($storeId) !== '' && $this->getSecretKey($storeId) !== '';
    }

    public function getDisplayMode(?int $storeId = null): string
    {
        return $this->getValue('display_mode', $storeId) === self::MODE_IFRAME ? self::MODE_IFRAME : self::MODE_REDIRECT;
    }

    /**
     * @return int[] Installment counts to offer; empty = what is set up in the iyzico panel.
     */
    public function getInstallments(?int $storeId = null): array
    {
        $values = array_map('intval', array_filter(explode(',', $this->getValue('installments', $storeId))));
        $values = array_values(array_unique(array_intersect($values, self::INSTALLMENT_OPTIONS)));
        sort($values);
        return $values;
    }

    /**
     * Language of the payment page and of iyzico's messages: "tr" or "en".
     */
    public function getLocale(?int $storeId = null): string
    {
        $language = $this->getValue('language', $storeId);
        if ($language === 'tr' || $language === 'en') {
            return $language;
        }
        $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $storeId);
        return str_starts_with(strtolower($locale), 'tr') ? 'tr' : 'en';
    }

    public function isInstallmentTableEnabled(?int $storeId = null): bool
    {
        return $this->isActive($storeId) && $this->getValue('installment_table', $storeId) === '1';
    }

    public function getReviewStatus(?int $storeId = null): string
    {
        return $this->getValue('review_status', $storeId) ?: 'payment_review';
    }

    /**
     * Minutes after which an unpaid order is checked with iyzico and cancelled if iyzico has no payment.
     * Never less than the 30-minute lifetime of a Checkout Form token plus a margin.
     */
    public function getPendingTimeout(?int $storeId = null): int
    {
        $minutes = (int)$this->getValue('pending_timeout', $storeId);
        return $minutes > 0 ? max(35, min($minutes, 10080)) : 60;
    }

    /**
     * The attribute mapped to the T.C. Kimlik No, as [type, code] ("customer"/"address"), or null when not mapped.
     *
     * @return array{0: string, 1: string}|null
     */
    public function getIdentityAttribute(?int $storeId = null): ?array
    {
        $value = $this->getValue('identity_attribute', $storeId);
        if (!preg_match('/^(customer|address):([a-z][a-z0-9_]{0,59})$/', $value, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }

    public function getDefaultIdentityNumber(?int $storeId = null): string
    {
        $value = preg_replace('/\D/', '', $this->getValue('default_identity_number', $storeId));
        return strlen((string)$value) >= 5 ? substr((string)$value, 0, 50) : '11111111111';
    }

    public function isDebug(?int $storeId = null): bool
    {
        return $this->getValue('debug', $storeId) === '1';
    }

    public function isCurrencySupported(string $currencyCode): bool
    {
        return in_array(strtoupper($currencyCode), self::SUPPORTED_CURRENCIES, true);
    }

    private function decrypt(string $value): string
    {
        if ($value === '' || preg_match('/^\*+$/', $value)) {
            return '';
        }
        return trim((string)$this->encryptor->decrypt($value));
    }
}
