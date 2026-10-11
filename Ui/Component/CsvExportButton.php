<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Ui\Component;

use Magento\Ui\Component\ExportButton;

/**
 * Export button of the iyzico transactions grid: CSV through the module's formula-safe export only.
 */
class CsvExportButton extends ExportButton
{
    public function prepare()
    {
        $config = (array)$this->getData('config');
        $config['options'] = [
            'csv' => [
                'value' => 'csv',
                'label' => __('CSV'),
                'url' => 'commercegears_iyzico/transaction/exportCsv',
            ],
        ];
        $this->setData('config', $config);
        parent::prepare();
    }
}
