<?php
/* GoMedia REST API
*
* @category GoMedia
* @package GoMedia_Rest
*/
class GoMedia_Rest_Model_Api2_Configurable_Rest_Admin_V1 extends Mage_Catalog_Model_Api2_Product_Rest_Admin_V1
{

	/**
	 * @var attributes data cache
	 */
	protected $_attributeCache = array();
	
	protected $_attributeSetId = null;

    /**
     * Create product
     *
     * @param array $data
     * @return string
     */
    protected function _create(array $data)
    {
		Varien_Profiler::enable();
		Varien_Profiler::start('_create');
		Mage::log('_create');
		Mage::log($data);
		
        $validator = Mage::getModel('catalog/api2_product_validator_product', array(
            'operation' => self::OPERATION_CREATE
        ));

        if (!$validator->isValidData($data)) {
            foreach ($validator->getErrors() as $error) {
                $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }
            $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
        }

		$this->_updateAttributes($data);

        $type = $data['type_id'];
        if ($type !== 'configurable') {
            $this->_critical("Only creation of products with type '$type' is implemented",
                Mage_Api2_Model_Server::HTTP_METHOD_NOT_ALLOWED);
        }
        $set = $data['attribute_set_id'];
        $sku = $data['sku'];

        /** @var $product Mage_Catalog_Model_Product */
        $product = Mage::getModel('catalog/product')
            ->setStoreId(Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID)
            ->setAttributeSetId($set)
            ->setTypeId($type)
            ->setSku($sku);

        foreach ($product->getMediaAttributes() as $mediaAttribute) {
            $mediaAttrCode = $mediaAttribute->getAttributeCode();
            $product->setData($mediaAttrCode, 'no_selection');
        }

        try {
			$this->_prepareDataForSave($product, $data);
            $product->validate();
            $product->save();
            $this->_multicall($product->getId());
        } catch (Mage_Eav_Model_Entity_Attribute_Exception $e) {
			mage::log($e->getMessage());
            $this->_critical(sprintf('Invalid attribute "%s": %s', $e->getAttributeCode(), $e->getMessage()),
                Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
        } catch (Mage_Core_Exception $e) {
			mage::log($e->getMessage());
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
			mage::log($e->getMessage());
            $this->_critical(self::RESOURCE_UNKNOWN_ERROR);
        }

		Varien_Profiler::stop('_create');
		mage::log('_create:: ' . Varien_Profiler::fetch('_create'));
		mage::log('simple_save:: ' . Varien_Profiler::fetch('simple_save'));
        return $this->_getLocation($product);
    }

    /**
     * Update product by its ID
     *
     * @param array $data
     */
    protected function _update(array $data)
    {
		Mage::log('_update');
		Mage::log($data);

		$this->_updateAttributes($data);

        $product = $this->_getProduct();
        $validator = Mage::getModel('catalog/api2_product_validator_product', array(
            'operation' => self::OPERATION_UPDATE,
            'product'   => $product
        ));

        if (!$validator->isValidData($data)) {
            foreach ($validator->getErrors() as $error) {
                $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }
            $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
        }
        if (isset($data['sku'])) {
            $product->setSku($data['sku']);
        }
        // attribute set and product type cannot be updated
		if (isset($data['attribute_set_id'])) {
			unset($data['attribute_set_id']);
		}
		if (isset($data['type_id'])) {
			unset($data['type_id']);
		}
        try {
			$this->_prepareDataForSave($product, $data);
            $product->validate();
            $product->save();
        } catch (Mage_Eav_Model_Entity_Attribute_Exception $e) {
			mage::log($e->getMessage());
            $this->_critical(sprintf('Invalid attribute "%s": %s', $e->getAttributeCode(), $e->getMessage()),
                Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
        } catch (Mage_Core_Exception $e) {
			mage::log($e->getMessage());
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
			mage::log($e->getMessage());
            $this->_critical(self::RESOURCE_UNKNOWN_ERROR);
        }
    }

    /**
     * Delete product by its ID
     *
     * @throws Mage_Api2_Exception
     */
    protected function _delete()
    {
        $product = $this->_getProduct();
        try {
            $this->_deleteAssociated($product);
            $product->delete();
        } catch (Mage_Core_Exception $e) {
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
            $this->_critical(self::RESOURCE_INTERNAL_ERROR);
        }
    }

    /**
     * Set additional data before product save
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array $productData
     */
    protected function _prepareDataForSave($product, $productData)
    {
		mage::log('_prepareDataForSave');
		
		parent::_prepareDataForSave($product, $productData);
		
		$removeSkus = isset($productData['remove_associated_products']) ? $productData['remove_associated_products'] : array();
		
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

		if (isset($productData['associated_products'])) {
            $simpleProducts = (array) $productData['associated_products'];
			
			$attributesData = array();
			foreach ($simpleProducts as $simpleProduct) {
				foreach ($simpleProduct['attributes'] as $attribute) {
					if (!isset($attributesData[$attribute['code']])) {
						$attributesData[$attribute['code']] = array();
					}
					$attributesData[$attribute['code']][] = $attribute['value'];
				}
			}
			//$this->_updateAttributes($attributesData, $this->_attributeSetId);

			$existingConfigAttrs = $this->_getExistingConfigurableAttributes($product);

			$skus = array();
            $configurableAttributes = $existingConfigAttrs;
			$configurableAttributesData = array();
			$attributeValues = array();
			foreach ($simpleProducts as $simpleProduct) {
				$skus[] = $simpleProduct['sku'];
				
				foreach ($simpleProduct['attributes'] as $attribute) {
					
					if (!in_array($attribute['code'], $configurableAttributes)) {
						$configurableAttributesData[] = array(
							'attribute_id' => $this->_getAttributeIdByCode($attribute['code']),
							'attribute_code' => $attribute['code'],
						);
					}

					$configurableAttributes[] = $attribute['code'];
					
					if (isset($attribute['value'])) {
						if (!isset($attributeValues[$simpleProduct['sku']])) {
							$attributeValues[$simpleProduct['sku']] = array();
						}
						$attributeValues[$simpleProduct['sku']][$attribute['code']] = $this->_getAttributeIdByValue($attribute['code'], $attribute['value']);
					}
				}
			}

            $this->_associateProducts($product, $skus, $configurableAttributesData, $attributeValues, $removeSkus);
        } elseif (!empty($removeSkus)) {
            $this->_associateProducts($product, array(), array(), array(), $removeSkus);
		}
    }
	
	protected function _getIsDropdown($code)
	{
		$attribute = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $code);
		return $attribute->getData('frontend_input') == 'select';
	}
	
	protected function _updateAttributes($data)
	{
		if (!isset($data['associated_products'])) {
			return;
		}
        $simpleProducts = (array) $data['associated_products'];
		$attributesData = array();
		foreach ($simpleProducts as $simpleProduct) {
			foreach ($simpleProduct['attributes'] as $attribute) {
				if (!isset($attributesData[$attribute['code']])) {
					$attributesData[$attribute['code']] = array();
				}
				$attributesData[$attribute['code']][] = $attribute['value'];
			}
		}
		foreach ($attributesData as $code => $values) {
			if (isset($data['attribute_set_id'])) {
				$this->_createAttribute($code, $data['attribute_set_id']);
			}
			foreach ($values as $value) {
				$this->_addAttributeOption($code, $value);
			}
		}
	}
	
	protected function _getExistingConfigurableAttributes($product)
	{
		$attributes = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);
		$ids = array();
		foreach ($attributes as $attribute) {
			$ids[] = $attribute['attribute_code'];
		}
		
		return $ids;
	}
	
	protected function _associateProducts($product, $skus, $configurableAttributesData, $attributeValues, $removeSkus)
	{
		//mage::log($skus);
		//mage::log($configurableAttributesData);
		//mage::log($attributeValues);
		//mage::log($removeSkus);

		// get assigned simple products
		$usedProductIds = Mage::getModel('catalog/product_type_configurable')->setProduct($product)->getUsedProductCollection()->getAllIds();

        if (!empty($skus)) {
            $productIds = Mage::getModel('catalog/product')->getCollection()
                ->addFieldToFilter('sku', array('in' => (array) $skus))
                ->addFieldToFilter('type_id', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
                ->getAllIds();
				
			foreach ($attributeValues as $sku => $data) {
				$p = Mage::getModel('catalog/product')->load(Mage::getModel('catalog/product')->getIdBySku($sku));
				if (!$p->getId()) {
					// no simple product
					throw new exception('Some of simple products have not been found');
				}
				
				foreach ($data as $key => $value) {
					$this->_updateSimpleProductAttribute($p->getId(), $key, $value);
					//$p->setData($key, $value);
				}

				Varien_Profiler::start('simple_save');
				//$p->save();
				Varien_Profiler::stop('simple_save');
			}

			// merge with new
			$usedProductIds = array_merge($productIds, $usedProductIds);
			$usedProductIds = array_unique($usedProductIds);
        }
		
		if (!empty($removeSkus)) {
            $productIds = Mage::getModel('catalog/product')->getCollection()
                ->addFieldToFilter('sku', array('in' => (array) $removeSkus))
                ->addFieldToFilter('type_id', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
                ->getAllIds();

			$usedProductIds = array_diff($usedProductIds, $productIds);
		}

		$product->setConfigurableAttributesData($configurableAttributesData);
		$product->setConfigurableProductsData(array_flip($usedProductIds));
	}
	
	protected function _getAttributeIdByCode($code)
	{
		$attribute = Mage::getModel('eav/entity_attribute')->loadByCode('catalog_product', $code);
		$attributeId = $attribute->getData('attribute_id');
	
		//if (!$attribute->getData('is_configurable') || $attribute->getData('frontend_input') != 'select' || !$attribute->getData('is_global')) {
			// attribute is not configurable
			//throw new exception('Attribute is not allowed for creating of configurable products: ' . $code);
		//}
		
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
	
	protected function _deleteAssociated($product)
	{
		// get assigned simple products
		$usedProducts = Mage::getModel('catalog/product_type_configurable')->setProduct($product)->getUsedProductCollection();
		foreach ($usedProducts as $usedProduct) {
			$usedProduct->delete();
		}
	}
	
	protected function _createAttribute($code, $attributeSetId)
    {
        if ($code == '') {
            return false;
        }

		if ($this->_getAttributeIdByCode($code)) {
			return;
		}

		$attributeGroupId = $this->_getAttributeGroupId($attributeSetId);
        if (!$attributeGroupId) {
            return false;
        }

        $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
 
        $data = array(
            'attribute_code'                => $code,
			'attribute_set_id'				=> $attributeSetId,
			'entity_type_id'				=> $entityTypeId,
			'attribute_group_id'			=> $attributeGroupId,
            'is_global'                     => '1',
            'frontend_input'                => 'select',
            'is_unique'                     => '0',
            'is_required'                   => '0',
            'frontend_class'                => '',
            'is_searchable'                 => '1',
            'is_visible_in_advanced_search' => '1',
            'is_comparable'                 => '1',
            'is_used_for_promo_rules'       => '0',
            'is_html_allowed_on_front'      => '0',
            'is_visible_on_front'           => '1',
			'is_user_defined'				=> 1,
            'used_in_product_listing'       => '0',
            'used_for_sort_by'              => '0',
            'is_configurable'               => '1',
            'is_filterable'                 => '0',
            'is_filterable_in_search'       => '0',
            'backend_type'                  => 'int',
			'apply_to'						=> array('simple'),
			'frontend_label'				=> array(0 => $code),
			'default_value'					=> '',
        );
 
        $model = Mage::getModel('catalog/resource_eav_attribute');
        $model->addData($data);
        $model->save();

        return $model->getId();
	}

	function _getAttributeGroupId($attributeSetId, $name = 'General')
	{
		$model = Mage::getModel('eav/entity_attribute_group');
		$collection = $model->getCollection();
		
		foreach ($collection as $item) {
			if ($item->getData('attribute_group_name') == $name && $item->getData('attribute_set_id') == $attributeSetId) {
				return $item->getId();
			}
		}
		
		return false;
	}
	
	protected function _updateSimpleProductAttribute($pid, $code, $value)
	{
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('write');
        $tPrefix = (string) Mage::getConfig()->getTablePrefix();
		$tableName = $tPrefix . 'catalog_product_entity_int';
		$attrId = $this->_getAttributeIdByCode($code);
        $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
		$query = "INSERT INTO `{$tableName}` (`entity_type_id` ,`attribute_id` ,`store_id` ,`entity_id` ,`value`) VALUES ('{$entityTypeId}', '{$attrId}', '0', '{$pid}', '{$value}');";
		try {
			$write->query($query);
		} catch (exception $e) {
			$query = "UPDATE `{$tableName}` SET `value` = '{$value}' WHERE `attribute_id` = {$attrId} AND `entity_id` = '{$pid}'";
			$write->query($query);
		}
	}

}