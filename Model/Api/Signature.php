<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Api;

use Softaware\IyzicoPayment\Model\Amount;

/**
 * iyzico HMAC-SHA256 signatures (docs: "HMACSHA256 Auth", "Response Signature Validation", "Webhook").
 */
class Signature
{
    /** Response fields that make up the signature, per endpoint, in the documented order. */
    public const RESPONSE_FIELDS = [
        'cf_initialize' => ['conversationId', 'token'],
        'cf_retrieve' => ['paymentStatus', 'paymentId', 'currency', 'basketId', 'conversationId', 'paidPrice', 'price', 'token'],
        'payment_detail' => ['paymentId', 'currency', 'basketId', 'conversationId', 'paidPrice', 'price'],
        'refund' => ['paymentId', 'price', 'currency', 'conversationId'],
    ];

    private const PRICE_FIELDS = ['paidPrice', 'price'];

    /**
     * Authorization header value: "IYZWSv2 " + base64("apiKey:..&randomKey:..&signature:" + HMAC(rnd + path + body)).
     */
    public function authorization(string $apiKey, string $secretKey, string $randomKey, string $path, string $body): string
    {
        $signature = hash_hmac('sha256', $randomKey . $path . $body, $secretKey);
        return 'IYZWSv2 ' . base64_encode('apiKey:' . $apiKey . '&randomKey:' . $randomKey . '&signature:' . $signature);
    }

    /**
     * Expected "signature" of a response: HMAC of the listed fields joined with ":" (prices without trailing zeros).
     */
    public function expectedResponseSignature(string $type, array $response, string $secretKey): string
    {
        $parts = [];
        foreach (self::RESPONSE_FIELDS[$type] as $field) {
            $value = $response[$field] ?? '';
            $parts[] = in_array($field, self::PRICE_FIELDS, true) ? Amount::trimZeros($value) : (string)$value;
        }
        return hash_hmac('sha256', implode(':', $parts), $secretKey);
    }

    /**
     * True when the response carries no signature (nothing to check) or the signature matches.
     */
    public function isResponseValid(string $type, array $response, string $secretKey): bool
    {
        $given = strtolower(trim((string)($response['signature'] ?? '')));
        if ($given === '') {
            return true;
        }
        return hash_equals($this->expectedResponseSignature($type, $response, $secretKey), $given);
    }

    public function hasSignature(array $response): bool
    {
        return trim((string)($response['signature'] ?? '')) !== '';
    }

    /**
     * X-IYZ-SIGNATURE-V3 of a merchant notification (webhook).
     * HPP format (Checkout Form): secretKey + iyziEventType + iyziPaymentId + token + paymentConversationId + status.
     * Direct format: secretKey + iyziEventType + paymentId + paymentConversationId + status.
     */
    public function webhookSignature(array $payload, string $secretKey): string
    {
        if (isset($payload['token']) && (string)$payload['token'] !== '') {
            $message = $secretKey
                . ($payload['iyziEventType'] ?? '')
                . ($payload['iyziPaymentId'] ?? '')
                . $payload['token']
                . ($payload['paymentConversationId'] ?? '')
                . ($payload['status'] ?? '');
        } else {
            $message = $secretKey
                . ($payload['iyziEventType'] ?? '')
                . ($payload['paymentId'] ?? '')
                . ($payload['paymentConversationId'] ?? '')
                . ($payload['status'] ?? '');
        }
        return hash_hmac('sha256', $message, $secretKey);
    }

    public function isWebhookValid(array $payload, string $header, string $secretKey): bool
    {
        return $header !== '' && hash_equals($this->webhookSignature($payload, $secretKey), strtolower(trim($header)));
    }
}
