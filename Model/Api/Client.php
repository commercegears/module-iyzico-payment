<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Softaware\IyzicoPayment\Model\Config;
use Softaware\IyzicoPayment\Model\Log;

/**
 * Small iyzico REST client (IYZWSv2 authentication) on Magento's HTTP client.
 *
 * Returns iyzico's decoded answer, including documented failures ("status": "failure" with errorCode); the caller
 * decides what a failure means. Throws ApiException when there is no usable answer, so an unknown outcome is never
 * mistaken for a declined payment. Only reads are retried (once).
 */
class Client
{
    public const PATH_CF_INITIALIZE = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
    public const PATH_CF_RETRIEVE = '/payment/iyzipos/checkoutform/auth/ecom/detail';
    public const PATH_PAYMENT_DETAIL = '/payment/detail';
    public const PATH_REFUND = '/payment/refund';
    public const PATH_CANCEL = '/payment/cancel';
    public const PATH_INSTALLMENT = '/payment/iyzipos/installment';
    public const PATH_REPORTING_DETAIL = '/v2/reporting/payment/details';

    private const TIMEOUT = 25;
    private const CONNECT_TIMEOUT = 8;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly Signature $signature,
        private readonly Json $json,
        private readonly Log $log
    ) {
    }

    /**
     * POST a JSON request.
     *
     * @param bool $isRead Idempotent read: retried once on a transport error.
     * @param int|null $timeout Seconds; shorter for storefront look-ups.
     * @throws ApiException
     */
    public function post(string $path, array $payload, ?int $storeId, bool $isRead = false, ?int $timeout = null): array
    {
        $body = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->send('POST', $path, '', $body, $storeId, $isRead, $timeout);
    }

    /**
     * GET with a query string (reporting v2). The signature covers the path only (docs/SDK: no body, no query).
     *
     * @throws ApiException
     */
    public function get(string $path, array $query, ?int $storeId, ?int $timeout = null): array
    {
        return $this->send('GET', $path, http_build_query($query), '', $storeId, true, $timeout);
    }

    /**
     * @throws ApiException
     */
    private function send(
        string $method,
        string $path,
        string $query,
        string $body,
        ?int $storeId,
        bool $isRead,
        ?int $timeout
    ): array {
        $apiKey = $this->config->getApiKey($storeId);
        $secretKey = $this->config->getSecretKey($storeId);
        if ($apiKey === '' || $secretKey === '') {
            throw new ApiException('iyzico API key or secret key is not configured.');
        }
        $url = $this->config->getBaseUrl($storeId) . $path . ($query !== '' ? '?' . $query : '');
        $attempts = $isRead ? 2 : 1;
        $lastError = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $randomKey = (string)(int)(microtime(true) * 1000) . random_int(100000000, 999999999);
            /** @var Curl $curl */
            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout ?? self::TIMEOUT);
            $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(self::CONNECT_TIMEOUT, $timeout ?? self::CONNECT_TIMEOUT));
            $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
            $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
            $curl->setHeaders([
                'Authorization' => $this->signature->authorization($apiKey, $secretKey, $randomKey, $path, $body),
                'x-iyzi-rnd' => $randomKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
            $started = microtime(true);
            try {
                if ($method === 'GET') {
                    $curl->get($url);
                } else {
                    $curl->post($url, $body);
                }
                $status = $curl->getStatus();
                $raw = (string)$curl->getBody();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->log->error('iyzico request failed', ['path' => $path, 'attempt' => $attempt, 'error' => $lastError]);
                continue;
            }
            $decoded = $this->decode($raw);
            $this->log->debug('iyzico ' . $method . ' ' . $path, [
                'http' => $status,
                'ms' => (int)((microtime(true) - $started) * 1000),
                'request' => $body !== '' ? $this->decode($body) : $query,
                'response' => $decoded ?? substr($raw, 0, 300),
            ], $storeId);

            if (is_array($decoded) && isset($decoded['status']) && $status < 500) {
                return $decoded;
            }
            $lastError = 'HTTP ' . $status . ($decoded === null ? ', response is not JSON' : ', no status in response');
            $this->log->error('iyzico returned no usable answer', ['path' => $path, 'attempt' => $attempt, 'error' => $lastError]);
        }
        throw new ApiException('iyzico could not be reached: ' . $lastError);
    }

    private function decode(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $data = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * True when iyzico refused the store's credentials or request headers (1000-1099, e.g. 1001 "api credentials
     * are not found"): a configuration problem of the store, it says nothing about a payment.
     */
    public static function isCredentialFailure(array $response): bool
    {
        $code = (string)($response['errorCode'] ?? '');
        return ($response['status'] ?? '') !== 'success' && preg_match('/^10\d\d$/', $code) === 1;
    }

    /**
     * True for documented iyzico failures that only mean "try again later" (system error, rate limit).
     */
    public static function isTemporaryFailure(array $response): bool
    {
        return ($response['status'] ?? '') !== 'success'
            && in_array((string)($response['errorCode'] ?? ''), ['1', '2', '50000'], true);
    }
}
