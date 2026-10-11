<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Psr\Log\LoggerInterface;

/**
 * Writes to var/log/commercegears_iyzico.log. Debug entries only when "Debug Log" is on; errors always.
 *
 * Context data is masked before it is written: API keys, signatures, tokens and payment page URLs are cut to a
 * short prefix, personal data (name, e-mail, phone, identity number, address, IP) is replaced. iyzico never
 * returns card numbers; BIN and last four digits are kept because they are not sensitive card data.
 */
class Log
{
    private const SECRET_KEYS = [
        'token', 'paymentpageurl', 'paywithiyzicopageurl', 'signature', 'authorization', 'apikey', 'secretkey',
        'api_key', 'secret_key', 'x-iyz-signature-v3',
    ];
    private const DROP_KEYS = ['checkoutformcontent'];
    private const PII_KEYS = [
        'name', 'surname', 'email', 'gsmnumber', 'identitynumber', 'registrationaddress', 'address', 'contactname',
        'zipcode', 'ip', 'city', 'buyername', 'buyersurname', 'buyeremail', 'buyergsmnumber', 'registrationdate',
        'lastlogindate',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function debug(string $message, array $context = [], ?int $storeId = null): void
    {
        if ($this->config->isDebug($storeId)) {
            $this->logger->debug($message, $this->mask($context));
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $this->mask($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $this->mask($context));
    }

    public function mask(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string)$key);
            if (in_array($lower, self::DROP_KEYS, true)) {
                $result[$key] = '[removed]';
            } elseif (in_array($lower, self::SECRET_KEYS, true)) {
                $result[$key] = self::maskSecret((string)(is_scalar($value) ? $value : ''));
            } elseif (in_array($lower, self::PII_KEYS, true) && !is_array($value)) {
                $result[$key] = $value === null || $value === '' ? $value : '***';
            } elseif (is_array($value)) {
                $result[$key] = $this->mask($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    public static function maskSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return strlen($value) > 12 ? substr($value, 0, 6) . '…(' . strlen($value) . ')' : '***';
    }
}
