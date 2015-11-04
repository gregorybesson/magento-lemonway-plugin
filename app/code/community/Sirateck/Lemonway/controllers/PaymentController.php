<?php
/**
 * Sirateck_Lemonway extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/mit-license.php
 *
 * @category       Sirateck
 * @package        Sirateck_Lemonway
 * @copyright      Copyright (c) 2015
 * @license        http://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Main Controller
 *
 * @category    Sirateck
 * @package     Sirateck_Lemonway
 * @author Kassim Belghait kassim@sirateck.com
 */
class Sirateck_Lemonway_PaymentController extends Mage_Core_Controller_Front_Action {
	
	protected $_order = null;
	
	public function preDispatch()
	{
		parent::preDispatch();
		
		Mage::log($this->getRequest()->getRequestedActionName(),null,"debug_ipn_lw.log");
		Mage::log($this->getRequest()->getMethod(),null,"debug_ipn_lw.log");
		Mage::log($this->getRequest()->getParams(),null,"debug_ipn_lw.log");
		Mage::log($this->getRequest()->getPost(),null,"debug_ipn_lw.log");
		
		$action = $this->getRequest()->getRequestedActionName();
		if(!$this->_validateOperation($action))
		{
			$this->getResponse()->setBody("NOK. Wrong Operation!");
			$this->setFlag('', 'no-dispatch', true);
		}
	}
	
	protected function _validateOperation($action){
		
		$actionToStatus = array("return"=>"3","error"=>"0","cancel"=>"0");
		if(!isset($actionToStatus[$action]))
			return false;
		
		//call directkit to get Webkit Token
		$params = array('transactionMerchantToken'=>$this->_getOrder()->getIncrementId());
		$res = Sirateck_Lemonway_Model_Apikit_Kit::GetMoneyInTransDetails($params);
		
		
		if (isset($res->lwError)){
			Mage::throwException("Error code: " . $res->lwError->CODE . " Message: " . $res->lwError->MSG);
		}
		
		/* @var $op Sirateck_Lemonway_Model_Apikit_Apimodels_Operation */
		foreach ($res->operations as $op) {
			
			if($op->STATUS == $actionToStatus[$action])
			{
				return true;
			}
			
		}
		
		return false;
		
	}
	
	/**
	 * Get singleton of Checkout Session Model
	 *
	 * @return Mage_Checkout_Model_Session
	 */
	protected function _getCheckout()
	{
		return Mage::getSingleton('checkout/session');
	}
	
	/**
	 * @return Mage_Sales_Model_Order
	 */
	protected function _getOrder(){
		if(is_null($this->_order))
		{
			
			$order = Mage::getModel('sales/order')->loadByIncrementId($this->getRequest()->getParam('response_wkToken'));

			if($order->getId())
				$this->_order = $order;
			else
			{
				Mage::logException(new Exception("Order not Found"));
				Mage::throwException("Order not found!");
			}
		}
		
		return $this->_order;
	}
	
	/**
	 *  Create invoice for order
	 *
	 *  @param    Mage_Sales_Model_Order $order
	 *  @return	  boolean Can save invoice or not
	 */
	protected function createInvoice($order)
	{
		if ($order->canInvoice()) {
	
			$version = Mage::getVersion();
			$version = substr($version, 0, 5);
			$version = str_replace('.', '', $version);
			while (strlen($version) < 3) {
				$version .= "0";
			}
	
			if (((int) $version) < 111) {
				$convertor = Mage::getModel('sales/convert_order');
				$invoice = $convertor->toInvoice($order);
				foreach ($order->getAllItems() as $orderItem) {
					if (!$orderItem->getQtyToInvoice()) {
						continue;
					}
					$item = $convertor->itemToInvoiceItem($orderItem);
					$item->setQty($orderItem->getQtyToInvoice());
					$invoice->addItem($item);
				}
				$invoice->collectTotals();
			} else {
				$invoice = $order->prepareInvoice();
			}
	
			$invoice->register()->capture();
			Mage::getModel('core/resource_transaction')
			->addObject($invoice)
			->addObject($invoice->getOrder())
			->save();
			return true;
		}
	
		return false;
	}
	
	
	public function returnAction(){
		$params = $this->getRequest()->getParams();
		if($this->getRequest()->isGet())
		{

			$this->_redirect('checkout/onepage/success');

			return $this;
		}
		elseif($this->getRequest()->isPost())
		{
			if($params['response_code'] == "0000")
			{
				
				$message = $this->__('Transaction success.');
				
				//DATA POST FROM NOTIFICATION
				$order = $this->_getOrder();
				$status = $order->getStatus();
				$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $message);
				if (!$order->getEmailSent()) {
					$order->sendNewOrderEmail();
				}
				
				$this->createInvoice($order);
				
				$order->save();
			}
			else{
				$this->_forward('error');
			}
			
		}
		else 
		{
			die("HTTP Method not Allowed");
		}
		
	}
	
	public function cancelAction(){
		
		//When canceled by user, notification by POST not sended
		//So we cancel with get request
		if($this->getRequest()->isGet())
		{
			$order = $this->_getOrder();
			$status = $order->getStatus();
			
			$message = $this->__('Transaction was canceled by customer');
			$order->addStatusToHistory($status, $message);
			
			if($order->canCancel())
			{
				$order->cancel();
			}

			$order->save();
			
			//Reload products in cart
			Mage::helper('sirateck_lemonway')->reAddToCart($order->getIncrementId());
			
			$this->_getCheckout()->addSuccess($this->__('Your order is canceled.'));
			
			$this->_redirect('checkout/cart');
		}
		else
		{
			die("HTTP Method not Allowed");
		}
		
		return $this;
		
	}
	
	public function errorAction(){
		
		if($this->getRequest()->isGet())
		{
			$this->_redirect('checkout/onepage/failure');
			return $this;
		}
		elseif($this->getRequest()->isPost())
		{

			$post = $this->getRequest()->getPost();
				
			//DATA POST FROM NOTIFICATION
			$order = $this->_getOrder();
				
			$status = $order->getStatus();
			$res_message = $post['response_msg'];
			//$res_code = $post['response_code'];
			$order->addStatusToHistory($status, $res_message);
			
			$message = $this->__('Transaction was canceled.');
			$order->addStatusToHistory($status, $message);
				
			if($order->canCancel())
			{
				$order->cancel();
			}
			try {
				
			$order->save();
			} catch (Exception $e) {
				Mage::logException($e);
				Mage::throwException($e->getMessage());
			}
		}
		else
		{
			die("HTTP Method not Allowed");
		}
		
	}
	
	
}