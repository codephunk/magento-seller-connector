<?php
require dirname(__DIR__) . '/../abstract.php';

use Mirakl\MMP\Common\Domain\Order\OrderState;

class MiraklSeller_Shell_Shipment_Update extends Mage_Shell_Abstract
{
    /**
     * @var MiraklSeller_Api_Helper_Order
     */
    protected $_apiOrder;
    
    /**
     * @var bool
     */
    protected $_quiet = false;

    /**
     * @return  $this
     */
    protected function _construct()
    {
        $this->_quiet = (bool) $this->getArg('quiet');

        Mage::getModel('mirakl_seller/autoload')->registerAutoload();
    
        $this->_apiOrder = Mage::helper('mirakl_seller_api/order');
    
        return $this;
    }

    /**
     * @param   string  $str
     */
    protected function _echo($str)
    {
        if (!$this->_quiet) {
            echo $str . PHP_EOL; // @codingStandardsIgnoreLine
        }
    }

    /**
     * @param   string  $str
     */
    protected function _fault($str)
    {
        $this->_echo($str);
        exit; // @codingStandardsIgnoreLine
    }

    /**
     * @param   int $listingId
     * @return  $this
     */
    protected function _updateAllShipments()
    {
        /** @var Mage_Sales_Model_Resource_Order_Collection $orderCollection */
        $orderCollection = Mage::getResourceModel('sales/order_collection');
        $orderCollection
            ->addFieldToFilter('status', 'complete')
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
        return $this;
    }

    /**
     * Run script
     */
    public function run()
    {
        if ($listing = $this->getArg('all')) {
            try {
                $this->_updateAllShipments();
            } catch (Exception $e) {
                $this->_fault('An exception has been thrown: ' . $e->getMessage());
            }
        } else {
            $this->_echo($this->usageHelp());
        }
    }

    /**
     * @return  string
     */
    public function usageHelp()
    {
        return <<<USAGE
This script will update all shipment tracking numbers of the specified listing.

Usage: php -f {$_SERVER['SCRIPT_NAME']} -- --listing <listing_id> [options]

  --listing <listing_id>        Identifier of the listing
  --quiet                       Shutdown standard output messages
  --help                        This help

USAGE;
    }

    /**
     * @return  bool
     */
    protected function _validate()
    {
        if (!Mage::isInstalled()) {
            $this->_fault('Please install Magento before running this script.');
        }

        if (!Mage::helper('core')->isModuleEnabled('MiraklSeller_Core')) {
            $this->_fault('Please enable MiraklSeller_Core module before running this script.');
        }

        return true;
    }
}

$shell = new MiraklSeller_Shell_Shipment_Update();
$shell->run();
