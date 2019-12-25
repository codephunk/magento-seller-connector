<?php
use Mage_Sales_Model_Order as Order;
use Mirakl\MMP\Shop\Domain\Order\ShopOrder;

class MiraklSeller_Sales_Model_Synchronize_Shipment
{
    /**
     * @var MiraklSeller_Api_Helper_Order
     */
    protected $_apiOrder;
    

    public function __construct()
    {
        $this->_apiOrder           = Mage::helper('mirakl_seller_api/order');
    }

    /**
     * Returns true if Magento order has been updated or false if nothing has changed (order is up to date with Mirakl)
     *
     * @param   Order       $magentoOrder
     * @param   ShopOrder   $miraklOrder
     * @return  bool
     */
    public function pushTrackingInfo($magentoOrder, $miraklOrder)
    {
            $connection = $this->_getConnectionById($magentoOrder->getMiraklConnectionId());
            foreach ($magentoOrder->getTracksCollection() as $tracking) {
                // Send order tracking info to Mirakl
                $this->_apiOrder->updateOrderTrackingInfo(
                    $connection,
                    $miraklOrder->getId(),
                    'DHL-DE', // TODO: needs to be configurable
                    'DHL-DE', // TODO: needs to be configurable
                    $tracking->getNumber()
                );
                return true; // Stop after the first, Mirakl handles only one tracking
            }

        return false;
    }
    
    /**
     * Retrieves Mirakl connection by id
     *
     * @param   int $connectionId
     * @return  MiraklSeller_Api_Model_Connection
     */
    protected function _getConnectionById($connectionId)
    {
        return Mage::getModel('mirakl_seller_api/connection')->load($connectionId);
    }
}