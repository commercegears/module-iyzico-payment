<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Order number linked to the order page (escaped HTML).
 */
class OrderLink extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        foreach ($dataSource['data']['items'] ?? [] as &$item) {
            $increment = (string)($item['increment_id'] ?? '');
            $orderId = (int)($item['order_id'] ?? 0);
            $item['increment_id_link'] = $orderId
                ? '<a href="' . $this->escaper->escapeUrl($this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId])) . '">'
                    . $this->escaper->escapeHtml($increment) . '</a>'
                : $this->escaper->escapeHtml($increment);
        }
        return $dataSource;
    }
}
