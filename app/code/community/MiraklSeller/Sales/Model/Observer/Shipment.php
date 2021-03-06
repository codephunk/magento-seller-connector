<?php

use Mirakl\MMP\Common\Domain\Order\OrderState;

class MiraklSeller_Sales_Model_Observer_Shipment extends MiraklSeller_Sales_Model_Observer_Abstract
{
    /**
     * Intercept order shipping from back office
     *
     * @param   Varien_Event_Observer   $observer
     */
    public function onSaveShipmentBefore(Varien_Event_Observer $observer)
    {
        if (!$order = $this->_getOrderFromEvent($observer->getEvent())) {
            return; // Do not do anything if it's not an imported Mirakl order
        }

        /** @var Mage_Adminhtml_Sales_OrderController $action */
        $action = $observer->getEvent()->getControllerAction();

        /** @var Mage_Core_Controller_Request_Http $request */
        $request = $action->getRequest();

        $shipmentQtys = $request->getParam('shipment');
        if (empty($shipmentQtys['items']) || !($qtyToShip = array_sum($shipmentQtys['items']))) {
            return;
        }

        $connection  = $this->_getConnectionById($order->getMiraklConnectionId());
        $miraklOrder = $this->_getMiraklOrder($connection, $order->getMiraklOrderId());

        try {
            // Synchronize Magento and Mirakl orders together
            $this->_synchronizeOrder->synchronize($order, $miraklOrder);

            if ($qtyToShip < $this->_getOrderQtyToShip($order)) {
                // Block partial shipping
                $this->_fail($this->__('Partial shipping is not allowed on this Mirakl order.'), $action);
            }
        } catch (\Exception $e) {
            $this->_getSession()->addError($this->__('An error occurred: %s', $e->getMessage()));
        }
    }

    /**
     * Intercept order shipping manual or automatic save
     *
     * @param   Varien_Event_Observer   $observer
     */
    public function onShipmentSaveAfter(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Order_Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();

        $order = $shipment->getOrder();
        if (!$this->_isImportedMiraklOrder($order)) {
            return; // Not a Mirakl order, leave
        }

        if ($this->_getOrderQtyToShip($order) > 0) {
            return; // Order is not totally shipped, abort
        }

        $connection  = $this->_getConnectionById($order->getMiraklConnectionId());
        $miraklOrder = $this->_getMiraklOrder($connection, $order->getMiraklOrderId());

        try {
            /** @var Mage_Sales_Model_Order_Shipment_Track $track */
            foreach ($shipment->getAllTracks() as $track) {
                // Send order tracking info to Mirakl
                $this->_apiOrder->updateOrderTrackingInfo(
                    $connection,
                    $miraklOrder->getId(),
                    'dhl-de', // TODO: needs to be configurable
                    $track->getTitle(),
                    $track->getNumber()
                );
                break; // Stop after the first, Mirakl handles only one tracking
            }

            // Confirm shipment of the order in Mirakl
            if ($miraklOrder->getStatus()->getState() == OrderState::SHIPPING) {
                $this->_apiOrder->shipOrder($connection, $miraklOrder->getId());
            }
        } catch (\Exception $e) {
            $this->_getSession()->addError($this->__('An error occurred: %s', $e->getMessage()));
        }
    }
    
    /**
     * Custom observer to handle shipping data imports by the CodePhunk_XPort module.
     *
     * @event codephunk_xport_shipping_import_after
     * @param Varien_Event_Observer $observer
     * @return $this
     */
    public function onShipmentDataImportAfter(Varien_Event_Observer $observer)
    {
        $orderIds = $observer->getEvent()->getUpdatedOrders();
        if (empty($orderIds)) return $this;
        
        /** @var Mage_Sales_Model_Resource_Order_Collection $orderCollection */
        $orderCollection = Mage::getResourceModel('sales/order_collection');
        $orderCollection
            ->addFieldToFilter('entity_id', array('in' => $orderIds))
            ->addFieldToFilter('mirakl_order_id', array('notnull' => true));
        $connections = array();
        /** @var Mage_Sales_Model_Order $order */
        foreach($orderCollection as $order) {
            $connectionId = $order->getMiraklConnectionId();
            $miraklOrderId = $order->getMiraklOrderId();
            if (!$connectionId) continue;
            if (!isset($connections[$connectionId])) {
                $connections[$connectionId] = Mage::getModel('mirakl_seller_api/connection')->load($connectionId);
            }
            $miraklOrder = $this->_apiOrder->getOrderById($connections[$connectionId], $miraklOrderId);
            $this->_echo('loaded Mirakl order ' . $miraklOrderId . ' with state ' . $miraklOrder->getStatus()->getState());
            if ($miraklOrder->getStatus()->getState() !== OrderState::SHIPPING) {
                $this->_echo('skipping.');
            } else {
                $this->_echo('processing...');
                $tracks = $order->getTracksCollection()->getFirstItem();
                if ($tracks) {
                    $trackingId = $tracks->getNumber();
                    $this->_apiOrder->updateOrderTrackingInfo(
                        $connections[$connectionId],
                        $miraklOrder->getId(),
                        'dhl-de', // TODO: needs to be configurable
                        'DHL-DE', // TODO: needs to be configurable
                        $trackingId
                    );
                    // Confirm shipment of the order in Mirakl
                    $this->_apiOrder->shipOrder($connections[$connectionId], $miraklOrder->getId());
                    $this->_echo('done...');
                } else {
                    $this->_echo('no tracking number found...');
                }
            }
        }
    }
    
    
    /**
     * Returns order total quantity to ship
     *
     * @param   Mage_Sales_Model_Order  $order
     * @return  int
     */
    protected function _getOrderQtyToShip($order)
    {
        $qtyToShip = 0;
        /** @var Mage_Sales_Model_Order_Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $qtyToShip += $item->getQtyToShip();
        }

        return $qtyToShip;
    }
    
    protected function _echo($message)
    {
        Mage::log($message, null, 'mirakl_debug.log');
    }
}