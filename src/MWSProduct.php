<?php 
namespace MCS;

class MWSProduct{

    public $sku;
    public $price;
    public $quantity = 0;
    public $product_id;
    public $product_id_type;
    public $condition_type = 'New';
    public $condition_note = '';
    public $ASIN_hint;
    public $title;
    public $product_tax_code;
    public $operation_type;
    public $sale_price;
    public $sale_start_date;
    public $sale_end_date;
    public $leadtime_to_ship;
    public $launch_date;
    public $is_giftwrap_available;
    public $is_gift_message_available;
    public $fulfillment_center_id;
    public $main_offer_image;
    public $offer_image1;
    public $offer_image2;
    public $offer_image3;
    public $offer_image4;
    public $offer_image5;
    
    private $validation_errors = [];
    
    private $conditions = [
        'New', 'Refurbished', 'UsedLikeNew', 
        'UsedVeryGood', 'UsedGood', 'UsedAcceptable'
    ];

    public static $header = [
        'sku',
        'price',
        'quantity',
        'product-id',
        'product-id-type',
        'condition-type',
        'condition-note',
        'ASIN-hint',
        'title',
        'product-tax-code',
        'operation-type',
        'sale-price',
        'sale-start-date',
        'sale-end-date',
        'leadtime-to-ship',
        'launch-date',
        'is-giftwrap-available',
        'is-gift-message-available',
        'fulfillment-center-id',
        'main-offer-image',
        'offer-image1',
        'offer-image2',
        'offer-image3',
        'offer-image4',
        'offer-image5'
    ];

    /**
     * MWSProduct constructor.
     *
     * @param array $array
     */
    public function __construct(array $array = [])
    {
        foreach ($array as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * @return array
     */
    public function getValidationErrors()
    {
        return $this->validation_errors;   
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $array = [];
        foreach (self::$header as $key) {
            $variable = str_replace('-', '_', $key);
            $val = $this->{$variable};

            $array[$key] = ($val instanceof \DateTime)
                ? $val->setTimezone(new \DateTimeZone('UTC'))->format(MWSClient::DATE_FORMAT)
                : $val
            ;
        }

        return $array;
    }

    /**
     * @return bool
     */
    public function validate()
    {
        if ((mb_strlen($this->sku) < 1) || (strlen($this->sku) > 40)) {
            $this->validation_errors['sku'] = 'Should be longer than 1 character and shorter than 40 characters';
        }
        
        $this->price = str_replace(',', '.', $this->price);
        
        $exploded_price = explode('.', $this->price);
        
        if (count($exploded_price) == 2) {
            if (mb_strlen($exploded_price[0]) > 18) { 
                $this->validation_errors['price'] = 'Too high';        
            } else if (mb_strlen($exploded_price[1]) > 2) {
                $this->validation_errors['price'] = 'Too many decimals';    
            }
        } else {
            $this->validation_errors['price'] = 'Looks wrong';        
        }
        
        $this->quantity = (int) $this->quantity;
        $this->product_id = (string) $this->product_id;
        
        $product_id_length = mb_strlen($this->product_id);
        
        switch ($this->product_id_type) {
            case 'ASIN':
                if ($product_id_length != 10) {
                    $this->validation_errors['product_id'] = 'ASIN should be 10 characters long';                
                }
                break;
            case 'UPC':
                if ($product_id_length != 12) {
                    $this->validation_errors['product_id'] = 'UPC should be 12 characters long';                
                }
                break;
            case 'EAN':
                if ($product_id_length != 13) {
                    $this->validation_errors['product_id'] = 'EAN should be 13 characters long';                
                }
                break;
            default:
               $this->validation_errors['product_id_type'] = 'Not one of: ASIN,UPC,EAN';        
        }
        
        if (!in_array($this->condition_type, $this->conditions)) {
            $this->validation_errors['condition_type'] = 'Not one of: ' . implode($this->conditions, ',');                
        }
        
        if ($this->condition_type != 'New') {
            $length = mb_strlen($this->condition_note);
            if ($length < 1) {
                $this->validation_errors['condition_note'] = 'Required if condition_type not is New';                    
            } else if ($length > 1000) {
                $this->validation_errors['condition_note'] = 'Should not exceed 1000 characters';                    
            }
        }
        
        if (count($this->validation_errors) > 0) {
            return false;    
        } else {
            return true;    
        }
    }

    /**
     * @param $property
     * @param $value
     *
     * @return mixed
     */
    public function __set($property, $value) {
        if (property_exists($this, $property)) {
            $this->{$property} = $value;

            return $this->{$property};
        }
    }    
}
