<?php
/* GoMedia REST API
*
* @category GoMedia
* @package GoMedia_Rest
*/
class GoMedia_Rest_Model_Api2_Category_Rest_Admin_V1 extends Mage_Catalog_Model_Api2_Product_Category_Rest_Admin_V1
{

    /**
     * @throws Mage_Api2_Exception
     * Retrieve a category
     */
    protected function _retrieve()
    {
        /** @var $category Mage_Cat */
        $category = $this->_getCategoryById($this->getRequest()->getParam('id'));
        return $category->getData();
    }


    /**
     * Retrieve list of products
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        /** @var $collection Mage_Catalog_Model_Resource_Product_Collection */
        $collection = Mage::getResourceModel('catalog/category_collection');
        $store = $this->_getStore();
        $collection->setStoreId($store->getId());
        $collection->addAttributeToSelect(array_keys(
            $this->getAvailableAttributes($this->getUserType(), Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_READ)
        ));
        $this->_applyCategoryFilter($collection);
        $this->_applyCollectionModifiers($collection);
        $products = $collection->clear()->setPageSize(false)->load()->toArray();
        return $products;
    }

    /**
     * @throws Mage_Api2_Exception
     * Create a new category
     */
    public function _create()
    {
        $requestData = $this->getRequest()->getBodyParams();
        $category = Mage::getModel('catalog/category');
        $category->setName($requestData['name']);
        $category->setStoreId(Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID);
        $category->setIsActive($requestData['is_active']);
        $category->setDisplayMode('PRODUCTS');
        $category->setIsAnchor($requestData['is_anchor']);

        if (isset($requestData['parent_id'])) {
            $parentId = $requestData['parent_id'];
        } else {
            $parentId = Mage::app()->getStore(Mage_Catalog_Model_Abstract::DEFAULT_STORE_ID)->getRootCategoryId();
        }
        $parentCategory = Mage::getModel('catalog/category')->load($parentId);
        $category->setPath($parentCategory->getPath());
        try {
            $category->save();
        } catch (Mage_Core_Exception $e) {
            $this->_critical($e->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $e) {
            $this->_critical(self::RESOURCE_INTERNAL_ERROR);
        }
        return $this->_getLocation($category);
    }

    /**
     * @throws Mage_Api2_Exception
     * Delete a category
     */
    public function _delete()
    {
        $id = $this->getRequest()->getParam('id');
        $id = str_replace(':', '', $id);
        $category = $this->_initCategory($id);
        try {
            $category->delete();
        } catch (Exception $e) {
            $this->_critical($e->getMessage());
        }
    }

    /**
     * Get resource location
     *
     * @param Mage_Core_Model_Abstract $resource
     * @return string URL
     */
    protected function _getLocation($resource)
    {
        /* @var $apiTypeRoute Mage_Api2_Model_Route_ApiType */
        $apiTypeRoute = Mage::getModel('api2/route_apiType');

        $chain = $apiTypeRoute->chain(
            new Zend_Controller_Router_Route($this->getConfig()->getRouteWithEntityTypeAction($this->getResourceType()))
        );
        $params = array(
            'api_type' => $this->getRequest()->getApiType(),
            'id'       => $resource->getId()
        );
        $uri = $chain->assemble($params);

        return '/' . $uri;
    }

    /**
     * @param $categoryID
     * @return Mage_Catalog_Model_Category
     * @throws Mage_Api2_Exception
     * Check if a category exists
     */

    protected function _initCategory($categoryID)
    {
        /** @var $category Mage_Catalog_Model_Category */
        $category = Mage::getModel("catalog/category")->load($categoryID);
        $test = $category->getId();
        if (isset($test)) {
            return $category;
        } else {
            $this->_critical('Category not found');
        }
    }

}