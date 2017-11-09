<?php
class GoMedia_Rest_Model_Api2_Acl_Filter_Attribute_ResourcePermission extends Mage_Api2_Model_Acl_Filter_Attribute_ResourcePermission
{

    /**
     * Get resources permissions for selected role
     *
     * @return array
     */
    public function getResourcesPermissions()
    {
        if (null === $this->_resourcesPermissions) {
            $rulesPairs = array();

            if ($this->_userType) {
                $allowedAttributes = array();

                /** @var $rules Mage_Api2_Model_Resource_Acl_Filter_Attribute_Collection */
                $rules = Mage::getResourceModel('api2/acl_filter_attribute_collection');
                $rules->addFilterByUserType($this->_userType);

                foreach ($rules as $rule) {
                    if (Mage_Api2_Model_Acl_Global_Rule::RESOURCE_ALL === $rule->getResourceId()) {
                        $rulesPairs[$rule->getResourceId()] = Mage_Api2_Model_Acl_Global_Rule_Permission::TYPE_ALLOW;
                    }

                    /** @var $rule Mage_Api2_Model_Acl_Filter_Attribute */
                    if (null !== $rule->getAllowedAttributes()) {
                        $allowedAttributes[$rule->getResourceId()][$rule->getOperation()] = explode(
                            ',', $rule->getAllowedAttributes()
                        );
                    }
                }

                /** @var $config Mage_Api2_Model_Config */
                $config = Mage::getModel('api2/config');

                /** @var $operationSource Mage_Api2_Model_Acl_Filter_Attribute_Operation */
                $operationSource = Mage::getModel('api2/acl_filter_attribute_operation');

                foreach ($config->getResourcesTypes() as $resource) {
                    $resourceUserPrivileges = $config->getResourceUserPrivileges($resource, $this->_userType);

                    if (!$resourceUserPrivileges) { // skip user without any privileges for resource
                        continue;
                    }
                    $operations = $operationSource->toArray();

                    if (empty($resourceUserPrivileges[Mage_Api2_Model_Resource::OPERATION_CREATE])
                        && empty($resourceUserPrivileges[Mage_Api2_Model_Resource::OPERATION_UPDATE])
                    ) {
                        unset($operations[Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_WRITE]);
                    }
                    if (empty($resourceUserPrivileges[Mage_Api2_Model_Resource::OPERATION_RETRIEVE])) {
                        unset($operations[Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_READ]);
                    }
                    if (!$operations) { // skip resource without any operations allowed
                        continue;
                    }
                    try {
                        /** @var $resourceModel Mage_Api2_Model_Resource */
                        $resourceModel = Mage::getModel($config->getResourceModel($resource));
						if ($resource == 'gomediarest') {
							// use product source for gomediarest
							$resourceModel = Mage::getModel($config->getResourceModel('product'));
						}
                        if ($resourceModel) {
                            $resourceModel->setResourceType($resource)
                                ->setUserType($this->_userType);

                            foreach ($operations as $operation => $operationLabel) {
                                if (!$this->_hasEntityOnlyAttributes
                                    && $config->getResourceEntityOnlyAttributes($resource, $this->_userType, $operation)
                                ) {
                                    $this->_hasEntityOnlyAttributes = true;
                                }
                                $availableAttributes = $resourceModel->getAvailableAttributes(
                                    $this->_userType,
                                    $operation
                                );
                                asort($availableAttributes);
                                foreach ($availableAttributes as $attribute => $attributeLabel) {
                                    $status = isset($allowedAttributes[$resource][$operation])
                                        && in_array($attribute, $allowedAttributes[$resource][$operation])
                                            ? Mage_Api2_Model_Acl_Global_Rule_Permission::TYPE_ALLOW
                                            : Mage_Api2_Model_Acl_Global_Rule_Permission::TYPE_DENY;

                                    $rulesPairs[$resource]['operations'][$operation]['attributes'][$attribute] = array(
                                        'status'    => $status,
                                        'title'     => $attributeLabel
                                    );
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // getModel() throws exception when application is in development mode
                        Mage::logException($e);
                    }
                }
            }
            $this->_resourcesPermissions = $rulesPairs;
        }
        return $this->_resourcesPermissions;
    }

}
