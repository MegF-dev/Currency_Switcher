<?php
/**
 * Plugin Name: Custom Location Based Pricing for WooCommerce
 * Plugin URI: 
 * Description: Handles location-based pricing using ACF fields for WooCommerce products
 * Version: 3.0.0
 * Author: Warp Development - MF/jh/d
 * Author URI: https://www.warpdevelopment.com/
 */

if (!defined('WPINC')) {
    die;
}


function load_js() {
    wp_enqueue_script('main-js', plugins_url('/js/main.js', __FILE__), array('jquery'), '1.0', true);
}

add_action('wp_enqueue_scripts', 'load_js');
function load_plugin_styles() {
    wp_enqueue_style('plugin-styles', plugins_url('/style.css', __FILE__), array(), '1.0', 'all');
}

add_action('wp_enqueue_scripts', 'load_plugin_styles');
if(!function_exists('log_it')){
    function log_it( $message ) {
      if( WP_DEBUG === true ){
          if( is_array( $message ) || is_object( $message ) ){
              error_log( print_r( $message, true ) );
          } else {
            error_log( $message );
          }
      }
    }
 }
class Location_Based_Pricing {
    
    private static $instance = null;
    private $currencies_match = array(
        'sa_price'      => '_variation_zar_alternate_price',
        'zar_int_price' => '_variation_izar_alternate_price',
        'gbp_price'     => '_variation_gbp_alternate_price',
        'usd_price'     => '_variation_usd_alternate_price',
        'ausd_price'    => '_variation_aud_alternate_price',
        'nzd_price'     => '_variation_nzd_alternate_price',
        'euro_price'    => '_variation_eur_alternate_price',
    );
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
       

        add_filter('woocommerce_currency_symbol', [$this,'modify_admin_order_currency_display'], 10, 2);
        add_action('wp_footer',[$this, 'refresh_mini_cart']);
        add_filter('woocommerce_adjust_non_base_location_prices', '__return_false');
        
        add_filter('woocommerce_cart_item_price',[$this, 'update_mini_cart_price'], 10, 3);
        add_filter('woocommerce_widget_cart_item_quantity',[$this, 'update_mini_cart_item_total'], 10, 3);
        add_filter('woocommerce_cart_subtotal', [$this,'update_mini_cart_subtotal'], 10, 3);
        add_filter('woocommerce_cart_total',[$this, 'update_mini_cart_total'], 10);
        add_filter('woocommerce_package_rates', [$this, 'modify_shipping_rates'], 100, 2);
        add_action('woocommerce_variation_options_pricing', [$this, 'add_variation_custom_field'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_custom_field'], 10, 2);
                
        add_filter('woocommerce_product_get_price',[$this, 'custom_location_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price',[$this, 'custom_location_price'], 10, 2);
        add_filter('woocommerce_product_get_sale_price',[$this, 'custom_location_price'], 10, 2);
        add_filter('woocommerce_product_get_price_html',[$this, 'custom_location_price_html'], 10, 2);
        add_filter('woocommerce_variable_price_html',[$this, 'custom_location_price_html'], 10, 2);
        add_filter('woocommerce_currency', [$this, 'update_woocommerce_currency'], 10);
        add_action('woocommerce_before_calculate_totals', [$this,'update_cart_location_prices'], 10, 1);
        add_filter('woocommerce_currency_symbol',[$this, 'update_currency_symbol'], 10, 2);
        
        add_shortcode('location_price',[$this, 'location_based_price_shortcode']);
        add_action('init',[$this, 'clear_wc_session_data']);

        //test//
        add_filter('woocommerce_variation_prices_price', [$this, 'check_variation_price'], 10, 3);
      
        add_filter('woocommerce_product_variation_get_price', [$this, 'check_variation_price'], 10, 3);
        add_filter('woocommerce_product_variation_get_regular_price', [$this, 'check_variation_price'], 10, 3);
        add_filter('woocommerce_product_variation_get_sale_price', [$this, 'check_variation_price'], 10, 3);
		add_filter('woocommerce_is_purchasable', [$this, 'disable_add_to_cart_for_specific_products'], 10, 2);



    }

  
    private function get_min_max_at_position($array, $position) {
        $values = array_map(function($item) use ($position) {
            return isset($item[$position]) ? $item[$position] : null;
        }, $array);
        
        $values = array_filter($values, function($value) {
            return $value !== null && $value !== '';
        });
        
        if (empty($values)) {
            return ['min' => null, 'max' => null];
        }
        
        return [
            'min' => min($values),
            'max' => max($values)
        ];
    }
    public function check_variation_price($price, $variation = null, $product = null) {
        if (!$variation) {
            return $price;
        }
        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        $location_price = $this->get_location_price($price_data['field'], $variation->get_id());
        
        return !empty($location_price) ? $location_price : $price;
    }
    
    // public function modify_shipping_rates($rates, $package) {
    //     if (!is_admin()) {
    //         error_log('SHIPPING DEBUG: Starting shipping calculation...');
    //         error_log('SHIPPING DEBUG: Available rates: ' . print_r($rates, true));
            
    //         // Get cart subtotal
    //         $cart_subtotal = WC()->cart->get_subtotal();
    //         error_log('SHIPPING DEBUG: Cart subtotal: ' . $cart_subtotal);
            
    //         // Get customer's country using our existing method
    //         $customer_country = $this->get_user_country_code();
    //         error_log('SHIPPING DEBUG: Customer country: ' . $customer_country);
            
    //         // USD countries list
    //         $usd_countries = ['US', 'AK', 'AG', 'BS', 'BH', 'BB', 'BZ', 'BO', 'BR', 'CA', 'CL', 'CN', 'CO', 'CR', 'CU', 'GD', 'KW', 'MX', 'SA', 'AE'];
    
    //         foreach ($rates as $rate_key => $rate) {
    //             if ($customer_country === 'ZA') {
    //                 // South Africa Rules
    //                 error_log('SHIPPING DEBUG: Customer is in South Africa');
    
    //                 if ($cart_subtotal >= 1499 && $rate->method_id === 'free_shipping') {
    //                     error_log('SHIPPING DEBUG: Cart total >= 1499, keeping free shipping');
    //                     // Keep free shipping rate only
    //                     unset($rates['flat_rate']);
    //                 } elseif ($cart_subtotal < 1499 && $rate->method_id === 'flat_rate') {
    //                     // Adjust flat rate if it exists
    //                     error_log('SHIPPING DEBUG: Cart total < 1499, setting flat rate to R250');
    //                     $rate->cost = 250;
    //                 } else {
    //                     unset($rates[$rate_key]); // Remove other rates
    //                 }
    //             } elseif (in_array($customer_country, $usd_countries)) {
    //                 // USD Countries Rules
    //                 error_log('SHIPPING DEBUG: Customer is in USD zone');
    
    //                 if ($cart_subtotal >= 499 && $rate->method_id === 'free_shipping') {
    //                     error_log('SHIPPING DEBUG: Cart total >= $499, keeping free shipping');
    //                     unset($rates['flat_rate']);
    //                 } elseif ($cart_subtotal < 499 && $rate->method_id === 'flat_rate') {
    //                     // Adjust flat rate if it exists
    //                     error_log('SHIPPING DEBUG: Cart total < $499, setting flat rate to $100');
    //                     $rate->cost = 100;
    //                 } else {
    //                     unset($rates[$rate_key]); // Remove other rates
    //                 }
    //             } else {
    //                 unset($rates[$rate_key]); // Remove rates for unsupported countries
    //             }
    //         }
    
    //         error_log('SHIPPING DEBUG: Final rates: ' . print_r($rates, true));
    //     }
        
    //     return $rates;
    // }
    public function modify_shipping_rates($rates, $package) {
        if (is_admin()) {
            return $rates;
        }
    
        error_log('SHIPPING DEBUG: Starting shipping calculation...');
        error_log('SHIPPING DEBUG: Initial rates: ' . print_r($rates, true));
    
        $cart_subtotal = WC()->cart->get_subtotal() + WC()->cart->get_subtotal_tax();
        $customer_country = $this->get_user_country_code();
        $modified_rates = array();
    
        error_log('SHIPPING DEBUG: Cart subtotal: ' . $cart_subtotal);
        error_log('SHIPPING DEBUG: Customer country: ' . $customer_country);
    
        // Country lists
        $usd_countries = ['US', 'AK', 'AG', 'BS', 'BH', 'BB', 'BZ', 'BO', 'BR', 'CA', 'CL', 'CN', 'CO', 'CR', 'CU', 'GD', 'KW', 'MX', 'SA', 'AE'];
        $euro_countries = ['AL', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'GE', 'DE', 'GR', 'HU', 'IS', 'IE', 'IL', 'IT', 'LV', 'LB', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'NO', 'PL', 'PT', 'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'TN', 'TR', 'UA', 'VA'];
        $african_international_countries = ['ZW', 'ZM', 'MZ', 'BW', 'LS', 'SZ', 'NA'];
    
        if ($customer_country === 'ZA') {
            error_log('SHIPPING DEBUG: Processing South Africa shipping');
            error_log('SHIPPING DEBUG: Cart subtotal before conversion: ' . $cart_subtotal);
            
            // Get price data to ensure correct currency
            $price_data = $this->get_price_field_and_currency($customer_country);
            error_log('SHIPPING DEBUG: Price data: ' . print_r($price_data, true));
            
            // Convert subtotal if needed
            $converted_subtotal = $cart_subtotal;
            error_log('SHIPPING DEBUG: Converted subtotal: ' . $converted_subtotal);
            error_log('SHIPPING DEBUG: Checking if ' . $converted_subtotal . ' >= 1499');
            
            if ($converted_subtotal >= 1499) {
                error_log('SHIPPING DEBUG: Cart total is over threshold, creating free shipping');
                
                // Create a new free shipping rate
                $free_shipping = new WC_Shipping_Rate(
                    'free_shipping:1',
                    'Free Shipping',
                    0,
                    array(),
                    'free_shipping'
                );
                $modified_rates['free_shipping:1'] = $free_shipping;
                error_log('SHIPPING DEBUG: Created free shipping rate');
                
            } else {
                error_log('SHIPPING DEBUG: Cart total is under threshold, applying flat rate');
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 250;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping R250');
                    }
                }
            }
            
            error_log('SHIPPING DEBUG: Final modified rates for SA: ' . print_r($modified_rates, true));
        }
        elseif (in_array($customer_country, $african_international_countries)) {
            error_log('SHIPPING DEBUG: Processing African International shipping');
            
            // Get price data to ensure correct currency
            $price_data = $this->get_price_field_and_currency($customer_country);
            $converted_subtotal = $cart_subtotal;
            error_log('SHIPPING DEBUG: Converted subtotal for African Int: ' . $converted_subtotal);
            
            if ($converted_subtotal >= 8999) {
                error_log('SHIPPING DEBUG: Cart total is over threshold, creating free shipping for African Int');
                
                // Create a new free shipping rate
                $free_shipping = new WC_Shipping_Rate(
                    'free_shipping:1',
                    'Free Shipping',
                    0,
                    array(),
                    'free_shipping'
                );
                $modified_rates['free_shipping:1'] = $free_shipping;
                error_log('SHIPPING DEBUG: Created free shipping rate for African Int');
            } else {
                error_log('SHIPPING DEBUG: Cart total is under threshold, applying flat rate for African Int');
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 1800;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping R1800');
                    }
                }
            }
            
            error_log('SHIPPING DEBUG: Final modified rates for African Int: ' . print_r($modified_rates, true));
        }
        elseif (in_array($customer_country, $usd_countries)) {
            error_log('SHIPPING DEBUG: Processing USD Countries shipping');
            
            if ($cart_subtotal >= 499) {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'free_shipping') {
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added free shipping rate');
                        break;
                    }
                }
            } else {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 100;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping ($100)');
                        break;
                    }
                }
            }
        }
        elseif (in_array($customer_country, $euro_countries)) {
            error_log('SHIPPING DEBUG: Processing European shipping');
            
            if ($cart_subtotal >= 456) {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'free_shipping') {
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added free shipping rate');
                        break;
                    }
                }
            } else {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 91;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping (€91)');
                        break;
                    }
                }
            }
        }
        elseif ($customer_country === 'GB') {
            error_log('SHIPPING DEBUG: Processing UK shipping');
            
            if ($cart_subtotal >= 394) {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'free_shipping') {
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added free shipping rate');
                        break;
                    }
                }
            } else {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 79;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping (£79)');
                        break;
                    }
                }
            }
        }
        elseif ($customer_country === 'AU') {
            error_log('SHIPPING DEBUG: Processing Australian shipping');
            
            if ($cart_subtotal >= 762) {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'free_shipping') {
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added free shipping rate');
                        break;
                    }
                }
            } else {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 152;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping (A$152)');
                        break;
                    }
                }
            }
        }
        elseif ($customer_country === 'NZ') {
            error_log('SHIPPING DEBUG: Processing New Zealand shipping');
            
            if ($cart_subtotal >= 824) {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'free_shipping') {
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added free shipping rate');
                        break;
                    }
                }
            } else {
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 165;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping (NZ$165)');
                        break;
                    }
                }
            }
        }
        else {
            // Rest of World - using African International rates
            error_log('SHIPPING DEBUG: Processing Rest of World shipping');
            error_log('SHIPPING DEBUG: Cart subtotal for ROW: ' . $cart_subtotal);
            
            if ($cart_subtotal >= 8999) {
                error_log('SHIPPING DEBUG: Cart total is over threshold, creating free shipping for ROW');
                
                // Create a new free shipping rate
                $free_shipping = new WC_Shipping_Rate(
                    'free_shipping:1',
                    'Free Shipping',
                    0,
                    array(),
                    'free_shipping'
                );
                $modified_rates['free_shipping:1'] = $free_shipping;
                error_log('SHIPPING DEBUG: Created free shipping rate for ROW');
            } else {
                error_log('SHIPPING DEBUG: Cart total is under threshold, applying flat rate for ROW');
                foreach ($rates as $rate) {
                    if ($rate->method_id === 'flat_rate') {
                        $rate->cost = 1800;
                        $modified_rates[$rate->id] = $rate;
                        error_log('SHIPPING DEBUG: Added flat rate shipping R1800 for ROW');
                    }
                }
            }
        }
    
        error_log('SHIPPING DEBUG: Final modified rates: ' . print_r($modified_rates, true));
        return !empty($modified_rates) ? $modified_rates : $rates;
    }
    
    public function get_location_price($field, $product_id, $return_range = false) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
    
        // For variable products when we need a range
        if ($product->is_type('variable') && $return_range) {
           
            
            $variations = $product->get_available_variations();
          
            
            $prices = [];
            
            foreach ($variations as $variation) {
                $variation_id = $variation['variation_id'];
                $meta_key = $this->currencies_match[$field];
                
                // Debug each variation
            
                
                // Get both potential prices
                $variation_price = get_post_meta($variation_id, $this->currencies_match[$field], true);
                $acf_price = get_field($field, $variation_id);
				
                if(!$variation_price){
					return false;
				}
                
                // Use variation price if exists, otherwise ACF, otherwise fallback to display price
                if (!empty($variation_price)) {
                  
                    $prices[] = $variation_price;
                } elseif (!empty($acf_price)) {
                   
                    $prices[] = $acf_price;
                } else {
                    
                    $prices[] = $variation['display_price'];
                }
            }
            
            $result = [
                'min' => !empty($prices) ? min($prices) : null,
                'max' => !empty($prices) ? max($prices) : null
            ];
         
            return $result;
        }
        
        // For all other cases (simple products, single variations, or variable products without range)
        $variation_price = get_post_meta($product_id, $this->currencies_match[$field], true);
        $acf_price = get_field($field, $product_id);

		if(empty($acf_price)  ){
			if (!empty($variation_price)){
				$final_price = $variation_price;
			}else{
			return false;
			}
		}
    
        $final_price = !empty($variation_price) ? $variation_price : 
                       (!empty($acf_price) ? $acf_price : $product->get_price());
        
        return $final_price;
    }
    public function custom_location_price_html($price_html, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }

        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        
     // Check if price data exists and is valid
    if (empty($price_data) || !isset($price_data['field']) || empty($price_data['field'])) {
        return $price_html;
    }
       

        //check dis
        $location_price = $this->get_location_price($price_data['field'], $product->get_id(), 
        $product->is_type('variable'));
		$not_available = "<span class='not-available-in-country'>Not Available in your Country</span>";
		if(!$location_price && $price_data['field'] !=="sa_price" ){
			return $not_available;
		}
			
        if ($product->is_type('variable') && is_array($location_price)) {
            if ($location_price['min'] !== null && $location_price['max'] !== null) {
                if ($location_price['min'] === $location_price['max']) {
                    return wc_price($location_price['min']);
                }
                return wc_price($location_price['min']) . ' - ' . wc_price($location_price['max']);
            }
        } elseif (!empty($location_price)) {
            return wc_price($location_price);
        }

        return $price_html;
    }

    public function get_user_country_code() {
       
        if (class_exists('WC_Geolocation')) {
            $wc_geo = new WC_Geolocation();
            $geo_ip = $wc_geo->geolocate_ip();
            if (!empty($geo_ip['country'])) {
                return $geo_ip['country'];
            }
        }
        
       
        if (function_exists('WC')) {
            $customer = WC()->customer;
            if ($customer && $customer->get_shipping_country()) {
                return $customer->get_shipping_country();
            }
        }
        
    
        $ip_address = WC_Geolocation::get_ip_address(); 
        $geo_response = wp_remote_get("http://ip-api.com/json/{$ip_address}");
        
        if (!is_wp_error($geo_response)) {
            $geo_data = json_decode(wp_remote_retrieve_body($geo_response));
            if ($geo_data && isset($geo_data->countryCode)) {
                return $geo_data->countryCode;
            }
        }
        
        return false;
    }
    
    public function get_price_field_and_currency($country_code) {
       
        $usd_countries = ['US', 'AK', 'AG', 'BS', 'BH', 'BB', 'BZ', 'BO', 'BR', 'CA', 'CL', 'CN', 'CO', 'CR', 'CU', 'GD', 'KW', 'MX', 'SA', 'AE'];
        
        $euro_countries = ['AL', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'GE', 'DE', 'GR', 'HU', 'IS', 'IE', 'IL', 'IT', 'LV', 'LB', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'NO', 'PL', 'PT', 'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'TN', 'TR', 'UA', 'VA'];
    
       
        $southern_african_countries = ['ZA', 'ZW', 'ZM', 'MZ', 'BW', 'LS', 'SZ', 'NA'];
    
       
        $country_code = strtoupper($country_code);
        
       
        if (in_array($country_code, $southern_african_countries)) {
            return ['field' => 'sa_price', 'currency' => 'R', 'currency_code' => 'ZAR'];
        } elseif (in_array($country_code, $usd_countries)) {
            return ['field' => 'usd_price', 'currency' => '$', 'currency_code' => 'USD'];
        } elseif (in_array($country_code, $euro_countries)) {
            return ['field' => 'euro_price', 'currency' => '€', 'currency_code' => 'EUR'];
        } elseif ($country_code === 'GB') {
            return ['field' => 'gbp_price', 'currency' => '£', 'currency_code' => 'GBP'];
        } elseif ($country_code === 'AU') {
            return ['field' => 'ausd_price', 'currency' => '$', 'currency_code' => 'AUD'];
        } elseif ($country_code === 'NZ') {
            return ['field' => 'nzd_price', 'currency' => '$', 'currency_code' => 'NZD'];
        } else {
            return ['field' => 'zar_int_price', 'currency' => 'R', 'currency_code' => 'ZAR'];
        }
    }
  
    public function location_based_price_shortcode($atts = []) {
        $post_id = get_the_ID();
        if (!$post_id) {
            return 'Price not available';
        }
        
        $country_code = $this->get_user_country_code();
        $price_data = $this->get_price_field_and_currency($country_code);
        
        $product = wc_get_product($post_id);
        if (!$product) {
            return 'Price not available';
        }
        
        // Get price with support for both simple and variable products
        $location_price = $this->get_location_price($price_data['field'], $post_id, $product->is_type('variable'));
        
        if (!$location_price) {
            return 'Price not available';
        }
        
        // Handle variable products with price ranges
        if (is_array($location_price)) {
            if ($location_price['min'] === $location_price['max']) {
                return $price_data['currency'] . number_format((float)$location_price['min'], 2, '.', ',');
            }
            return $price_data['currency'] . number_format((float)$location_price['min'], 2, '.', ',') . 
                   ' - ' . 
                   $price_data['currency'] . number_format((float)$location_price['max'], 2, '.', ',');
        }
        
        // Handle simple products and variations
        return $price_data['currency'] . number_format((float)$location_price, 2, '.', ',');
    }
  
    public function clear_wc_session_data() {
        if (isset(WC()->session)) {
            WC()->session->set('customer_geo_ip', null);
        }
    }
    
    public function custom_location_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
    //check dis
        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        $location_price = $this->get_location_price($price_data['field'], $product->get_id());
    
        if (!empty($location_price)) {
            // Don't modify price ranges here - they're handled in price_html
            if (!is_array($location_price)) {
                $product->set_tax_status('taxable');
                $product->set_price($location_price);
                return $location_price;
            }
        }
        
        return $price;
    }
    
    // public function custom_location_price_html($price_html, $product) {
     
    //     if (is_admin() && !wp_doing_ajax()) {
    //         return $price_html;
    //     }
    //     $product  = wc_get_product($product);

        

    //     $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
    //     $location_price = $this->get_location_price($price_data['field'], $product->get_id(), true);
    //     //$pos = array_search($price_data['field'], array_keys($currencies_match));
        
        
    //     if ($product->is_type('variation')) { // get single price of variation
    //         $formatted_price = $price_data['currency'] . number_format((float)$location_price, 2, '.', ',');
           
    //         return $formatted_price;
    //         //return wc_price($location_price);
    //     }
    //     if ($product->is_type('variable')) { // get range of prices for the product, this is used on the archive view

    //         //$values = $this->get_min_max_at_position($location_price, $pos);
    //         if (is_array($location_price) )
    //         {
    //             return wc_price($location_price['min']) . ' - ' . wc_price($location_price['max']);
    //         } else {
    //             return wc_price($location_price);
    //         }
    
    //     }

    //     if ($product->is_type('simple')) {
    //         return wc_price($location_price);
    //     }
    
    //     // log_it('price in custom_location_price_html: -------------------');
    //     // log_it($location_price);

    //     // get_field($price_data['field'], $product->get_id()); // this was the initial value
        
    //     if (!empty($location_price)) {
    //         if (wc_tax_enabled() && wc_prices_include_tax()) {
    //             $location_price = $location_price;
    //         }
    //         return wc_price($location_price);
    //     }
    
    //     return $price_html ;
    // }
    // public function get_location_price($location,$id, $return_arr = false) {
    //     $is_variable = false; // flag to check if we need to return a range or a single value;
    //     $currencies_match = array(
    //         'sa_price'      => '_variation_zar_alternate_price',
    //         'zar_int_price' => '_variation_izar_alternate_price',
    //         'gbp_price'     => '_variation_gbp_alternate_price',
    //         'usd_price'     => '_variation_usd_alternate_price',
    //         'ausd_price'    => '_variation_aud_alternate_price',
    //         'nzd_price'     => '_variation_nzd_alternate_price',
    //         'euro_price'    => '_variation_eur_alternate_price',
    //     );//CLAUDE CHANGE 

    //     $product = wc_get_product($id);
        
    //     $product_prices = array();
    //     if (!$product) {
    //         return false;
    //     }
    //     if ($product->is_type('variation'))
    //     {
    //         // product makes use of variations, so use the values of the array to get meta instead of acf values
    //         $currencies = array_values($currencies_match); 
    //         $variable_product = wc_get_product($id);
    //         for ($j = 0; $j <= count ($currencies)-1 ; $j++ ) {
    //             $meta_price = get_post_meta($id, $currencies[$j], true);            
    //             $normal_price = $variable_product->get_price();
    //             $meta_price !== "" ? $product_prices[$id][] = $meta_price : $product_prices[$id][] = $normal_price;
    //         }
            
    //     }
    //     else if ($product->is_type('simple')) {

    //         // product is simple, so we need to use the keys of the array to get the acf values instead of the meta
    //         $currencies = array_keys($currencies_match); 
                     
    //         for ($i = 0; $i <= count($currencies) -1 ; $i++) {
    //             $product_prices[$id][] = get_field($currencies[$i], $id);
    //         }
    //     } else {
    //         $is_variable = true;
    //         $currencies = array_values($currencies_match);
    //         log_it("currency keys are : ");
    //         log_it($currencies);
     
    //         //$product_price = $product->get_price(); // initial value
    //         $variation_ids = $product->get_children();

    //         for ($i=0; $i <= count($variation_ids)-1; $i++) { // loop through the variations
    //             $variation_product = wc_get_product($variation_ids[$i]);
    //             for ($j = 0; $j <= count($currencies_match)-1; $j++ ) {
    //                  log_it("Getting meta {$currencies[$j]} for product id: {$variation_ids[$i]}");
                    

    //                 $meta_price = get_post_meta($variation_ids[$i], $currencies[$j], true);
    //                 $normal_price = $variation_product->get_price();
                    
    //                 $meta_price !== "" ? $product_prices[$variation_ids[$i]][] = $meta_price : $product_prices[$variation_ids[$i]][] = $normal_price;
    //             }
    //         }
    //     }
    //     // log_it("location and product is ----------");
    //     // log_it($location. ' | ' . $id);
    //     // log_it('----------------------------------');

    //     // log_it("prices array is ------------------");
    //     // log_it($product_prices);
    //     // log_it('----------------------------------');

    //     $pos = array_search($location, array_keys($currencies_match));
    //     // log_it("pos is ---------------------------");
    //     // log_it($pos);
    //     // log_it('----------------------------------');
    //     if (!$pos) {
    //        // location isn't found in the price matrix, return the first item
    //        if ($currencies[0] == "" || !$currencies[0]) {
    //         if ($is_variable ) {
    //             $price = $this->get_min_max_at_position($product_prices, 0);
    //         }
    //        }
    //        else {
    //         $price = $product_prices[$id][0];
    //        }
    //     }

    //     // Determine single value or range
    //     if ($currencies[$pos] == "" || !$currencies[$pos]) {
    //        $price = 0; 
    //        if ($is_variable) {
    //            $price = $this->get_min_max_at_position($product_prices, $pos);
    //        }

    //     } else {
    //         $price = $product_prices[$id][$pos]; 
    //         if ($is_variable && $return_arr) {
    //             $price = $this->get_min_max_at_position($product_prices, $pos); // this is the problematic one
    //        }
           
    //     }

    //     // log_it('price is set to ------------------');
    //     // log_it($price);
    //     // log_it('----------------------------------');

    //     return $price;
    // }

    public function update_cart_location_prices($cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
    
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
    
        foreach ($cart->get_cart() as $cart_item) {
            $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
            if ($cart_item['variation_id'] !== "" || $cart_item['variation_id'] !== null ) {
                $id = $cart_item['variation_id'];
            }
            else {
                $id = $cart_item['product_id'];
            }
            $location_price = $this->get_location_price($price_data['field'],$id);
          
            
            if (!empty($location_price)) {
                $cart_item['data']->set_price($location_price);
                $cart_item['data']->set_tax_status('taxable');
                if (wc_prices_include_tax()) {
                    $cart_item['data']->set_tax_class('');
                }
            }
        }
    }
    
    public function update_currency_symbol($currency_symbol, $currency) {
        if (is_admin() && !wp_doing_ajax()) {
            return $currency_symbol;
        }
    
        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        return $price_data['currency'];
    }
    
    public function update_mini_cart_price($price, $cart_item, $cart_item_key) {
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }
        
        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
        $location_price = $this->get_location_price($price_data['field'], $product_id);
        
        if (!empty($location_price)) {
            return wc_price($location_price);
        }
        
        return $price;
    }

    
    public function update_mini_cart_item_total($html, $cart_item, $cart_item_key) {
        $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
        $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
        $location_price = $this->get_location_price($price_data['field'], $product_id, false);
        
        if (!empty($location_price)) {
            $price = $location_price * $cart_item['quantity'];
            return sprintf('<span class="quantity">%s &times; %s</span>', $cart_item['quantity'], wc_price($location_price));
        }
        
        return '<p class="from-text">From</p>'. $html;
    }
    
    public function update_mini_cart_subtotal($cart_subtotal, $compound, $cart) {
        if (is_admin() && !wp_doing_ajax()) {
            return $cart_subtotal;
        }
        
        $total = 0;
        foreach ($cart->get_cart() as $cart_item) {
            $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $location_price = $this->get_location_price($price_data['field'], $product_id, false);
            
            if (!empty($location_price)) {
                $total += (float)$location_price * $cart_item['quantity'];
            }
        }
        
        return wc_price($total);
    }
    
    public function update_mini_cart_total($cart_total) {
        if (is_admin() && !wp_doing_ajax()) {
            return $cart_total;
        }
        
        $total = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
            $product_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : $cart_item['product_id'];
            $location_price = $this->get_location_price($price_data['field'], $product_id, false);
            
            if (!empty($location_price)) {
                $total += (float)$location_price * $cart_item['quantity'];
            }
        }
    
        // Add shipping total to the calculated product total
        $shipping_total = WC()->cart->get_shipping_total();
        $total += (float)$shipping_total;
        
        return wc_price($total);
    }
    
    public function refresh_mini_cart() {
        if (is_admin()) return;
        ?>
        <script type="text/javascript">
        jQuery(function($){
            $(document.body).trigger('wc_fragment_refresh');
        });
        </script>
        <?php
    }

    
    public function add_variation_custom_field($loop, $variation_data, $variation) {
        echo "<br/>";
        woocommerce_wp_text_input(
            array(
                'id' => '_variation_zar_alternate_price',
                'label' => __('ZAR Price', 'woocommerce'),
                'placeholder' => '',
               // 'description' => __('Enter the alternate price for this variation.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_zar_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-first',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_variation_izar_alternate_price',
                'label' => __('ZAR INT Price', 'woocommerce'),
                'placeholder' => '',
                //'description' => __('Enter the alternate price for this variation in international ZAR.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_izar_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-last',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_variation_gbp_alternate_price',
                'label' => __('GBP Price', 'woocommerce'),
                'placeholder' => '',
                //'description' => __('Enter the alternate price for this variation in GBP.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_gbp_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-first',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_variation_usd_alternate_price',
                'label' => __('USD Price', 'woocommerce'),
                'placeholder' => '',
               // 'description' => __('Enter the alternate price for this variation in USD.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_usd_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-last',
            )
        );
        woocommerce_wp_text_input(
            array(
                'id' => '_variation_aud_alternate_price',
                'label' => __('AUD Price', 'woocommerce'),
                'placeholder' => '',
               // 'description' => __('Enter the alternate price for this variation in AUD.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_aud_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-first',
            )
        );

        woocommerce_wp_text_input(
            array(
                'id' => '_variation_nzd_alternate_price',
                'label' => __('NZD Price', 'woocommerce'),
                'placeholder' => '',
                // 'description' => __('Enter the alternate price for this variation in NZD.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_nzd_alternate_price', true), // Existing value if any
                'wrapper_class' => 'form-field form-row form-row-last',
            )
        );
  
       echo "<br style='clear:both'/>";
        woocommerce_wp_text_input(
            array(
                'id' => '_variation_eur_alternate_price',
                'label' => __('EUR Price  ', 'woocommerce'),
                'placeholder' => '',
                // 'description' => __('Enter the alternate price for this variation in EUR.', 'woocommerce'),
                'type' => 'text',
                'value' => get_post_meta($variation->ID, '_variation_eur_alternate_price', true), // Existing value if any
                
            )
        );



    }

   // Save custom field value for variations
   public function save_variation_custom_field($variation_id, $i) {
   
    $variation_zar_alternate_price = $_POST['_variation_zar_alternate_price'];
    if (!empty($variation_zar_alternate_price)) {
        update_post_meta($variation_id, '_variation_zar_alternate_price', sanitize_text_field($variation_zar_alternate_price));
    }

    $variation_izar_alternate_price = $_POST['_variation_izar_alternate_price'];
    if (!empty($variation_izar_alternate_price)) {
        update_post_meta($variation_id, '_variation_izar_alternate_price', sanitize_text_field($variation_izar_alternate_price));
    }

    $variation_gbp_alternate_price = $_POST['_variation_gbp_alternate_price'];
    if (!empty($variation_gbp_alternate_price)) {
        update_post_meta($variation_id, '_variation_gbp_alternate_price', sanitize_text_field($variation_gbp_alternate_price));
    }

    $variation_usd_alternate_price = $_POST['_variation_usd_alternate_price'];
    if (!empty($variation_usd_alternate_price)) {
        update_post_meta($variation_id, '_variation_usd_alternate_price', sanitize_text_field($variation_usd_alternate_price));
    }

    $variation_aud_alternate_price = $_POST['_variation_aud_alternate_price'];
    if (!empty($variation_aud_alternate_price)) {
        update_post_meta($variation_id, '_variation_aud_alternate_price', sanitize_text_field($variation_aud_alternate_price));
    }

    $variation_nzd_alternate_price = $_POST['_variation_nzd_alternate_price'];
    if (!empty($variation_nzd_alternate_price)) {
        update_post_meta($variation_id, '_variation_nzd_alternate_price', sanitize_text_field($variation_nzd_alternate_price));
    }

    $variation_eur_alternate_price = $_POST['_variation_eur_alternate_price'];
    if (!empty($variation_eur_alternate_price)) {
        update_post_meta($variation_id, '_variation_eur_alternate_price', sanitize_text_field($variation_eur_alternate_price));
    }


}

   
public function modify_admin_order_currency_display($currency_symbol, $currency) {
    if (!is_admin()) {
        return $currency_symbol;
    }

    global $post;
    if ($post && 'shop_order' === $post->post_type) {
        $order = wc_get_order($post->ID);
        if ($order) {
            $customer_country = $order->get_billing_country();
            $price_data = $this->get_price_field_and_currency($customer_country);
            return $price_data['currency'];
        }
    }

    return $currency_symbol;
}
	
public function disable_add_to_cart_for_specific_products($is_purchasable, $product) {
	
	$price_data = $this->get_price_field_and_currency($this->get_user_country_code());
    $location_price = $this->get_location_price($price_data['field'], $product->get_id(), 
    $product->is_type('variable'));
	
	if(!$location_price && $price_data['field'] !=="sa_price" ){
		return false;
	}
	return $is_purchasable;
}
	
public function update_woocommerce_currency($currency) {
    if (is_admin() && !wp_doing_ajax()) {
        return $currency;
    }
    
    $price_data = $this->get_price_field_and_currency($this->get_user_country_code());
    return $price_data['currency_code'];
}
}


add_action('plugins_loaded', function() {
    Location_Based_Pricing::get_instance();
});
