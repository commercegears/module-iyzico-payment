<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Block\Adminhtml\System;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test Connection" button in the payment method settings. Uses the saved keys of the scope being edited.
 */
class TestConnection extends Field
{
    protected $_template = 'CommerceGears_IyzicoPayment::system/test_connection.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getTestUrl(): string
    {
        $params = [];
        foreach (['website', 'store'] as $scope) {
            $value = (int)$this->getRequest()->getParam($scope);
            if ($value) {
                $params[$scope] = $value;
            }
        }
        return $this->getUrl('commercegears_iyzico/system/testConnection', $params);
    }
}
