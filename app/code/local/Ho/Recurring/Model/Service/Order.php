<?php
/**
 * Ho_Recurring
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the H&O Commercial License
 * that is bundled with this package in the file LICENSE_HO.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.h-o.nl/license
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@h-o.com so we can send you a copy immediately.
 *
 * @category    Ho
 * @package     Ho_Recurring
 * @copyright   Copyright © 2015 H&O (http://www.h-o.nl/)
 * @license     H&O Commercial License (http://www.h-o.nl/license)
 * @author      Maikel Koek – H&O <info@h-o.nl>
 */

class Ho_Recurring_Model_Service_Order
{
    /**
     * @param Mage_Sales_Model_Order $order
     */
    public function createProfile(Mage_Sales_Model_Order $order)
    {
        $billingAgreement = $this->_getBillingAgreement($order);

        // Create a profile for each order item
        // @todo Check if order items can be merged in one profile (same term, billing cycles, etc)

        $profiles = [];
        /** @var Mage_Sales_Model_Order_Item $orderItem */
        foreach ($order->getAllVisibleItems() as $orderItem) {
            /** @var Ho_Recurring_Model_Product_Profile $productProfile */
            $productProfile = $this->_getProductProfile($orderItem);

            if (!$productProfile) {
                // No recurring product profile found, no recurring profile needs to be created
                continue;
            }

            /** @var Ho_Recurring_Model_Profile $profile */
            $profile = Mage::getModel('ho_recurring/profile')
                ->setStatus(Ho_Recurring_Model_Profile::STATUS_ACTIVE)
                ->setStockId($order->getStockId())
                ->setCustomerId($order->getCustomerId())
                ->setCustomerName($order->getCustomerName())
                ->setOrderId($order->getId())
                ->setBillingAgreementId($billingAgreement->getId())
                ->setStoreId($order->getStoreId())
                ->setEndsAt('2015-10-01 12:00:00') // @todo Set correct ending date
                ->setTerm($productProfile->getTerm())
                ->setTermType($productProfile->getTermType())
                ->setNextOrderAt('2015-06-01 12:00:00') // @todo Set correct date
                ->setShippingMethod($order->getShippingMethod())
                ->save();

            /** @var Ho_Recurring_Model_Profile_Item $profileItem */
            $profileItem = Mage::getModel('ho_recurring/profile_item')
                ->setProfileId($profile->getId())
                ->setStatus(Ho_Recurring_Model_Profile_Item::STATUS_ACTIVE)
                ->setProductId($orderItem->getProductId())
                ->setSku($orderItem->getSku())
                ->setName($orderItem->getName())
                ->setLabel($productProfile->getLabel())
                ->setPrice($orderItem->getPrice())
                ->setPriceInclTax($orderItem->getPriceInclTax())
                ->setQty($productProfile->getQty())
                ->setOnce(0)
                ->setMinBillingCycles($productProfile->getMinBillingCycles())
                ->setMaxBillingCycles($productProfile->getMaxBillingCycles())
                ->setCreatedAt(now());

            $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
            $profile->setActiveQuote($quote);
            $orderAdditional = $profile->getOrderAdditional($order, true)->save();
            $quoteAdditional = $profile->getActiveQuoteAdditional(true)
                ->setQuote($quote)
                ->setOrder($order);
//            var_dump($quoteAdditional, $orderAdditional);exit;
//
            $profile->setErrorMessage(null);
            if ($profile->getStatus() == $profile::STATUS_ORDER_ERROR) {
                $profile->setStatus($profile::STATUS_ACTIVE);
            }

            Mage::getModel('core/resource_transaction')
                ->addObject($profileItem)
                ->addObject($profile)
                ->addObject($orderAdditional)
                ->addObject($quoteAdditional)
                ->save();

            $profiles[] = $profile;
        }
        return $profiles;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @return Mage_Sales_Model_Billing_Agreement
     */
    protected function _getBillingAgreement(Mage_Sales_Model_Order $order)
    {
        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');
        $select = $connection->select();

        $select->from($resource->getTableName('sales/billing_agreement_order'));
        $select->reset(Zend_Db_Select::COLUMNS);
        $select->columns('agreement_id');
        $select->where('order_id = ?', $order->getId());

        $billingAgreementId = $connection->fetchOne($select);
        if (! $billingAgreementId) {
            Ho_Recurring_Exception::throwException('Could not find billing agreement for order '.$order->getIncrementId());
        }

        return Mage::getModel('sales/billing_agreement')->load($billingAgreementId);
    }

    /**
     * @param Mage_Sales_Model_Order_Item $orderItem
     * @return Ho_Recurring_Model_Product_Profile
     */
    protected function _getProductProfile(Mage_Sales_Model_Order_Item $orderItem)
    {
        $profileId = $orderItem->getBuyRequest()->getData('ho_recurring_profile');
        if (! $profileId) {
            return $this;
        }

        $recurringProductProfile = Mage::getModel('ho_recurring/product_profile')
            ->load($profileId);

        if (!$recurringProductProfile->getId()) {
            return false;
        }

        return $recurringProductProfile;
    }
}
