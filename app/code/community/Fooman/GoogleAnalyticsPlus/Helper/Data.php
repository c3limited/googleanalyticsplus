<?php
/**
 * Fooman GoogleAnalyticsPlus
 *
 * @package   Fooman_GoogleAnalyticsPlus
 * @author    Kristof Ringleff <kristof@fooman.co.nz>
 * @copyright Copyright (c) 2010 Fooman Limited (http://www.fooman.co.nz)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class Fooman_GoogleAnalyticsPlus_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * retrieve requested value from order or item
     * convert from base currency if configured
     * else return order currency
     *
     * @param $object
     * @param $field
     *
     * @param $currentCurrency
     *
     * @return string
     */
    public function convert($object, $field, $currentCurrency = null)
    {
        $baseCur = Mage::app()->getStore($object->getStoreId())->getBaseCurrency();

        //getPrice and getFinalPrice do not have base equivalents
        if ($field != 'price' && $field != 'final_price') {
            $field = 'base_' . $field;
            $baseValue = $object->getDataUsingMethod($field);
        } else {
            if (null === $currentCurrency) {
                Mage::throwException('Currency needs to be defined');
            }
            $value = $object->getDataUsingMethod($field);
            if ($currentCurrency == $baseCur->getCode()) {
                $baseValue = $value;
            } else {
                $rate = Mage::getModel('directory/currency')
                    ->load($baseCur->getCode())
                    ->getRate($currentCurrency);
                $baseValue = Mage::app()->getStore()->roundPrice($value / $rate);
            }
        }

        if (!Mage::getStoreConfig('google/analyticsplus/convertcurrencyenabled')) {
            return $baseValue;
        }

        return sprintf(
            "%01.4f", Mage::app()->getStore()->roundPrice(
                $baseCur->convert(
                    $baseValue,
                    Mage::getStoreConfig('google/analyticsplus/convertcurrency')
                )
            )
        );

    }

    /**
     * currency for tracking
     *
     * @param $object
     *
     * @return string
     */
    public function getTrackingCurrency($object)
    {
        if (!Mage::getStoreConfig('google/analyticsplus/convertcurrencyenabled')) {
            return $object->getBaseCurrencyCode();
        } else {
            return Mage::getStoreConfig('google/analyticsplus/convertcurrency');
        }
    }

    /**
     * @param Mage_Sales_Model_Order|Mage_Sales_Model_Quote $order
     *
     * @return string
     */
    public function getCartProductEC($order)
    {
        $return = '';
        $altUniversal = Mage::getStoreConfig('google/analyticsplus_universal/altaccountnumber');

        foreach ($order->getAllVisibleItems() as $item) {
            $itemDetails = "{
                'id': '" . $this->jsQuoteEscape($item->getSku()) . "',
                'name': '" . $this->jsQuoteEscape($item->getName()) . "',
                'sku': '" . $this->jsQuoteEscape($item->getSku()) . "',
                'price': '"
                . Mage::helper('googleanalyticsplus')->convert($item, 'price', ($order instanceof Mage_Sales_Model_Order) ? $order->getOrderCurrencyCode() : $order->getQuoteCurrencyCode())
                . "',
                'quantity': '" . (int)$item->getQtyOrdered() . "',
                'category': '" . $this->jsQuoteEscape($this->getCategory($item))
                . "'
            }";

            $return .= "ga('ec:addProduct',{$itemDetails});\n";
            if ($altUniversal) {
                $return .= "ga('" . Fooman_GoogleAnalyticsPlus_Block_Universal::TRACKER_TWO_NAME . ".ec:addProduct',{$itemDetails});\n";
            }
        }

        return $return;
    }

    /**
     * retrieve an item's category
     * if no product attribute chosen use the product's first category
     *
     * @param $item
     *
     * @return mixed|null
     */
    protected function getCategory($item)
    {

        $product = Mage::getModel('catalog/product')->load($item->getProductId());
        if ($product) {
            return $this->getProductCategory($product);
        }
        return null;
    }

    /**
     * get product category for tracking purposes
     * either based on chosen category tracking attribute
     * or first category encountered
     *
     * @param $product
     *
     * @return mixed
     */
    public function getProductCategory($product)
    {
        $attributeCode = Mage::getStoreConfig('google/analyticsplus/categorytrackingattribute');
        if ($attributeCode) {
            if ($product->getResource()->getAttribute($attributeCode)) {
                $attributeValue = $product->getAttributeText($attributeCode);
                if (!$attributeValue) {
                    return $product->getDataUsingMethod($attributeCode);
                } else {
                    return $attributeValue;
                }
            }
        } else {
            $catIds = $product->getCategoryIds();
            foreach ($catIds as $catId) {
                $category = Mage::getModel('catalog/category')->load($catId);
                if ($category) {
                    //we use the first category
                    return $category->getName();
                }
            }
        }
        return false;
    }
}
