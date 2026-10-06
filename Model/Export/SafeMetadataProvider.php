<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Export;

use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Ui\Model\Export\MetadataProvider;

/**
 * Grid CSV export that neutralises spreadsheet formulas (text starting with = + - @ tab or carriage return).
 * Plain numbers are left as they are.
 */
class SafeMetadataProvider extends MetadataProvider
{
    public function getRowData(DocumentInterface $document, $fields, $options): array
    {
        return array_map(
            static function ($value) {
                if (is_string($value) && $value !== '' && !is_numeric($value)
                    && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
                ) {
                    return "'" . $value;
                }
                return $value;
            },
            parent::getRowData($document, $fields, $options)
        );
    }
}
