<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\BundleImportExport\Model\Export;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogImportExport\Model\Export\RowCustomizerInterface;
use Magento\CatalogImportExport\Model\Import\Product as ImportProductModel;
use Magento\Bundle\Model\ResourceModel\Selection\Collection as SelectionCollection;
use Magento\ImportExport\Model\Import as ImportModel;
use \Magento\Catalog\Model\Product\Type\AbstractType;
use \Magento\Framework\App\ObjectManager;
use \Magento\Store\Model\StoreManagerInterface;

/**
 * Class RowCustomizer
 */
class RowCustomizer implements RowCustomizerInterface
{
    const BUNDLE_PRICE_TYPE_COL = 'bundle_price_type';

    const BUNDLE_SKU_TYPE_COL = 'bundle_sku_type';

    const BUNDLE_PRICE_VIEW_COL = 'bundle_price_view';

    const BUNDLE_WEIGHT_TYPE_COL = 'bundle_weight_type';

    const BUNDLE_VALUES_COL = 'bundle_values';

    const VALUE_FIXED = 'fixed';

    const VALUE_DYNAMIC = 'dynamic';

    const VALUE_PERCENT = 'percent';

    const VALUE_PRICE_RANGE = 'Price range';

    const VALUE_AS_LOW_AS = 'As low as';

    /**
     * Mapping for bundle types
     *
     * @var array
     */
    protected $typeMapping = [
        '0' => self::VALUE_DYNAMIC,
        '1' => self::VALUE_FIXED
    ];

    /**
     * Mapping for price views
     *
     * @var array
     */
    protected $priceViewMapping = [
        '0' => self::VALUE_PRICE_RANGE,
        '1' => self::VALUE_AS_LOW_AS
    ];

    /**
     * Mapping for price types
     *
     * @var array
     */
    protected $priceTypeMapping = [
        '0' => self::VALUE_FIXED,
        '1' => self::VALUE_PERCENT
    ];

    /**
     * Bundle product columns
     *
     * @var array
     */
    protected $bundleColumns = [
        self::BUNDLE_PRICE_TYPE_COL,
        self::BUNDLE_SKU_TYPE_COL,
        self::BUNDLE_PRICE_VIEW_COL,
        self::BUNDLE_WEIGHT_TYPE_COL,
        self::BUNDLE_VALUES_COL
    ];

    /**
     * Product's bundle data
     *
     * @var array
     */
    protected $bundleData = [];

    /**
     * Column name for shipment_type attribute
     *
     * @var string
     */
    private $shipmentTypeColumn = 'bundle_shipment_type';

    /**
     * Mapping for shipment type
     *
     * @var array
     */
    private $shipmentTypeMapping = [
        AbstractType::SHIPMENT_TOGETHER => 'together',
        AbstractType::SHIPMENT_SEPARATELY => 'separately',
    ];

    /**
     * @var \Magento\Bundle\Model\ResourceModel\Option\Collection[]
     */
    private $optionsCollection = [];

    /**
     * @var array
     */
    private $storeIdToCode = [];

    /**
     * @var string
     */
    private $optionCollectionCacheKey = '_cache_instance_options_collection';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * Retrieve list of bundle specific columns
     * @return array
     */
    private function getBundleColumns()
    {
        return array_merge($this->bundleColumns, [$this->shipmentTypeColumn]);
    }

    /**
     * Prepare data for export
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @param int[] $productIds
     * @return $this
     */
    public function prepareData($collection, $productIds)
    {
        $productCollection = clone $collection;
        $productCollection->addAttributeToFilter(
            'entity_id',
            ['in' => $productIds]
        )->addAttributeToFilter(
            'type_id',
            ['eq' => \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE]
        );

        return $this->populateBundleData($productCollection);
    }

    /**
     * Set headers columns
     *
     * @param array $columns
     * @return array
     */
    public function addHeaderColumns($columns)
    {
        $columns = array_merge($columns, $this->getBundleColumns());

        return $columns;
    }

    /**
     * Add data for export
     *
     * @param array $dataRow
     * @param int $productId
     * @return array
     */
    public function addData($dataRow, $productId)
    {
        if (!empty($this->bundleData[$productId])) {
            $dataRow = array_merge($this->cleanNotBundleAdditionalAttributes($dataRow), $this->bundleData[$productId]);
        }

        return $dataRow;
    }

    /**
     * Calculate the largest links block
     *
     * @param array $additionalRowsCount
     * @param int $productId
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getAdditionalRowsCount($additionalRowsCount, $productId)
    {
        return $additionalRowsCount;
    }

    /**
     * Populate bundle product data
     *
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @return $this
     */
    protected function populateBundleData($collection)
    {
        foreach ($collection as $product) {
            $id = $product->getEntityId();
            $this->bundleData[$id][self::BUNDLE_PRICE_TYPE_COL] = $this->getTypeValue($product->getPriceType());
            $this->bundleData[$id][$this->shipmentTypeColumn] = $this->getShipmentTypeValue(
                $product->getShipmentType()
            );
            $this->bundleData[$id][self::BUNDLE_SKU_TYPE_COL] = $this->getTypeValue($product->getSkuType());
            $this->bundleData[$id][self::BUNDLE_PRICE_VIEW_COL] = $this->getPriceViewValue($product->getPriceView());
            $this->bundleData[$id][self::BUNDLE_WEIGHT_TYPE_COL] = $this->getTypeValue($product->getWeightType());
            $this->bundleData[$id][self::BUNDLE_VALUES_COL] = $this->getFormattedBundleOptionValues($product);
        }
        return $this;
    }

    /**
     * Retrieve formatted bundle options
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    protected function getFormattedBundleOptionValues($product)
    {
        $optionsCollection = $this->getProductOptionsCollection($product);
        $bundleData = '';
        $optionTitles = $this->getBundleOptionTitles($product);
        foreach ($optionsCollection->getItems() as $option) {
            $optionValues = $this->getFormattedOptionValues($option, $optionTitles);
            $bundleData .= $this->getFormattedBundleSelections(
                $optionValues,
                $product->getTypeInstance()
                    ->getSelectionsCollection([$option->getId()], $product)
                    ->setOrder('position', Collection::SORT_ORDER_ASC)
            );
        }

        return rtrim($bundleData, ImportProductModel::PSEUDO_MULTI_LINE_SEPARATOR);
    }

    /**
     * Retrieve formatted bundle selections
     *
     * @param string $optionValues
     * @param SelectionCollection $selections
     * @return string
     */
    protected function getFormattedBundleSelections($optionValues, SelectionCollection $selections)
    {
        $bundleData = '';
        $selections->addAttributeToSort('position');
        foreach ($selections as $selection) {
            $selectionData = [
                'sku' => $selection->getSku(),
                'price' => $selection->getSelectionPriceValue(),
                'default' => $selection->getIsDefault(),
                'default_qty' => $selection->getSelectionQty(),
                'price_type' => $this->getPriceTypeValue($selection->getSelectionPriceType())
            ];
            $bundleData .= $optionValues
                . ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
                . implode(
                    ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR,
                    array_map(
                        function ($value, $key) {
                            return $key . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR . $value;
                        },
                        $selectionData,
                        array_keys($selectionData)
                    )
                )
                . ImportProductModel::PSEUDO_MULTI_LINE_SEPARATOR;
        }

        return $bundleData;
    }

    /**
     * Retrieve option value of bundle product
     *
     * @param \Magento\Bundle\Model\Option $option
     * @param array $optionTitles
     * @return string
     */
    protected function getFormattedOptionValues($option, $optionTitles = [])
    {
        $names = implode(ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, array_map(
            function ($title, $storeName) {
                return $storeName . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR . $title;
            },
            $optionTitles[$option->getOptionId()],
            array_keys($optionTitles[$option->getOptionId()])
        ));
        return $names . ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
            . 'type' . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR
            . $option->getType() . ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR
            . 'required' . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR
            . $option->getRequired();
    }

    /**
     * Retrieve bundle type value by code
     *
     * @param string $type
     * @return string
     */
    protected function getTypeValue($type)
    {
        return isset($this->typeMapping[$type]) ? $this->typeMapping[$type] : self::VALUE_DYNAMIC;
    }

    /**
     * Retrieve bundle price view value by code
     *
     * @param string $type
     * @return string
     */
    protected function getPriceViewValue($type)
    {
        return isset($this->priceViewMapping[$type]) ? $this->priceViewMapping[$type] : self::VALUE_PRICE_RANGE;
    }

    /**
     * Retrieve bundle price type value by code
     *
     * @param string $type
     * @return string
     */
    protected function getPriceTypeValue($type)
    {
        return isset($this->priceTypeMapping[$type]) ? $this->priceTypeMapping[$type] : null;
    }

    /**
     * Retrieve bundle shipment type value by code
     *
     * @param string $type
     * @return string
     */
    private function getShipmentTypeValue($type)
    {
        return isset($this->shipmentTypeMapping[$type]) ? $this->shipmentTypeMapping[$type] : null;
    }

    /**
     * Remove bundle specified additional attributes as now they are stored in specified columns
     *
     * @param array $dataRow
     * @return array
     */
    protected function cleanNotBundleAdditionalAttributes($dataRow)
    {
        if (!empty($dataRow['additional_attributes'])) {
            $additionalAttributes = $this->parseAdditionalAttributes($dataRow['additional_attributes']);
            $dataRow['additional_attributes'] = $this->getNotBundleAttributes($additionalAttributes);
        }

        return $dataRow;
    }

    /**
     * Retrieve not bundle additional attributes
     *
     * @param array $additionalAttributes
     * @return string
     */
    protected function getNotBundleAttributes($additionalAttributes)
    {
        $filteredAttributes = [];
        foreach ($additionalAttributes as $code => $value) {
            if (!in_array('bundle_' . $code, $this->getBundleColumns())) {
                $filteredAttributes[] = $code . ImportProductModel::PAIR_NAME_VALUE_SEPARATOR . $value;
            }
        }
        return implode(ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $filteredAttributes);
    }

    /**
     * Retrieves additional attributes as array code=>value.
     *
     * @param string $additionalAttributes
     * @return array
     */
    private function parseAdditionalAttributes($additionalAttributes)
    {
        $attributeNameValuePairs = explode(ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR, $additionalAttributes);
        $preparedAttributes = [];
        $code = '';
        foreach ($attributeNameValuePairs as $attributeData) {
            //process case when attribute has ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR inside its value
            if (strpos($attributeData, ImportProductModel::PAIR_NAME_VALUE_SEPARATOR) === false) {
                if (!$code) {
                    continue;
                }
                $preparedAttributes[$code] .= ImportModel::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR . $attributeData;
                continue;
            }
            list($code, $value) = explode(ImportProductModel::PAIR_NAME_VALUE_SEPARATOR, $attributeData, 2);
            $preparedAttributes[$code] = $value;
        }
        return $preparedAttributes;
    }

    /**
     * Get product options titles.
     * Values for all store views (default) should be specified with 'name' key.
     * If user want to specify value or change existing for non default store views it should be specified with
     * 'name_' prefix and needed store view suffix.
     *
     * For example:
     *  - 'name=All store views name' for all store views
     *  - 'name_specific_store=Specific store name' for store view with 'specific_store' store code
     *
     * @param \Magento\Catalog\Model\Product $product $product
     * @return array
     */
    private function getBundleOptionTitles($product)
    {
        $optionsCollection = $this->getProductOptionsCollection($product);
        $optionsTitles = [];
        /** @var \Magento\Bundle\Model\Option $option */
        foreach ($optionsCollection->getItems() as $option) {
            $optionsTitles[$option->getId()]['name'] = $option->getTitle();
        }
        $storeIds = $product->getStoreIds();
        if (array_count_values($storeIds) > 1) {
            foreach ($storeIds as $storeId) {
                $optionsCollection = $this->getProductOptionsCollection($product, $storeId);
                /** @var \Magento\Bundle\Model\Option $option */
                foreach ($optionsCollection->getItems() as $option) {
                    $optionTitle = $option->getTitle();
                    if ($optionsTitles[$option->getId()]['name'] != $optionTitle) {
                        $optionsTitles[$option->getId()]['name_' . $this->getStoreCodeById($storeId)] = $optionTitle;
                    }
                }
            }
        }
        return $optionsTitles;
    }

    /**
     * Get product options collection by provided product model.
     * Set given store id to the product if it was defined (default store id will be set if was not).
     *
     * @param \Magento\Catalog\Model\Product $product $product
     * @param integer $storeId
     * @return \Magento\Bundle\Model\ResourceModel\Option\Collection
     */
    private function getProductOptionsCollection($product, $storeId = \Magento\Store\Model\Store::DEFAULT_STORE_ID)
    {
        $productSku = $product->getSku();
        if (!isset($this->optionsCollection[$productSku][$storeId])) {
            $product->unsetData($this->optionCollectionCacheKey);
            $product->setStoreId($storeId);
            $this->optionsCollection[$productSku][$storeId] = $product->getTypeInstance()
                ->getOptionsCollection($product)
                ->setOrder('position', Collection::SORT_ORDER_ASC);
        }
        return $this->optionsCollection[$productSku][$storeId];
    }

    /**
     * Retrieve store code by it's ID.
     * Collect store id in $storeIdToCode[] private variable if it was not initialized earlier.
     *
     * @param $storeId
     * @return mixed
     */
    private function getStoreCodeById($storeId)
    {
        if (!isset($this->storeIdToCode[$storeId])) {
            $this->storeIdToCode[$storeId] = $this->getStoreManager()->getStore($storeId);
        }
        return $this->storeIdToCode[$storeId];
    }

    /**
     * Initialize StoreManagerInterface if it was not and return it.
     *
     * @return StoreManagerInterface
     * @throws \RuntimeException
     */
    private function getStoreManager()
    {
        if (!$this->storeManager) {
            $this->storeManager = ObjectManager::getInstance()->get(StoreManagerInterface::class);
        }
        return $this->storeManager;
    }
}
