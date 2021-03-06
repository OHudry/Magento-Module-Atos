<?php

/**
 * 1997-2015 Quadra Informatique
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0) that is available
 * through the world-wide-web at this URL: http://www.opensource.org/licenses/OSL-3.0
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to modules@quadra-informatique.fr so we can send you a copy immediately.
 *
 * @author Quadra Informatique <modules@quadra-informatique.fr>
 * @copyright 1997-2015 Quadra Informatique
 * @license http://www.opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
class Quadra_Atos_PaymentController extends Mage_Core_Controller_Front_Action
{

    /**
     * Get Atos Api Response Model
     *
     * @return Quadra_Atos_Model_Api_Response
     */
    public function getApiResponse()
    {
        return Mage::getSingleton('atos/api_response');
    }

    /**
     * Get Atos/Sips Standard config
     *
     * @return Quadra_Atos_Model_Config
     */
    public function getConfig()
    {
        return Mage::getSingleton('atos/config');
    }

    /**
     * Get checkout session
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get customer session
     *
     * @return Mage_Customer_Model_Session
     */
    public function getCustomerSession()
    {
        return Mage::getSingleton('customer/session');
    }

    /**
     * Get Atos/Sips Standard session
     *
     * @return Quadra_Atos_Model_Session
     */
    public function getAtosSession()
    {
        return Mage::getSingleton('atos/session');
    }

    /**
     * When a customer chooses Atos/Sips Standard on Checkout/Payment page
     */
    public function redirectAction()
    {
        $this->getAtosSession()->setQuoteId($this->getCheckoutSession()->getLastQuoteId());
        $this->getResponse()->setBody($this->getLayout()->createBlock($this->getMethodInstance()->getRedirectBlockType(), 'atos_redirect')->toHtml());
        $this->getCheckoutSession()->unsQuoteId();
        $this->getCheckoutSession()->unsRedirectUrl();
    }

    /**
     * When a customer cancel payment from Atos/Sips Standard.
     */
    public function cancelAction()
    {
        if (!array_key_exists('DATA', $_REQUEST)) {
            // Set redirect message
            $this->getAtosSession()->setRedirectMessage($this->__('An error occured: no data received.'));
            // Log error
            $errorMessage = $this->__('Customer #%s returned successfully from Atos/Sips payment platform but no data received for order #%s.', $this->getCustomerSession()->getCustomerId(), $this->getCheckoutSession()->getLastRealOrderId());
            Mage::helper('atos')->logError(get_class($this), __FUNCTION__, $errorMessage);
            // Redirect
            $this->_redirect('*/*/failure');
            return;
        }

        // Get Sips Server Response
        $response = $this->_getAtosResponse($_REQUEST['DATA']);

        // Debug
        $this->getMethodInstance()->debugResponse($response['hash'], 'Cancel');

        // Set redirect URL
        $response['redirect_url'] = '*/*/failure';

        // Set redirect message
        $this->getAtosSession()->setRedirectTitle($this->__('Your payment has been rejected'));
        $describedResponse = $this->getApiResponse()->describeResponse($response['hash'], 'array');
        $this->getAtosSession()->setRedirectMessage($this->__('The payment platform has rejected your transaction with the message: <strong>%s</strong>.', $describedResponse['response_code']));

        // Cancel order
        if ($response['hash']['order_id']) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($response['hash']['order_id']);
            if ($response['hash']['response_code'] == 17) {
                $message = $this->getApiResponse()->describeResponse($response['hash']);
            } else {
                $message = $this->__('Automatic cancel');
                if (array_key_exists('bank_response_code', $describedResponse)) {
                    $this->getAtosSession()->setRedirectMessage($this->__('The payment platform has rejected your transaction with the message: <strong>%s</strong>, because the bank send the error: <strong>%s</strong>.', $describedResponse['response_code'], $describedResponse['bank_response_code']));
                } else {
                    $this->getAtosSession()->setRedirectMessage($this->__('The payment platform has rejected your transaction with the message: <strong>%s</strong>.', $describedResponse['response_code']));
                }
            }
            if ($order->getId()) {
                try {
                    Mage::helper('atos')->reorder($response['hash']['order_id']);
                    $order->cancel()->save();
                } catch (Mage_Core_Exception $e) {
                    Mage::logException($e);
                } catch (Exception $e) {
                    $message = $this->__('The order has not been cancelled.');
                    Mage::logException($e);
                }
            }

            // Add message to order
            $order->addStatusHistoryComment($message)->save();
        }

        // Save Atos/Sips response in session
        $this->getAtosSession()->setResponse($response);
        $this->_redirect($response['redirect_url'], array('_secure' => true));
    }

    /**
     * When customer returns from Atos/Sips payment platform
     */
    public function normalAction()
    {
        if (!array_key_exists('DATA', $_REQUEST)) {
            // Set redirect message
            $this->getAtosSession()->setRedirectMessage($this->__('An error occured: no data received.'));
            // Log error
            $errorMessage = $this->__('Customer #%s returned successfully from Atos/Sips payment platform but no data received for order #%s.', $this->getCustomerSession()->getCustomerId(), $this->getCheckoutSession()->getLastRealOrderId());
            Mage::helper('atos')->logError(get_class($this), __FUNCTION__, $errorMessage);
            // Redirect
            $this->_redirect('*/*/failure');
            return;
        }

        // Get Sips Server Response
        $response = $this->_getAtosResponse($_REQUEST['DATA']);

        // Debug
        $this->getMethodInstance()->debugResponse($response['hash'], 'Normal');

        // Check if merchant ID matches
        if ($response['hash']['merchant_id'] != $this->getConfig()->getMerchantId()) {
            // Set redirect message
            $this->getAtosSession()->setRedirectMessage($this->__('An error occured: merchant ID mismatch.'));
            // Log error
            $errorMessage = $this->__('Response Merchant ID (%s) is mismatch with configuration value (%s)', $response['hash']['merchant_id'], $this->getConfig()->getMerchantId());
            Mage::helper('atos')->logError(get_class($this), __FUNCTION__, $errorMessage);
            // Redirect
            $this->_redirect('*/*/failure');
            return;
        }

        // Treat response
        $order = Mage::getModel('sales/order');
        if ($response['hash']['order_id']) {
            $order->loadByIncrementId($response['hash']['order_id']);
        }

        switch ($response['hash']['response_code']) {
            case '00':
                if ($order->getId()) {
                    $order->addStatusHistoryComment($this->__('Customer returned successfully from Atos/Sips payment platform.'))
                            ->save();
                }
                $this->getCheckoutSession()->getQuote()->setIsActive(false)->save();
                // Set redirect URL
                $response['redirect_url'] = 'checkout/onepage/success';
                break;
            default:
                // Log error
                $errorMessage = $this->__('Error: code %s.<br /> %s', $response['hash']['response_code'], $response['hash']['error']);
                Mage::helper('atos')->logError(get_class($this), __FUNCTION__, $errorMessage);
                // Add error on order message, cancel order and reorder
                if ($order->getId()) {
                    try {
                        Mage::helper('atos')->reorder($response['hash']['order_id']);
                        $order->cancel()->save();
                        $order->addStatusHistoryComment($errorMessage)
                                ->save();
                    } catch (Mage_Core_Exception $e) {
                        Mage::logException($e);
                    } catch (Exception $e) {
                        $message = $this->__('The order has not been cancelled.');
                        $order->addStatusHistoryComment($message)
                                ->save();
                        Mage::logException($e);
                    }
                }
                // Set redirect message
                $this->getAtosSession()->setRedirectTitle($this->__('Your payment has been rejected'));
                $describedResponse = $this->getApiResponse()->describeResponse($response['hash'], 'array');
                $this->getAtosSession()->setRedirectMessage($this->__('The payment platform has rejected your transaction with the message: <strong>%s</strong>, because the bank send the error: <strong>%s</strong>.', $describedResponse['response_code'], $describedResponse['bank_response_code']));
                // Set redirect URL
                $response['redirect_url'] = '*/*/failure';
                break;
        }

        // Save Atos/Sips response in session
        $this->getAtosSession()->setResponse($response);

        $this->_redirect($response['redirect_url'], array('_secure' => true));
    }

    /**
     * When Atos/Sips returns
     */
    public function automaticAction()
    {
        if (!array_key_exists('DATA', $_REQUEST)) {
            // Log error
            $errorMessage = $this->__('Automatic response received but no data received for order #%s.', $this->getCheckoutSession()->getLastRealOrderId());
            Mage::helper('atos')->logError(get_class($this), __FUNCTION__, $errorMessage);
            $this->getResponse()->setHeader('HTTP/1.1', '503 Service Unavailable');
            return;
        }

        Mage::getModel('atos/ipn')->processIpnResponse($_REQUEST['DATA'], $this->getMethodInstance());
    }

    /**
     * When has error in treatment
     */
    public function failureAction()
    {
        $this->loadLayout();
        $this->getLayout()->getBlock('atos_failure')->setTitle($this->getAtosSession()->getRedirectTitle());
        $this->getLayout()->getBlock('atos_failure')->setMessage($this->getAtosSession()->getRedirectMessage());
        $this->getAtosSession()->unsetAll();
        $this->renderLayout();
    }

    public function saveAuroreDobAction()
    {
        $dob = Mage::app()->getLocale()->date($this->getRequest()->getParam('dob'), null, null, false)->toString('yyyy-MM-dd');
        try {
            $this->getAtosSession()->setCustomerDob($dob);
            $this->getResponse()->setBody('OK');
        } catch (Exception $e) {
            $this->getResponse()->setBody('KO - ' . $e->getMessage());
        }
    }

    /**
     * Treat Atos/Sips response
     */
    protected function _getAtosResponse($data)
    {
        $response = $this->getApiResponse()->doResponse($data, array(
            'bin_response' => $this->getConfig()->getBinResponse(),
            'pathfile' => $this->getMethodInstance()->getConfig()->getPathfile()
        ));

        if (!isset($response['hash']['code'])) {
            $this->_redirect('*/*/failure');
            return;
        }

        if ($response['hash']['code'] == '-1') {
            $this->_redirect('*/*/failure');
            return;
        }

        return $response;
    }

}
