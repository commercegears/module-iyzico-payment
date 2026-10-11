<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Adminhtml\Transaction;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NotFoundException;
use Magento\Ui\Model\Export\ConvertToCsv;

/**
 * CSV export of the iyzico transactions grid (the grid's filters apply). Values a spreadsheet would run as a
 * formula are prefixed with an apostrophe.
 */
class ExportCsv extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_IyzicoPayment::transactions';
    public const NAMESPACE = 'commercegears_iyzico_transaction_listing';

    public function __construct(
        Context $context,
        private readonly ConvertToCsv $converter,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface
    {
        if ($this->getRequest()->getParam('namespace') !== self::NAMESPACE) {
            throw new NotFoundException(__('Page not found.'));
        }
        return $this->fileFactory->create(
            'iyzico-transactions-' . date('Ymd-His') . '.csv',
            $this->converter->getCsvFile(),
            DirectoryList::VAR_DIR
        );
    }
}
