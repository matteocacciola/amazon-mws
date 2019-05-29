<?php

namespace MCS;

use \DateTime;
use \Exception;
use MCS\MWSEndPoint;
use League\Csv\Reader;
use League\Csv\Writer;
use SplTempFileObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

class MWSClient
{

    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';

    private $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];

    private $MarketplaceIds = [
        'A2Q3Y263D00KWC' => 'mws.amazonservices.com',
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'ATVPDKIKX0DER' => 'mws.amazonservices.com',
        'A2VIGQ35RCS4UG' => 'mws.amazonservices.ae',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV' => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4' => 'mws-eu.amazonservices.com',
        'A33AVAJ2PDY3EV' => 'mws-eu.amazonservices.com',
        'A39IBJ37TRP1C6' => 'mws.amazonservices.com.au',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'AAHKV2X7AFYLW' => 'mws.amazonservices.com.cn',
    ];

    /* private $MarketplaceCenters = [
        'A2Q3Y263D00KWC' => 'AMAZON_NA',
        'A2EUQ1WTGCTBG2' => 'AMAZON_NA',
        'A1AM78C64UM0Y8' => 'AMAZON_NA',
        'ATVPDKIKX0DER' => 'AMAZON_NA',
        'A2VIGQ35RCS4UG' => null,
        'A1PA6795UKMFR9' => 'AMAZON_EU',
        'A1RKKUPIHCS9HS' => 'AMAZON_EU',
        'A13V1IB3VIYZZH' => 'AMAZON_EU',
        'A1F83G8C2ARO7P' => 'AMAZON_EU',
        'A21TJRUUN4KGV' => 'AMAZON_IN',
        'APJ6JRA9NG5V4' => 'AMAZON_EU',
        'A33AVAJ2PDY3EV' => 'AMAZON_EU',
        'A39IBJ37TRP1C6' => null,
        'A1VC38T7YXB528' => 'AMAZON_JP',
        'AAHKV2X7AFYLW' => 'AMAZON_CN',
    ]; */

    protected $debugNextFeed = false;
    protected $client;

    /**
     * MWSClient constructor.
     *
     * @param array $config
     *
     * @throws \Exception
     */
    public function __construct(array $config)
    {

        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        $required_keys = [
            'Marketplace_Id',
            'Seller_Id',
            'Access_Key_ID',
            'Secret_Access_Key'
        ];

        foreach ($required_keys as $key) {
            if (is_null($this->config[$key])) {
                throw new \Exception('Required field ' . $key . ' is not set');
            }
        }

        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new \Exception('Invalid Marketplace Id');
        }

        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];

    }

    /**
     * @param Client $client
     */
    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed()
    {
        $this->debugNextFeed = true;
    }

    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     */
    public function validateCredentials()
    {
        try {
            $this->ListOrderItems('validate');
        } catch (Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate') {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Returns a list of all feed submissions submitted in the previous 90 days.
     *
     * @return array
     */
    public function GetFeedSubmissionList()
    {
        $result = $this->request('GetFeedSubmissionList');
        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

    /**
     * Returns the current competitive price of a product, based on ASIN.
     *
     * @param array [$asin_array = []]
     *
     * @return array
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );

        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }

        return $array;
    }

    /**
     * Returns the current competitive price of a product, based on SKU.
     *
     * @param array [$sku_array = []]
     *
     * @return array
     * @throws \Exception
     */
    public function GetCompetitivePricingForSKU($sku_array = [])
    {
        if (count($sku_array) > 20) {
            throw new \Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetCompetitivePricingForSKU',
            $query
        );

        if (isset($response['GetCompetitivePricingForSKUResult'])) {
            $response = $response['GetCompetitivePricingForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Price'] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Rank'] = $product['Product']['SalesRankings']['SalesRank'][1];
            }
        }

        return $array;

    }

    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     *
     * @param string $asin
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     *
     * @return array
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {

        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];

        return $this->request('GetLowestPricedOffersForASIN', $query);
    }

    /**
     * Returns pricing information for your own offer listings, based on SKU.
     *
     * @param array  [$sku_array = []]
     * @param string [$ItemCondition = null]
     *
     * @return array
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );

        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                if (isset($product['Product']['Offers']['Offer'])) {
                    $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
                } else {
                    $array[$product['@attributes']['SellerSKU']] = [];
                }
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }

        return $array;

    }

    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     *
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null]
     *
     * @return array
     * @throws Exception
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );

        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success' && isset($product['Product']['Offers']['Offer'])) {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }

        return $array;
    }

    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     *
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     *
     * @return array
     * @throws Exception
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );

        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }

        return $array;
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     *
     * @param \DateTime $from , beginning of time frame
     * @param boolean $allMarketplaces , list orders from all marketplaces
     * @param array $statuses , an array containing orders states you want to filter on
     * @param string $FulfillmentChannels
     * @param \DateTime $till , end of time frame
     * @param bool $fromUpdated
     *
     * @return array|mixed|string
     * @throws Exception
     */
    public function ListOrders(
        \DateTime $from,
        $allMarketplaces = false,
        $statuses = [MWSOrder::STATUS_UNSHIPPED, MWSOrder::STATUS_PARTIALLY_SHIPPED],
        $FulfillmentChannels = 'MFN',
        \DateTime $till = null,
        $fromUpdated = false
    ) {
        if (empty($statuses)) {
            $statuses = MWSOrder::getStatuses();
        }

        // there are some $statuses which are not valid
        if (array_diff($statuses, MWSOrder::getStatuses())) {
            throw new \Exception('Invalid value(s) passed for statuses', 400);
        }

        $conditionStatusUnshipped = in_array(MWSOrder::STATUS_UNSHIPPED, $statuses);
        $conditionStatusPartiallyShipped = in_array(MWSOrder::STATUS_PARTIALLY_SHIPPED, $statuses);
        $conditionBothShipping = $conditionStatusUnshipped && $conditionStatusPartiallyShipped;
        $conditionNoneShipping = !$conditionStatusUnshipped && !$conditionStatusPartiallyShipped;

        if (!$conditionBothShipping && !$conditionNoneShipping) {
            throw new \Exception('Statuses "Unshipped" and "PartiallyShipped" must be present either at the same time or none of the two', 400);
        }

        if ($fromUpdated) {
            $query = [
                'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
            ];
        } else {
            $query = [
                'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
            ];
        }

        if ($till !== null) {
            $query['CreatedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }

        $counter = 1;
        foreach ($statuses as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }

        if ($allMarketplaces == true) {
            $counter = 1;
            foreach ($this->MarketplaceIds as $key => $value) {
                $query['MarketplaceId.Id.' . $counter] = $key;
                $counter = $counter + 1;
            }
        }

        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }

        $response = $this->request('ListOrders', $query);

        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersResult']['NextToken'];

                return $data;
            }

            $response = $response['ListOrdersResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;

        } else {
            return [];
        }
    }

    /**
     * Returns orders created or updated during a time frame that you specify, surfing along all the next tokens.
     *
     * @param \DateTime $from , beginning of time frame
     * @param boolean $allMarketplaces , list orders from all marketplaces
     * @param array $statuses , an array containing orders states you want to filter on
     * @param string $FulfillmentChannels
     * @param \DateTime $till , end of time frame
     * @param bool $fromUpdated
     *
     * @return array
     * @throws Exception
     */
    public function ListOrdersWithAllNextTokens(
        \DateTime $from,
        $allMarketplaces = false,
        $statuses = ['Unshipped', 'PartiallyShipped'],
        $FulfillmentChannels = 'MFN',
        \DateTime $till = null,
        $fromUpdated = false
    ) {
        if (empty($statuses)) {
            $statuses = MWSOrder::getStatuses();
        }

        // there are some $statuses which are not valid
        if (array_diff($statuses, MWSOrder::getStatuses())) {
            throw new \Exception('Invalid value(s) passed for statuses', 400);
        }

        $conditionStatusUnshipped = in_array(MWSOrder::STATUS_UNSHIPPED, $statuses);
        $conditionStatusPartiallyShipped = in_array(MWSOrder::STATUS_PARTIALLY_SHIPPED, $statuses);
        $conditionBothShipping = $conditionStatusUnshipped && $conditionStatusPartiallyShipped;
        $conditionNoneShipping = !$conditionStatusUnshipped && !$conditionStatusPartiallyShipped;

        if (!$conditionBothShipping && !$conditionNoneShipping) {
            throw new \Exception('Statuses "Unshipped" and "PartiallyShipped" must be present either at the same time or none of the two', 400);
        }

        if ($fromUpdated) {
            $query = [
                'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
            ];
        } else {
            $query = [
                'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
            ];
        }

        if ($till !== null) {
            $query['CreatedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }

        $counter = 1;
        foreach ($statuses as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }

        if ($allMarketplaces == true) {
            $counter = 1;
            foreach ($this->MarketplaceIds as $key => $value) {
                $query['MarketplaceId.Id.' . $counter] = $key;
                $counter = $counter + 1;
            }
        }

        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }

        $response = $this->request('ListOrders', $query);
        $arrResponses[] = $response;

        if (isset($response['ListOrdersResult']['NextToken'])) {
            do {
                $query = [
                    'NextToken' => $response['ListOrdersResult']['NextToken']
                ];
                $response = $this->request(
                    'ListOrdersByNextToken',
                    $query
                );
                $arrResponses[] = $response;
            } while (isset($response['ListOrdersResult']['NextToken']));
        }

        $finalResponse = [];
        foreach ($arrResponses as $response) {
            $arrOrders = [];
            if (isset($response['ListOrdersResult']['Orders']['Order'])) {
                $arrOrders = $response['ListOrdersResult']['Orders']['Order'];
            }
            if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
                $arrOrders = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
            }

            $finalResponse = (count($arrOrders) > 0)
                ? ((array_keys($arrOrders) !== range(0, count($arrOrders) - 1)) ? array_merge($finalResponse, [$arrOrders]) : array_merge($finalResponse, $arrOrders))
                : array_merge($finalResponse, []);
        }

        return $finalResponse;
    }

    /**
     * Returns orders created or updated, by the $nextToken.
     *
     * @param string $nextToken
     *
     * @return array
     */
    public function ListOrdersByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];

        $response = $this->request(
            'ListOrdersByNextToken',
            $query
        );
        if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersByNextTokenResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersByNextTokenResult']['NextToken'];

                return $data;
            }
            $response = $response['ListOrdersByNextTokenResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     *
     * @param string $AmazonOrderId
     *
     * @return string|null string if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId)
    {
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]);

        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify.
     *
     * @param string $AmazonOrderId
     *
     * @return array
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);

        $result = array_values($response['ListOrderItemsResult']['OrderItems']);

        $items = isset($result[0]['QuantityOrdered'])
            ? $result
            : $result[0];

        $NextToken = isset($response['ListOrderItemsResult']['NextToken'])
            ? $response['ListOrderItemsResult']['NextToken']
            : null;

        return ['ListOrderItems' => $items, 'NextToken' => $NextToken];
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify, surfing along all the next tokens.
     *
     * @param string $AmazonOrderId
     *
     * @return array
     */
    public function ListOrderItemsWithAllNextTokens($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);

        $arrResponses[] = $response;

        if (isset($response['ListOrderItemsResult']['NextToken'])) {
            do {
                $query = [
                    'NextToken' => $response['ListOrderItemsResult']['NextToken']
                ];
                $response = $this->request(
                    'ListOrderItemsByNextToken',
                    $query
                );

                $arrResponses[] = $response;
            } while (isset($response['ListOrderItemsResult']['NextToken']));
        }

        $finalResponse = [];
        foreach ($arrResponses as $response) {
            $result = array_values($response['ListOrderItemsResult']['OrderItems']);

            $items = isset($result[0]['QuantityOrdered'])
                ? $result
                : $result[0];

            $finalResponse = array_merge($finalResponse, $items);
        }

        return $finalResponse;
    }

    /**
     * Returns order items based on the $NextToken.
     *
     * @param string $nextToken
     *
     * @return array
     */
    public function ListOrderItemsByNextToken($NextToken)
    {
        $items = [];

        $response = $this->request('ListOrderItemsByNextToken', [
            'NextToken' => $NextToken
        ]);

        $result = array_values($response['ListOrderItemsByNextTokenResult']['OrderItems']);

        $items = isset($result[0]['QuantityOrdered'])
            ? $result
            : $result[0];

        $NextToken = isset($response['ListOrderItemsByNextTokenResult']['NextToken'])
            ? $response['ListOrderItemsByNextTokenResult']['NextToken']
            : null;

        return ['ListOrderItems' => $items, 'NextToken' => $NextToken];
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     *
     * @param string $SellerSKU
     *
     * @return array|bool array if found, false if not found
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);

        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     *
     * @param string $ASIN
     *
     * @return array|bool array if found, false if not found
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);

        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];
        } else {
            return false;
        }
    }


    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     *
     * @param array $asin_array A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     *
     * @return array
     * @throws Exception
     */
    public function GetMatchingProductForId(array $asin_array, $type = 'ASIN')
    {
        $asin_array = array_unique($asin_array);

        if (count($asin_array) > 5) {
            throw new Exception('Maximum number of id\'s = 5');
        }

        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];

        foreach ($asin_array as $asin) {
            $array['IdList.Id.' . $counter] = $asin;
            $counter++;
        }

        $response = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        );

        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $response = $this->xmlToArray(strtr($response, $replace));

        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];
        }

        $found = [];
        $not_found = [];

        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            foreach ($response['GetMatchingProductForIdResult'] as $result) {

                //print_r($product);exit;

                $asin = $result['@attributes']['Id'];
                if ($result['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;
                } else {
                    if (isset($result['Products']['Product']['AttributeSets'])) {
                        $products[0] = $result['Products']['Product'];
                    } else {
                        $products = $result['Products']['Product'];
                    }
                    foreach ($products as $product) {
                        $array = [];
                        if (isset($product['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array["ASIN"] = $product['Identifiers']['MarketplaceASIN']['ASIN'];
                        }

                        foreach ($product['AttributeSets']['ItemAttributes'] as $key => $value) {
                            if (is_string($key) && is_string($value)) {
                                $array[$key] = $value;
                            }
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['Feature'])) {
                            $array['Feature'] = $product['AttributeSets']['ItemAttributes']['Feature'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['PackageDimensions'])) {
                            $array['PackageDimensions'] = array_map(
                                'floatval',
                                $product['AttributeSets']['ItemAttributes']['PackageDimensions']
                            );
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['ListPrice'])) {
                            $array['ListPrice'] = $product['AttributeSets']['ItemAttributes']['ListPrice'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['SmallImage'])) {
                            $image = $product['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                            $array['medium_image'] = $image;
                            $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                            $array['large_image'] = str_replace('._SL75_', '', $image);;
                        }
                        if (isset($product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array['Parentage'] = 'child';
                            $array['Relationships'] = $product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'];
                        }
                        if (isset($product['Relationships']['VariationChild'])) {
                            $array['Parentage'] = 'parent';
                        }
                        if (isset($product['SalesRankings']['SalesRank'])) {
                            $array['SalesRank'] = $product['SalesRankings']['SalesRank'];
                        }
                        $found[$asin][] = $array;
                    }
                }
            }
        }

        return [
            'found' => $found,
            'not_found' => $not_found
        ];

    }

    /**
     * Returns a list of products and their attributes, ordered by relevancy, based on a search query that you specify.
     *
     * @param string $query the open text query
     * @param string [$query_context_id = null] the identifier for the context within which the given search will be performed. see: http://docs.developer.amazonservices.com/en_US/products/Products_QueryContextIDs.html
     *
     * @return array
     * @throws Exception
     */
    public function ListMatchingProducts($query, $query_context_id = null)
    {

        if (trim($query) == "") {
            throw new Exception('Missing query');
        }

        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'Query' => urlencode($query),
            'QueryContextId' => $query_context_id
        ];

        $response = $this->request(
            'ListMatchingProducts',
            $array,
            null,
            true
        );


        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $response = $this->xmlToArray(strtr($response, $replace));

        if (isset($response['ListMatchingProductsResult'])) {
            return $response['ListMatchingProductsResult'];
        } else
            return ['ListMatchingProductsResult' => []];

    }

    /**
     * Returns a list of reports that were created in the previous 90 days.
     *
     * @param array [$ReportTypeList = []]
     *
     * @return array
     */
    public function GetReportList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;

        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }

        return $this->request('GetReportList', $array);
    }

    /**
     * Returns a list of order report requests that are scheduled to be submitted to Amazon MWS for processing.
     *
     * @param array [$ReportTypeList = []]
     *
     * @return array
     */
    public function GetReportScheduleList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;

        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }

        return $this->request('GetReportScheduleList', $array);
    }


    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace.
     *
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     *
     * @return array|bool array/false if no result
     */
    public function ListRecommendations($RecommendationCategory = null)
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;
        }

        $result = $this->request('ListRecommendations', $query);

        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;
        }

    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     *
     * @return array
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Delete product(s) based on SKU
     *
     * @param array $array array containing sku's
     *
     * @return array feed submission result
     */
    public function DeleteProductBySKU(array $array)
    {
        if (count($array) > 0) {
            $feed = [
                'MessageType' => 'Product',
                'Message' => []
            ];

            foreach ($array as $sku) {
                $feed['Message'][] = [
                    'MessageID' => rand(),
                    'OperationType' => 'Delete',
                    'Product' => [
                        'SKU' => $sku
                    ]
                ];
            }

            return $this->SubmitFeed('_POST_PRODUCT_DATA_', $feed);
        }

        return [];
    }

    /**
     * Update a product(s) stock quantity
     *
     * @param array $array array containing sku as key and quantity as value
     *
     * @return array feed submission result
     */
    public function UpdateStock(array $array)
    {
        if (count($array) > 0) {
            $feed = [
                'MessageType' => 'Inventory',
                'Message' => []
            ];

            foreach ($array as $sku => $quantity) {
                $feed['Message'][] = [
                    'MessageID' => rand(),
                    'OperationType' => 'Update',
                    'Inventory' => [
                        'SKU' => $sku,
                        'Quantity' => (int)$quantity
                    ]
                ];
            }

            return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
        }

        return [];
    }

    /**
     * Update a product's stock quantity
     *
     * @param array $array array containing arrays with next keys: [sku, quantity, latency]
     *
     * @return array feed submission result
     */
    public function UpdateStockWithFulfillmentLatency(array $array)
    {
        if (count($array) > 0) {
            $feed = [
                'MessageType' => 'Inventory',
                'Message' => []
            ];

            foreach ($array as $item) {
                $feed['Message'][] = [
                    'MessageID' => rand(),
                    'OperationType' => 'Update',
                    'Inventory' => [
                        'SKU' => $item['sku'],
                        'Quantity' => (int)$item['quantity'],
                        'FulfillmentLatency' => $item['latency']
                    ]
                ];
            }

            return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
        }

        return [];
    }

    /**
     * Update a product's price
     *
     * @param array $standardprice an array containing sku as key and price as value
     * @param array $salesprice an optional array with sku as key and value consisting of an array with key/value pairs for SalePrice, StartDate, EndDate
     * Dates in DateTime object
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     *
     * @return array feed submission result
     */
    public function UpdatePrice(array $standardprice, array $saleprice = null)
    {
        if (count($standardprice) > 0) {
            $feed = [
                'MessageType' => 'Price',
                'Message' => []
            ];

            foreach ($standardprice as $sku => $price) {
                $feed['Message'][] = [
                    'MessageID' => rand(),
                    'Price' => [
                        'SKU' => $sku,
                        'StandardPrice' => [
                            '_value' => strval($price),
                            '_attributes' => [
                                'currency' => 'DEFAULT'
                            ]
                        ]
                    ]
                ];

                if (isset($saleprice[$sku]) && is_array($saleprice[$sku])) {
                    $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                        'StartDate' => $saleprice[$sku]['StartDate']->format(self::DATE_FORMAT),
                        'EndDate' => $saleprice[$sku]['EndDate']->format(self::DATE_FORMAT),
                        'SalePrice' => [
                            '_value' => (string)$saleprice[$sku]['SalePrice'],
                            '_attributes' => [
                                'currency' => 'DEFAULT'
                            ]
                        ]
                    ];
                }
            }

            return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
        }

        return [];
    }

    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     *
     * @param object|array $MWSProduct MWSProduct
     * @param bool $includeCenterId
     *
     * @return array
     */
    public function PostProduct($MWSProduct, $includeCenterId = false)
    {
        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }

        if (count($MWSProduct) > 0) {
            $csv = Writer::createFromFileObject(new SplTempFileObject());

            $csv->setDelimiter("\t");
            $csv->setInputEncoding('iso-8859-1');

            $csv->insertOne(['TemplateType=Offer', 'Version=2014.0703']);

            $csv->insertOne(MWSProduct::$header);
            $csv->insertOne(MWSProduct::$header);

            /** @var MWSProduct $product */
            foreach ($MWSProduct as $product) {
                /* if ($includeCenterId && ($product->quantity > 0)) {
                    $product->fulfillment_center_id = $this->MarketplaceCenters[$this->config['Marketplace_Id']];
                } */

                $csv->insertOne(
                    array_values($product->toArray())
                );
            }

            return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv->__toString());
        }

        return [];
    }

    /**
     * Returns the feed processing report and the Content-MD5 header.
     *
     * @param string $FeedSubmissionId
     *
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]);

        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

    /**
     * Uploads a feed for processing by Amazon MWS.
     *
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     *
     * @return array
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false, $options = [])
    {
        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }

        if ($debug === true) {
            return $feedContent;
        } elseif ($this->debugNextFeed == true) {
            $this->debugNextFeed = false;

            return $feedContent;
        }

        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => $purgeAndReplace ? 'true' : 'false',
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
        ];

        //if ($FeedType === '_POST_PRODUCT_PRICING_DATA_') {
        $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];
        //}

        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );

        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     *
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     *
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }

    /**
     * Convert an xml string to an array
     *
     * @param string $xmlstring
     *
     * @return array
     */
    private function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     *
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param \DateTime|null [$StartDate = null]
     * @param \DateTime|null [$EndDate = null]
     *
     * @return string
     * @throws Exception
     */
    public function RequestReport($report, \DateTime $StartDate = null, \DateTime $EndDate = null)
    {
        $query = [
            'MarketplaceIdList.Id.1' => $this->config['Marketplace_Id'],
            'ReportType' => $report
        ];

        if (!is_null($StartDate)) {
            $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
        }

        if (!is_null($EndDate)) {
            $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
        }

        $result = $this->request(
            'RequestReport',
            $query
        );

        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     *
     * @param string $ReportId
     *
     * @return array|bool array on success, otherwise false
     */
    public function GetReport($ReportId)
    {
        $status = $this->GetReportRequestStatus($ReportId);

        if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
            return [];
        } else if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {

            $result = $this->request('GetReport', [
                'ReportId' => $status['GeneratedReportId']
            ]);

            if (is_string($result)) {
                $csv = Reader::createFromString($result);
                $csv->setDelimiter("\t");
                $headers = $csv->fetchOne();
                $result = [];
                foreach ($csv->setOffset(1)->fetchAll() as $row) {
                    $result[] = array_combine($headers, $row);
                }
            }

            return $result;

        } else {
            return false;
        }
    }

    /**
     * Get a report's processing status
     *
     * @param string $ReportId
     *
     * @return array|bool if the report is found, an array is returned, boolean false otherwise
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);

        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }

        return false;
    }

    /**
     * Get a list's inventory for Amazon's fulfillment
     *
     * @param array $sku_array
     *
     * @return array
     * @throws Exception
     */
    public function ListInventorySupply($sku_array = [])
    {

        if (count($sku_array) > 50) {
            throw new Exception('Maximum amount of SKU\'s for this call is 50');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSkus.member.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'ListInventorySupply',
            $query
        );

        $result = [];
        if (isset($response['ListInventorySupplyResult']['InventorySupplyList']['member'])) {
            foreach ($response['ListInventorySupplyResult']['InventorySupplyList']['member'] as $index => $ListInventorySupplyResult) {
                $result[$index] = $ListInventorySupplyResult;
            }
        }

        return $result;
    }

    /**
     * Sets the shipping status of one or multiple orders
     * References:
     * - https://stackoverflow.com/a/16842965
     * - https://github.com/meertensm/amazon-mws/issues/55#issuecomment-399400316
     *
     * @param array $orders array containing AmazonOrderID as key and array as values
     *
     * @return array feed submission result
     * @throws Exception
     */
    public function SetDeliveryStatus(array $orders)
    {
        if (count($orders) > 0) {
            $feedType = '_POST_ORDER_FULFILLMENT_DATA_';

            $feed = [
                'MessageType' => 'OrderFulfillment',
                'Message' => []
            ];

            foreach ($orders as $orderId => $data) {
                $feed['Message'][] = $this->createPostOrderFulFillmentDataMessage($orderId, $data);
            }

            return $this->SubmitFeed($feedType, $feed);
        }

        return [];
    }

    /**
     * @param array $data
     *
     * @return array
     * @throws Exception
     */
    private function createPostOrderFulFillmentDataMessage(string $orderId, array $data)
    {
        if ((!isset($data['carrierCode']) || empty($data['carrierCode'])) && (!isset($data['carrierName']) || empty($data['carrierName']))) {
            throw new \Exception('Missing required carrier data');
        }

        if (!isset($data['shippingMethod'])) {
            throw new \Exception('Missing required shipping method data');
        }

        if (!isset($data['shippingDate'])) {
            $data['shippingDate'] = gmdate(self::DATE_FORMAT);
        } else {
            if ($data['shippingDate'] instanceof \DateTimeInterface) {
                $data['shippingDate'] = gmdate(self::DATE_FORMAT, $data['shippingDate']->getTimestamp());
            } else {
                throw new \Exception('Invalid shipping date format');
            }
        }

        $fulfillmentMessage = [
            'MessageID' => rand(),
            'OrderFulfillment' => [
                'AmazonOrderID' => $orderId,
                'MerchantFulfillmentID' => $data['merchantFulfillmentId'],
                'FulfillmentDate' => $data['shippingDate']
            ]
        ];

        $fulfillmentData = [];
        $fulfillmentData['ShippingMethod'] = $data['shippingMethod'];

        if (!empty($data['trackingCode'])) {
            $fulfillmentData['ShipperTrackingNumber'] = $data['trackingCode'];
        }

        if (!empty($data['carrierCode'])) {
            $fulfillmentData['CarrierCode'] = $data['carrierCode'];
        } elseif (!empty($data['carrierName'])) {
            $fulfillmentData['CarrierName'] = $data['carrierName'];
        }

        $fulfillmentData['Item'] = [];
        foreach ($data['items'] as $item) {
            $fulfillmentData['Item'][] = [
                'MerchantOrderItemID' => $item['merchantOrderItemId'],
                'MerchantFulfillmentItemID' => $item['merchantFullfillmentItemId'],
                'Quantity' => $item['quantity']
            ];
        }

        $fulfillmentMessage['OrderFulfillment']['FulfillmentData'] = $fulfillmentData;

        return $fulfillmentMessage;
    }

    /**
     * Set the status of one or more orders
     * Reference: https://stackoverflow.com/a/37822611
     *
     * @param array $orders
     *
     * @return array
     */
    public function OrderAcknowledgement(array $orders)
    {
        if (count($orders) > 0) {
            $feedType = '_POST_ORDER_ACKNOWLEDGEMENT_DATA_';

            $feed = [
                'MessageType' => 'OrderAcknowledgement',
                'Message' => []
            ];

            foreach ($orders as $orderId => $data) {
                $feed['Message'][] = $this->createOrderAcknowledgementDataMessage($orderId, $data);
            }

            return $this->SubmitFeed($feedType, $feed);
        }

        return [];
    }

    /**
     * @param string $orderId
     * @param array $data
     *
     * @return array
     * @throws Exception
     */
    private function createOrderAcknowledgementDataMessage(string $orderId, array $data)
    {
        if (!isset($data['statusCode']) || empty($data['statusCode'])) {
            throw new \Exception('Missing required status code data');
        }

        $fulfillmentMessage = [
            'MessageID' => rand(),
            'OrderAcknowledgement' => [
                'AmazonOrderID' => $orderId,
                'MerchantOrderID' => $data['merchantOrderId'],
                'StatusCode' => $data['statusCode'],
                'Item' => []
            ]
        ];

        $fulfillmentItems = [];
        foreach ($data['items'] as $item) {
            $temp = [
                'AmazonOrderItemCode' => $item['merchantFullfillmentItemId'],
                'MerchantOrderItemID' => $item['merchantOrderItemId'],
            ];

            if ($data['statusCode'] == MWSOrder::ACK_STATUS_FAILURE) {
                $temp['CancelReason'] = $item['cancelReason'] ?? MWSOrder::CANCEL_REASON;
            }

            $fulfillmentItems[] = $temp;
        }

        $fulfillmentMessage['OrderAcknowledgement']['Item'] = $fulfillmentItems;

        return $fulfillmentMessage;
    }

    /**
     * Get eligible shipping services
     *
     * @param array $shipmentRequestDetails
     *
     * @return array
     * @throws Exception
     */
    public function GetEligibleShippingServices($shipmentRequestDetails = [])
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        $query += $shipmentRequestDetails;

        $response = $this->request(
            'GetEligibleShippingServices',
            $query
        );

        $result = [];
        if (isset($response['GetEligibleShippingServicesResult']['ShippingServiceList'])) {
            return $response['GetEligibleShippingServicesResult']['ShippingServiceList'];
        }

        return $result;
    }

    /**
     * create shipment
     *
     * @param array $shipmentRequestDetails
     *
     * @return array
     * @throws Exception
     */
    public function CreateShipment($shipmentRequestDetails = [])
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        $query += $shipmentRequestDetails;

        $response = $this->request(
            'CreateShipment',
            $query
        );

        if (isset($response['CreateShipmentResult']['Shipment'])) {
            return $response['CreateShipmentResult']['Shipment'];
        }

        return [];
    }

    /**
     * List of Financial Events.
     *
     * @param \DateTime $fromTime
     *
     * @return array
     */
    public function ListFinancialEvents(\DateTime $fromTime)
    {
        $response = $this->request('ListFinancialEvents', [
            'PostedAfter' => gmdate(self::DATE_FORMAT, $fromTime)
        ]);

        $events = isset($response['ListFinancialEventsResult']['FinancialEvents'])
            ? $response['ListFinancialEventsResult']['FinancialEvents']
            : [];

        $NextToken = isset($response['ListFinancialEventsResult']['NextToken'])
            ? $response['ListFinancialEventsResult']['NextToken']
            : null;

        return ['ListFinancialEvents' => $events, 'NextToken' => $NextToken];
    }

    /**
     * List of Financial Events, surfing along all the next tokens.
     *
     * @param \DateTime $fromTime
     *
     * @return array
     */
    public function ListFinancialEventsWithAllNextTokens(\DateTime $fromTime)
    {
        $response = $this->request('ListFinancialEvents', [
            'PostedAfter' => gmdate(self::DATE_FORMAT, $fromTime)
        ]);

        $arrResponses[] = $response;

        if (isset($response['ListFinancialEventsResult']['NextToken'])) {
            do {
                $query = [
                    'NextToken' => $response['ListFinancialEventsResult']['NextToken']
                ];
                $response = $this->request(
                    'ListFinancialEventsByNextToken',
                    $query
                );
                $arrResponses[] = $response;
            } while (isset($response['ListFinancialEventsResult']['NextToken']));
        }

        $finalResponse = [];
        foreach ($arrResponses as $response) {
            $arrEvents = [];
            if (isset($response['ListFinancialEventsResult']['FinancialEvents'])) {
                $arrEvents = $response['ListFinancialEventsResult']['FinancialEvents'];
            }
            if (isset($response['ListFinancialEventsByNextTokenResult']['FinancialEvents'])) {
                $arrEvents = $response['ListFinancialEventsByNextTokenResult']['FinancialEvents'];
            }

            $finalResponse = (count($arrEvents) > 0)
                ? ((array_keys($arrEvents) !== range(0, count($arrEvents) - 1)) ? array_merge($finalResponse, [$arrEvents]) : array_merge($finalResponse, $arrEvents))
                : array_merge($finalResponse, []);
        }

        return $finalResponse;
    }

    /**
     * List of Financial Events by Next token.
     *
     * @param $NextToken
     *
     * @return array
     */
    public function ListFinancialEventsByNextToken($NextToken)
    {
        $query = [
            'NextToken' => $NextToken
        ];
        $response = $this->request(
            'ListFinancialEventsByNextToken',
            $query
        );

        $events = isset($response['ListFinancialEventsByNextTokenResult']['FinancialEvents'])
            ? $response['ListFinancialEventsByNextTokenResult']['FinancialEvents']
            : [];

        $NextToken = isset($response['ListFinancialEventsByNextTokenResult']['NextToken'])
            ? $response['ListFinancialEventsByNextTokenResult']['NextToken']
            : null;

        return ['ListFinancialEvents' => $events, 'NextToken' => $NextToken];
    }

    /**
     * Request MWS
     */
    private function request($endPoint, array $query = [], $body = null, $raw = false)
    {
        $endPoint = MWSEndPoint::get($endPoint);

        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            //'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ];

        $query = array_merge($merge, $query);

        if (!isset($query['MarketplaceId.Id.1'])) {
            $query['MarketplaceId.Id.1'] = $this->config['Marketplace_Id'];
        }

        if (!is_null($this->config['MWSAuthToken']) and $this->config['MWSAuthToken'] != "") {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }

        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        if (isset($query['MarketplaceIdList.Id.1'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        try {
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];

            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];

                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );
            }

            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];

            ksort($query, SORT_STRING);

            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986),
                    $this->config['Secret_Access_Key'],
                    true
                )
            );

            $requestOptions['query'] = $query;

            if ($this->client === null) {
                $this->client = new Client();
            }

            $response = $this->client->{strtolower($endPoint['method'])}(
                $this->config['Region_Url'] . $endPoint['path'],
                $requestOptions
            );

            $content = (string)$response->getBody();

            if ($raw) {
                return $content;
            }

            $contentTypeHeader = is_array($response->getHeader('Content-Type'))
                ? $response->getHeader('Content-Type')[0]
                : $response->getHeader('Content-Type');

            if (strpos(strtolower($contentTypeHeader), 'xml') !== false) {
                return $this->xmlToArray($content);
            }

            return $content;
        } catch (BadResponseException $ex) {
            if ($ex->hasResponse()) {
                $message = $ex->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';
            }
            throw new \Exception($message);
        }
    }
}
