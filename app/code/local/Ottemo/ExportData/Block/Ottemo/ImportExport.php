<?php
/**
 * @category   Ottemo
 * @package    Ottemo_ExportData
 * @author     Oleksii Golub <oleksii.v.golub@gmail.com>
 */

/**
 * Class Ottemo_ExportData_Block_Ottemo_ImportExport
 */
class Ottemo_ExportData_Block_Ottemo_ImportExport extends Mage_Adminhtml_Block_Widget
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('ottemo/export.phtml');
    }

    public function getExportData()
    {
        $session = Mage::getSingleton('customer/session');
        $data = array();

        if ($session->hasData("ottemo_export_data")) {
            $data = $session->getData("ottemo_export_data");
            $session->unsetData("ottemo_export_data");
        }

        return $data;
    }

    public function getProductAttributes()
    {
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->getItems();
        $attributesArray = array();

        foreach ($attributes as $attribute) {
            if (!$attribute->getFrontendLabel()) {
                continue;
            }
            $attributesArray[] = array(
                "id" => $attribute->getId(),
                "code" => $attribute->getAttributeCode(),
                "label" => $attribute->getFrontendLabel(),
            );
        }

        return $attributesArray;
    }

}
