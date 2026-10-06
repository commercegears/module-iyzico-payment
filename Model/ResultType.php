<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Softaware\IyzicoPayment\Model\Api\Client;

/**
 * Reads a Checkout Form retrieve / payment detail answer.
 *
 * PAID: iyzico took the money. PENDING: the payment is still under way at iyzico (3-D Secure, bank transfer,
 * shopping credit). FAILED: iyzico declined or the form was not paid. UNKNOWN: no clear answer (system error,
 * rate limit, authentication problem) - never acted on as a failure.
 */
class ResultType
{
    public const PAID = 'paid';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    private const PENDING_STATUSES = [
        'INIT_THREEDS', 'CALLBACK_THREEDS', 'INIT_BANK_TRANSFER', 'INIT_CREDIT', 'PENDING_CREDIT', 'INIT_APM',
        'BKM_POS_SELECTED', 'INIT_CONTACTLESS',
    ];

    /** Error codes that say nothing about the payment itself (system error, rate limit, credentials, request). */
    private const NOT_A_RESULT_CODES = ['1', '2', '11', '50000', '1000', '1001'];

    public static function of(array $response): string
    {
        $status = (string)($response['status'] ?? '');
        $paymentStatus = strtoupper((string)($response['paymentStatus'] ?? ''));
        if ($status === 'success' && $paymentStatus === 'SUCCESS' && (string)($response['paymentId'] ?? '') !== '') {
            return self::PAID;
        }
        if (in_array($paymentStatus, self::PENDING_STATUSES, true)) {
            return self::PENDING;
        }
        if (Client::isTemporaryFailure($response)) {
            return self::UNKNOWN;
        }
        if ($paymentStatus === 'FAILURE') {
            return self::FAILED;
        }
        if ($status === 'failure') {
            return in_array((string)($response['errorCode'] ?? ''), self::NOT_A_RESULT_CODES, true)
                ? self::UNKNOWN
                : self::FAILED;
        }
        return self::UNKNOWN;
    }
}
