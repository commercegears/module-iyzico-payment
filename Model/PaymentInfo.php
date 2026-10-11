<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

/**
 * Keys of the iyzico data kept in the order payment's additional information.
 */
class PaymentInfo
{
    public const PAYMENT_ID = 'iyzico_payment_id';
    public const TOKEN = 'iyzico_token';
    public const ENVIRONMENT = 'iyzico_environment';
    public const PRICE = 'iyzico_price';
    public const PAID_PRICE = 'iyzico_paid_price';
    public const CURRENCY = 'iyzico_currency';
    public const INSTALLMENT = 'iyzico_installment';
    public const FRAUD_STATUS = 'iyzico_fraud_status';
    public const CARD_ASSOCIATION = 'iyzico_card_association';
    public const CARD_FAMILY = 'iyzico_card_family';
    public const CARD_TYPE = 'iyzico_card_type';
    public const BIN = 'iyzico_bin';
    public const LAST_FOUR = 'iyzico_last_four';
    public const MERCHANT_COMMISSION = 'iyzico_merchant_commission';
    public const IYZICO_FEE = 'iyzico_fee';
    public const PAID_AT = 'iyzico_paid_at';
    /** list of {id, ptx, price, paid, refunded} per basket item (refunds go per paymentTransactionId). */
    public const ITEMS = 'iyzico_items';
    /** list of {at, amount, type, ref} */
    public const REFUNDS = 'iyzico_refunds';
    /** Text shown in the admin panel when something needs the merchant's attention. */
    public const ATTENTION = 'iyzico_attention';
    /** Where the identity number came from: mapped / not_mapped / empty (see Request\IdentityNumber). */
    public const IDENTITY_SOURCE = 'iyzico_identity_source';

    /** Payment flag (not saved): our own code cancels the order, no need to ask iyzico again. */
    public const FLAG_SKIP_CANCEL_CHECK = 'iyzico_skip_cancel_check';
}
