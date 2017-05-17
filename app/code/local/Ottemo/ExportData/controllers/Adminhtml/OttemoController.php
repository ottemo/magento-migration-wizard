<?php
/**
 * Adminhtml Ottemo controller
 *
 * @category   Ottemo
 * @package    Ottemo_ExportData
 * @author     Oleksii Golub <oleksii.v.golub@gmail.com>
 */

/**
 * Class Ottemo_ExportData_Adminhtml_OttemoController
 */
class Ottemo_ExportData_Adminhtml_OttemoController extends Mage_Adminhtml_Controller_Action
{

    protected $_apiUrl;

    protected $_apiUrlPrefix = "/impex/magento";

    protected $_apiKey;

    protected $_allowedAttributes;

    /**
     * Initialize action
     *
     * @return Mage_Adminhtml_Controller_Action
     */
    protected function _initAction()
    {
        $this->loadLayout()
            ->_setActiveMenu('catalog/ottemo_exportdata')
            ->_addBreadcrumb(Mage::helper('ottemo_exportdata')->__('Ottemo'),
                Mage::helper('ottemo_exportdata')->__('Ottemo'))
            ->_addBreadcrumb(Mage::helper('ottemo_exportdata')->__('Export'),
                Mage::helper('ottemo_exportdata')->__('Export'));

        return $this;
    }

    /**
     * Export Page
     *
     */
    public function exportAction()
    {
        $this->_title($this->__('Ottemo'))
            ->_title($this->__('Export'));

        $this->_title($this->__('Export'));

        $this->loadLayout()
            ->_setActiveMenu('catalog/ottemo_exportdata')
            ->_addContent($this->getLayout()->createBlock('ottemo_exportdata/ottemo_importExport'))
            ->_initLayoutMessages('customer/session')
            ->renderLayout();

    }

    /**
     * export action from magento to ottemo
     *
     */
    public function exportPostAction()
    {
        $this->_apiUrl = $this->getRequest()->getParam('url');
        $this->_apiKey = $this->getRequest()->getParam('apiKey');
        $this->_allowedAttributes = $this->getRequest()->getParam('attributes');

        Mage::getSingleton('customer/session')->addData(array(
                "ottemo_export_data" => array(
                    "url" => $this->_apiUrl,
                    "apiKey" => $this->_apiKey,
                    "attributes" => $this->_allowedAttributes
                )
            )
        );

        if (!$this->_apiUrl || !$this->_apiKey) {
            Mage::getSingleton('customer/session')
                ->addError(Mage::helper('ottemo_exportdata')->__('Unable to submit your request. Please, try again later'));

            return $this->_redirect('adminhtml/ottemo/export');
        }

        set_time_limit(0);

        try {

            $this->sendCustomersData();
            $this->sendCategoriesData();
            $this->sendProductAttributesData();
            $this->sendProductsData();

            if (Mage::getStoreConfigFlag('cataloginventory/item_options/manage_stock')) {
                $this->sendStockData();
            }

            $this->sendOrdersData();

        } catch (Exception $e) {
            Mage::getSingleton('customer/session')
                ->addError(Mage::helper('ottemo_exportdata')->__($e->getMessage()));
        }

        return $this->_redirect('adminhtml/ottemo/export');

    }

    protected function sendRequests($url, $postData, $limit = 10)
    {
        $offset = 0;
        while ($data = array_slice($postData, $offset, $limit)) {
            $this->sendRequest($url, $data);
            $offset += $limit;
        }
    }

    /**
     * Send data to
     *
     * @param $url
     * @param $postData
     * @param bool $debug
     * @return bool|mixed
     * @throws Exception
     */
    protected function sendRequest($url, $postData, $debug = false)
    {

        if (!$postData || !$url) {
            return false;
        }

        $tmpFileName = Mage::getBaseDir('media') . '/import.json';

        file_put_contents($tmpFileName, json_encode($postData));
        $ch = curl_init();
//        var_dump($url);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        $this->curlCustomPostFields($ch, array('api_key' => $this->_apiKey), array("import.json" => $tmpFileName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($debug) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        $dataJson = curl_exec($ch);

        $error = "";
        if (curl_error($ch)) {
            $error = curl_error($ch);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
//        var_dump($httpCode);
        curl_close($ch);

        unlink($tmpFileName);
        if ($httpCode != 200) {
            throw new Exception("Url could not open (response code " . $httpCode . ")");
        }

        if ($error) {
            throw new Exception($error);
        }

        $dataArray = json_decode($dataJson, true);
//        var_dump($dataArray);
        if (!empty($dataArray["error"]["code"]) &&
            $dataArray["error"]["code"] == "8afbaca6-e1ec-435a-8208-d427ceb05d71"
        ) {
            throw new Exception('Access is denied. Check the correctness of the Api Key.');
        }


        return $dataArray;
    }

    /**
     * Attache files and params to cUlr request
     *
     * @param $ch
     * @param array $assoc
     * @param array $files
     * @return bool
     */
    protected function curlCustomPostFields($ch, array $assoc = array(), array $files = array())
    {

        // invalid characters for "name" and "filename"
        static $disallow = array("\0", "\"", "\r", "\n");
        $body = array();

        // build normal parameters
        foreach ($assoc as $key => $value) {
            $key = str_replace($disallow, "_", $key);
            $body[] = implode("\r\n", array(
                "Content-Disposition: form-data; name=\"{$key}\"",
                "",
                filter_var($value),
            ));
        }

        // build file parameters
        foreach ($files as $key => $value) {
            switch (true) {
                case false === $value = realpath(filter_var($value)):
                case !is_file($value):
                case !is_readable($value):
                    continue; // or return false, throw new InvalidArgumentException
            }
            $data = file_get_contents($value);
            $value = call_user_func("end", explode(DIRECTORY_SEPARATOR, $value));
            $key = str_replace($disallow, "_", $key);
            $value = str_replace($disallow, "_", $value);
            $body[] = implode("\r\n", array(
                "Content-Disposition: form-data; name=\"{$key}\"; filename=\"{$value}\"",
                "Content-Type: application/octet-stream",
                "",
                $data,
            ));
        }

        // generate safe boundary
        do {
            $boundary = "---------------------" . md5(mt_rand() . microtime());
        } while (preg_grep("/{$boundary}/", $body));

        // add boundary for each parameters
        array_walk($body, function (&$part) use ($boundary) {
            $part = "--{$boundary}\r\n{$part}";
        });

        // add final boundary
        $body[] = "--{$boundary}--";
        $body[] = "";

        // set options
        return @curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => implode("\r\n", $body),
                CURLOPT_HTTPHEADER => array(
                    "Expect: 100-continue",
                    "Content-Type: multipart/form-data; boundary={$boundary}", // change Content-Type
                ),
            )
        );
    }


    /**
     * Send products attributes data
     *
     * @return bool
     */
    protected function sendProductAttributesData()
    {
        $result = array();

        $attributes = Mage::getResourceModel('catalog/product_attribute_collection')->getItems();

        foreach ($attributes as $attribute) {
            if (empty($this->_allowedAttributes[$attribute->getId()])) {
                continue;
            }
            $data = $attribute->getData();

            $options = array();
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (is_array($option["value"])) {
                    continue;
                }
                $options[trim($option["value"])] = $option["label"];
            }
            $data['options'] = ($options ? json_encode($options) : "");

            $result[] = $data;
        }

        $this->sendRequest($this->_apiUrl . $this->_apiUrlPrefix . "/product/attributes", $result);
//        var_dump($result);
//        exit("<br/>DELETE ME(EXIT())<br/>");
        return true;
    }

    /**
     * Send products data
     *
     * @param int $page
     * @param int $limit
     * @return bool|mixed
     */
    protected function sendProductsData($page = 0, $limit = 10)
    {
        $result = array();
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect('*')
            ->setOrder('entity_id', 'ASC');

        $collection->getSelect()
            ->limit($limit, $limit * $page);
//        var_dump($collection->getSelect()->__toString());
        $products = $collection->load();
        if (count($products) == 0) {
            return false;
        }

        foreach ($products as $product) {
            $attributes = $product->getTypeInstance(true)->getSetAttributes($product);
            $media_gallery = $attributes['media_gallery'];
            $backend = $media_gallery->getBackend();
            $backend->afterLoad($product);

            $data = $product->getData();
            if ($product->getTypeId() === "configurable") {
                $childProducts = Mage::getModel('catalog/product_type_configurable')
                    ->getUsedProductIds($product);

                $data['children'] = $childProducts;
            }
            $data['category_ids'] = $product->getCategoryIds();
            $data['images'] = array();
            if ($images = $product->getMediaGalleryImages()->getItems()) {
                foreach ($images as $image) {
                    if (!$image->getFile()) {
                        continue;
                    }
                    $imageName = explode('/', $image->getFile());
                    $imageName = end($imageName);

                    $data['images'][] = array(
                        'image_url' => $image->getUrl(),
                        'image_name' => $imageName,
                    );

                }
            }

            if ($attributes = $product->getAttributes()) {
                foreach ($attributes as $attribute) {
                    $data['attributes'][] = $attribute->getData();
                }
            }

            $result[] = $data;
        }
//var_dump($result);exit("<br/>DELETE ME(EXIT())<br/>");
        $this->sendRequest($this->_apiUrl . $this->_apiUrlPrefix . "/products", $result);
//        var_dump(json_decode(json_encode($result), true));
//        exit("<br/>DELETE ME(EXIT())<br/>");

        return $this->sendProductsData($page + 1);
    }

    /**
     * Send products stock data
     *
     * @param int $page
     * @param int $limit
     * @return bool
     */
    protected function sendStockData($page = 0, $limit = 20)
    {
        $result = array();
//        $stock = Mage::getModel('cataloginventory/stock_item')->getCollection()
        $collection = Mage::getModel('cataloginventory/stock')->getItemCollection();


        $collection->getSelect()
            ->limit($limit, $limit * $page);
//        var_dump($collection->getSelect()->__toString());
        $stock = $collection->load();
        if (count($stock) == 0) {
            return false;
        }

        foreach ($stock as $stockData) {
//            var_dump($stockData->getData());
//            exit("<br/>DELETE ME(EXIT())<br/>");
            $result[] = $stockData->getData();
        }
//        var_dump($result);
//        exit("<br/>DELETE ME(EXIT())<br/>");

        $this->sendRequest($this->_apiUrl . $this->_apiUrlPrefix . "/stock", $result);

//        var_dump($result);
//        exit("<br/>DELETE ME(EXIT())<br/>");

        return $this->sendStockData($page + 1);
    }

    /**
     * Send categories data
     *
     * @param int $page
     * @param int $limit
     * @return bool
     */
    protected function sendCategoriesData($page = 0, $limit = 100)
    {
        $result = array();
        $collection = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToSelect('*')
            ->setOrder('level', 'ASC');

        $collection->getSelect()
            ->limit($limit, $limit * $page);
//        var_dump($collection->getSelect()->__toString());
        $categories = $collection->load();

        if (count($categories) == 0) {
            return false;
        }

        foreach ($categories as $category) {
            $data = $category->getData();
            $data['image_url'] = "";
            if ($category->getImageUrl()) {
                $data['image_url'] = $category->getImageUrl();
            }

            $result[] = $data;
        }

        $this->sendRequest($this->_apiUrl . $this->_apiUrlPrefix . "/category", $result);

        return $this->sendCategoriesData($page + 1);
    }

    /**
     * Send orders data
     *
     * @param int $page
     * @param int $limit
     * @return bool|mixed
     */
    protected function sendOrdersData($page = 0, $limit = 10)
    {
        $result = array();
        $collection = Mage::getModel('sales/order')->getCollection()
            ->addAttributeToSelect('*');

        $collection->getSelect()
            ->limit($limit, $limit * $page);
//        var_dump($collection->getSelect()->__toString());
        $orders = $collection->load();

        if (count($orders) == 0) {
            return false;
        }

        foreach ($orders as $order) {
            $orderData = $order->getData();
            $customerId = $order->getCustomerId();
            $customerData = array();
            if ($customerId) {
                $customerData = Mage::getModel('customer/customer')->load($customerId);
                $customerData = $customerData->getData();
            }

            $orderData['customerInfo'] = $customerData;

            $orderData['paymentInfo'] = array();
            if ($order->getPayment() && $order->getPayment()->getData()) {
                $orderData['paymentInfo'] = $order->getPayment()->getData();
            }

            $orderData['billingAddress'] = array();
            if ($order->getBillingAddress() && $order->getBillingAddress()->getData()) {
                $orderData['billingAddress'] = $order->getBillingAddress()->getData();
            }

            $orderData['shippingAddress'] = array();
            if ($order->getShippingAddress() && $order->getShippingAddress()->getData()) {
                $orderData['shippingAddress'] = $order->getShippingAddress()->getData();
            }

            foreach ($order->getAllVisibleItems() as $item) {
                $orderData["items"][] = $item->getData();
            }
            $result[] = $orderData;
        }

//        var_dump($result);exit("<br/>DELETE ME(EXIT())<br/>");
        $this->sendRequest($this->_apiUrl . $this->_apiUrlPrefix . "/order", $result);

//        var_dump($result);exit("<br/>DELETE ME(EXIT())<br/>");

        return $this->sendOrdersData($page + 1);
    }

    /**
     * Send customers data
     *
     * @return array
     */
    protected function sendCustomersData()
    {
        $groupsArray = array();
        $groups = Mage::getModel('customer/group')->getCollection();
        foreach ($groups as $group) {
            $groupsArray[$group->getCustomerGroupId()] = $group->getCustomerGroupCode();
        }
        unset($groups);

        $result = array();
        $users = Mage::getModel('customer/customer')->getCollection()
            ->addAttributeToSelect('*')
            ->load();

        foreach ($users as $user) {
            $result[$user->getId()] = $user->getData();
            $result[$user->getId()]['group'] = $groupsArray[$user->getGroupId()];
        }

        unset($users);
        unset($groupsArray);
        $usersAddresses = Mage::getModel('customer/address')->getCollection()
            ->addAttributeToSelect('*')
            ->load();

        foreach ($usersAddresses as $usersAddress) {
            if (!isset($result[$usersAddress->getParentId()]["address"])) {
                $result[$usersAddress->getParentId()]["address"] = array();
            }
            $addressData = $usersAddress->getData();
            $addressData["default_billing"] = false;
            $addressData["default_shipping"] = false;

            if (!empty($result[$usersAddress->getParentId()]["default_billing"]) &&
                $result[$usersAddress->getParentId()]["default_billing"] == $addressData["entity_id"]
            ) {
                $addressData["default_billing"] = true;
            }

            if (!empty($result[$usersAddress->getParentId()]["default_shipping"]) &&
                $result[$usersAddress->getParentId()]["default_shipping"] == $addressData["entity_id"]
            ) {
                $addressData["default_shipping"] = true;
            }

            $result[$usersAddress->getParentId()]["address"][] = $addressData;
        }

        $this->sendRequests($this->_apiUrl . $this->_apiUrlPrefix . "/visitor", $result);

//        var_dump($result);exit("<br/>DELETE ME(EXIT())<br/>");

        return $result;
    }

    /**
     * @return mixed
     */
    protected function _isAllowed()
    {

        switch ($this->getRequest()->getActionName()) {
            case 'export':
            case 'exportPost':
                return Mage::getSingleton('admin/session')
                    ->isAllowed('ottemo/ottemo_exportdata');
                break;
        }

        return false;
    }
}
