<?php
/* GoMedia REST API
*
* @category GoMedia
* @package GoMedia_Rest
*/
class GoMedia_Rest_Model_Api2_Simple_Rest_Admin_V1 extends Mage_Catalog_Model_Api2_Product_Rest_Admin_V1
{

    /**
     * Set additional data before product save
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $productData
     */
    protected function _prepareDataForSave($product, $productData)
    {
		parent::_prepareDataForSave($product, $productData);

        if (isset($productData['attributes']) && is_array($productData['attributes'])) {
            foreach ($productData['attributes'] as $key => $value) {
				if (!$this->_getIsDropdown($key)) {
					$product->setData($key, $value);
				} else {
					$this->_addAttributeOption($key, $value);
					$product->setData($key, $this->_getAttributeIdByValue($key, $value));
				}
            }

            unset($productData['attributes']);
        }
    }
	
	protected function _getIsDropdown($code)
	{
		$attribute = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $code);
		return $attribute->getData('frontend_input') == 'select';
	}
	
	protected function _getAttributeIdByCode($code)
	{
		$attribute = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $code);
		$attributeId = $attribute->getData('attribute_id');
	
		return $attributeId;
	}

	protected function _getAttributeIdByValue($code, $value)
	{
		$attrId = $this->_getAttributeIdByCode($code);
		$collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
			->setPositionOrder('asc')
			->setAttributeFilter($attrId)
			->setStoreFilter(0)
			->load();
			
		foreach ($collection as $item) {
			if (strtolower($item->getValue()) == strtolower($value)) {
				return $item->getOptionId();
			}
		}
		
		return false;
	}
	
	protected function _addAttributeOption($code, $option)
	{
		$option = trim($option);
		if (empty($option)) {
			return;
		}

		if ($this->_getAttributeIdByValue($code, $option)) {
			return;
		}
		$attribute = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $code);
		$value = array();
		$value['option'] = array($option);
        $result = array('value' => $value);
        $attribute->setData('option', $result);
        $attribute->save();
	}

}