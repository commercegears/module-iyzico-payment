<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * Signed, expiring references to an order for the payment and result pages (HMAC-SHA256 with a key derived from
 * the store's crypt key). They carry no personal data and only work for their purpose until they expire.
 */
class OrderToken
{
    public const PURPOSE_PAY = 'pay';
    public const PURPOSE_RESULT = 'result';

    public function __construct(private readonly DeploymentConfig $deploymentConfig)
    {
    }

    public function create(int $orderId, string $purpose, int $ttl): string
    {
        $payload = $purpose . '.' . $orderId . '.' . (time() + $ttl);
        return $this->encode($payload) . '.' . $this->sign($payload);
    }

    /**
     * @return int|null order ID when the token is valid for this purpose and not expired
     */
    public function verify(string $token, string $purpose): ?int
    {
        if ($token === '' || strlen($token) > 200 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$encoded, $signature] = explode('.', $token);
        $payload = $this->decode($encoded);
        if ($payload === null || !hash_equals($this->sign($payload), $signature)) {
            return null;
        }
        $parts = explode('.', $payload);
        if (count($parts) !== 3 || $parts[0] !== $purpose || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }
        return (int)$parts[2] >= time() ? (int)$parts[1] : null;
    }

    private function sign(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->key(), true)), '+/', '-_'), '=');
    }

    private function key(): string
    {
        $keys = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        $keys = preg_split('/\s+/', trim($keys)) ?: [''];
        return hash('sha256', 'softaware_iyzico_order_token|' . end($keys), true);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
