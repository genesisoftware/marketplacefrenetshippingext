<?php
namespace Genesisoft\MarketplaceFrenetShippingExt\Preference\Webkul\MarketplaceFrenetShipping\Model;

class Carrier extends \Webkul\MarketplaceFrenetShipping\Model\Carrier
{
    /**
     * Build RateV3 request, send it to Dhl gateway and retrieve quotes in XML format.
     *
     * @return Result
     */
    public function getShippingPricedetail(\Magento\Framework\DataObject $request)
    {
        $submethod = [];
        $shippinginfo = [];
        $totalpric = [];
        $this->_totalPriceArr = [];
        $serviceCodeToActualNameMap = [];
        $costArr = [];
        $debugData = [];
        $price = 0;

        foreach ($request->getShippingDetails() as $shipdetail) {
            $priceArr = [];
            $this->_isSellerHasOwnCredentials($request, $shipdetail['seller_id']);

            $req = $this->requestInterface;
            if ($req->getModuleName() == 'multishipping' && $req->getControllerName() == 'checkout') {
                $checkVar = 0;
            } else {
                $request->setShipDetail($shipdetail);
            }

            try {
                $response = $this->_getWebServicesQuote($request);

                $return['type'] = 'error';
                if ($response !== false &&
                    isset($response->GetShippingQuoteResult) &&
                    isset($response->GetShippingQuoteResult->ShippingSevicesArray->ShippingSevices)
                ) {
                    $this->_debug("Qtd services: " . count($response->GetShippingQuoteResult
                            ->ShippingSevicesArray->ShippingSevices));
                    $existReturn = false;
                    if (count($response->GetShippingQuoteResult->ShippingSevicesArray->ShippingSevices) == 1) {
                        $servicesArray[0] = $response->GetShippingQuoteResult->ShippingSevicesArray->ShippingSevices;
                    } else {
                        $servicesArray = $response->GetShippingQuoteResult->ShippingSevicesArray->ShippingSevices;
                    }

                    foreach ($servicesArray as $services) {
                        if (!isset($services->ServiceCode)
                            || $services->ServiceCode . '' == ''
                            || !isset($services->ShippingPrice)
                        ) {
                            continue;
                        }
                        $return['type'] = 'success';

                        $shippingPrice = floatval(str_replace(",", ".", (string) $services->ShippingPrice));
                        $delivery = (int) $services->DeliveryTime;
                        $shippingMethod = $services->ServiceCode;
                        $shippingMethodName = $services->ServiceDescription;
                        $priceArr[$shippingMethod] = [
                            'label' => $shippingMethodName,
                            'amount' => $shippingPrice,
                            'delivery' => $delivery
                        ];

                        if (isset($costArr[$shippingMethod])) {
                            $costArr[$shippingMethod] += $shippingPrice;
                        } else {
                            $costArr[$shippingMethod] = $shippingPrice;
                        }
                        $existReturn = true;
                    }
                    // All services are ignored
                    if ($existReturn  ===  false) {
                        $return['type'] = 'error';
                        $this->frenetLogger->info("All services are ignored [" . __LINE__ . "]");
                        if ($this->isMultiShippingActive()) {
                            return [];
                        } else {
                            return $this->_appendShippingMethod($return);
                        }
                    }
                } else {
                    $return['type'] = 'error';
                    $this->frenetLogger->info("Shipping Methods not available for all sellers. [" . __LINE__ . "]");
                    if ($this->isMultiShippingActive()) {
                        return [];
                    } else {
                        return $this->_appendShippingMethod($return);
                    }
                }
            } catch (\Exception $e) {
                $return['type'] = 'error';
                $return['error'] = $e->getMessage();
                $this->frenetLogger->info($e->getMessage());
            }
            $this->_filterSellerRate($priceArr);

            if ($this->_flag) {
                $this->frenetLogger->info("All Sellers does not have methods in common. [" . __LINE__ . "]");
                $return['type'] = 'error';
                $debugData = $return;
                if ($this->isMultiShippingActive()) {
                    return [];
                } else {
                    return $this->_appendShippingMethod($debugData);
                }
            }
            $submethod = [];

            foreach ($priceArr as $index => $price) {
                $submethod[$index] = [
                    'method' => $price['label'],
                    'cost' => $price['amount'],
                    'base_amount' => $price['amount'],
                    'error' => 0,
                ];
            }
            array_push(
                $shippinginfo,
                [
                    'seller_id' => $shipdetail['seller_id'],
                    'methodcode' => $this->_code,
                    'shipping_ammount' => $price,
                    'product_name' => $shipdetail['product_name'],
                    'submethod' => $submethod,
                    'item_ids' => $shipdetail['item_id'],
                ]
            );
        }
        $totalpric = ['totalprice' => $this->_totalPriceArr, 'costarr' => $costArr];
        $result = ['handlingfee' => $totalpric, 'shippinginfo' => $shippinginfo];
        $shippingAll = [];
        $shippingAll[$this->_code] = $result['shippinginfo'];
        $this->setShippingInformation($shippingAll);
        $this->_coreSession->setData('shippinginfo',$shippingAll);

        if (!$this->isMultiShippingActive() ||
            !$this->_scopeConfig->getValue('carriers/wk_pickup/active')) {
            return $this->_appendShippingMethod($totalpric);
        } else {
            return $result;
        }
    }

    /**
     * @param [type] $items
     * @return $this
     */
    protected function getSimpleProducts($items, $request = null)
    {
        $j = $i = 0;
        $productQty = [];
        $shipdetail = $this->_rawRequest->getShipDetail();
        $sellerItems = explode(',', $shipdetail['item_id']);

        $currentUrl = $this->urlInterface->getCurrentUrl();
        foreach ($items as $child) {
            if (!in_array($child->getId(), $sellerItems)) {
                continue;
            }
            if ($child->getProduct()->isVirtual() || $child->getParentItem()) {
                continue;
            }
            $productId = $child->getProductId();
            $product = $this->productFactory->create()->load($productId);
            $type_id = $product->getTypeId();

            $parentItem = $child->getParentItem();
            if ($child->getHasChildren()) {
                $_product = $this->productFactory
                    ->create()
                    ->load($child->getProductId());
                if ($_product->getTypeId() == 'bundle' || $_product->getTypeId() == 'configurable') {
                    foreach ($child->getChildren() as $child1) {
                        list($count, $this->_simpleProducts[$j], $this->_productsQty[$j]) =
                            $this->setChildProductDeatils($j, $child, $child1);
                        $j = $count;
                    }
                }
            } else {
                $product = $this->productFactory->create()->load($child->getProductId());
                $qty = $this->_getQty($child);
                $this->_simpleProducts [$j] = $product;
                $this->_productsQty [$j] = (int)$qty;
                $j++;
            }
        }

        return $this;
    }
}
