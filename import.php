<?php

define( 'FILE_TO_IMPORT', 'products.json' );
define( 'CATEGORIES_FILE_TO_IMPORT', 'categories.json' );

require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create the logger
$logger = new Logger('masterfishing_logger');
// Now add some handlers
$logger->pushHandler(new StreamHandler(__DIR__.'/masterfishing_app.log', Logger::DEBUG));

$db = new SQLite3('zeron.sqlite', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);

if ( ! file_exists( FILE_TO_IMPORT ) ) :
	die( 'Unable to find ' . FILE_TO_IMPORT );
endif;	

if ( ! file_exists( CATEGORIES_FILE_TO_IMPORT ) ) :
	die( 'Unable to find ' . CATEGORIES_FILE_TO_IMPORT );
endif;	

$woocommerce = new Client(
    'https://masterfishing.rightleftbrains.com',
    'ck_fbc54857d4a9908fb962968849aff21458a000eb', 
    'cs_45108c85d130a7186fe05c7c16ed41ba47c077f2',
    [
        'wp_api' => true,
        'version' => 'wc/v2',
        'timeout' => '50',
       // 'query_string_auth' => true
    ]
);

try {

	$json = parse_json( FILE_TO_IMPORT );
//	$categories_json = parse_json( CATEGORIES_FILE_TO_IMPORT );
	$categories_json = [];

	// Import Categories
	$wc_categories = $woocommerce->get('products/categories', array("per_page" => 100,));
	foreach ($categories_json as $category) :
		$i = array_search((string) $category['slug'], array_column($wc_categories, 'slug'));
		$category_data = array(
			'name' => (string) $category['name'],
			'slug' => (string) $category['slug'],
		);

		if ($category['parent'] !== null) :
			foreach ($wc_categories as $k => $v) {
				if (strpos($v->slug, (string) $category['parent'] . "-") === 0) :
					$category_data['parent'] = $v->id;
				endif;
			}
		endif;

		if ($i === false) :
			$wc_category = (array) $woocommerce->post('products/categories', $category_data);
			$wc_categories = $woocommerce->get('products/categories', array("per_page" => 100,));
			status_message('Category added. ID: ' . $wc_category['id']);
		else :
			$wc_category = (array) $woocommerce->post('products/categories/' . $wc_categories[$i]->id, $category_data);
			status_message('Category updated. ID: ' . $wc_category['id']);
		endif;
	endforeach;

	// Import Categories
	$wc_categories = $woocommerce->get('products/categories', array("per_page" => 100,));
	$categories = get_categories_from_json( $json );
	foreach ( $categories as $category => $wc_category ) :
		foreach ($wc_categories as $k => $v) {
			if (strpos($v->slug, (string) $category . "-") === 0) :
				$categories[$category] = $v->id;
			endif;
		}
	endforeach;

	// Import Attributes
	foreach ( get_attributes_from_json( $json ) as $product_attribute_name => $product_attribute ) :

		$attribute_data = array(
		    'name' => $product_attribute_name,
		    'slug' => 'pa_' . strtolower( $product_attribute_name ),
		    'type' => 'select',
		    'order_by' => 'menu_order',
		    'has_archives' => true
		);

		$wc_attribute = (array) $woocommerce->post( 'products/attributes', $attribute_data );

		if ( $wc_attribute ) :
			status_message( 'Attribute added. ID: '. $wc_attribute['id'] );

			// store attribute ID so that we can use it later for creating products and variations
			$added_attributes[$product_attribute_name]['id'] = $wc_attribute['id'];
			
			// Import: Attribute terms
			foreach ( $product_attribute['terms'] as $term ) :

				$attribute_term_data = array(
					'name' => $term
				);

				$wc_attribute_term = (array) $woocommerce->post( 'products/attributes/'. $wc_attribute['id'] .'/terms', $attribute_term_data );

				if ( $wc_attribute_term ) :
					status_message( 'Attribute term added. ID: '. $wc_attribute['id'] );

					// store attribute terms so that we can use it later for creating products
					$added_attributes[$product_attribute_name]['terms'][] = $term;
				endif;	
				
			endforeach;

		endif;		

	endforeach;


	$data = get_products_and_variations_from_json( $json, $added_attributes, $categories );

	// Merge products and product variations so that we can loop through products, then its variations
	$product_data = merge_products_and_variations( $data['products'], $data['product_variations'] );

	// Import: Products
	foreach ( $product_data as $k => $product ) :

		if ( isset( $product['variations'] ) ) :
			$_product_variations = $product['variations']; // temporary store variations array

			// Unset and make the $product data correct for importing the product.
			unset($product['variations']);
		endif;
                $query = 'SELECT "wc_last_update_attempt", "wc_updated" FROM "products" WHERE "product_id" = \'' .
                    SQLite3::escapeString($product['sku']) .
                    '\' ORDER BY "id" DESC LIMIT 1';

                $status = $db->querySingle($query, true);
                
                if (!is_null($status["wc_last_update_attempt"]) || !is_null($status["wc_updated"])) :
                    continue;
                endif;

		$wc_product = (array) $woocommerce->get('products', array("sku" => $product['sku'],));
                status_message('Product is being processed. SKU: ' . $product['sku'], $logger);
                $statement = $db->prepare('UPDATE "products" SET
                    "wc_last_update_attempt"=:wc_last_update_attempt
                    WHERE "product_id"=:pid');
                $statement->bindValue(':pid', $product['sku']);
                $statement->bindValue(':wc_last_update_attempt', date('Y-m-d H:i:s'));
                $statement->execute();

		if ($wc_product) :
			$wc_product_values = array_values($wc_product);
			$wc_product = (array) array_shift($wc_product_values);
			$wc_product = (array) $woocommerce->put('products/' . $wc_product['id'], $product);
			if ($wc_product) :
				status_message('Product updated. ID: ' . $wc_product['id'], $logger);
			endif;
		else :
			$wc_product = (array) $woocommerce->post('products', $product);

			if ($wc_product) :
				status_message('Product added. ID: ' . $wc_product['id'], $logger);
			endif;
		endif;

		if ( isset( $_product_variations ) ) :
			// Import: Product variations

			// Loop through our temporary stored product variations array and add them
			foreach ( $_product_variations as $variation ) :
				$wc_variation = (array) $woocommerce->post( 'products/'. $wc_product['id'] .'/variations', $variation );

				if ( $wc_variation ) :
					status_message( 'Product variation added. ID: '. $wc_variation['id'] . ' for product ID: ' . $wc_product['id'] );
				endif;	
			endforeach;	

			// Don't need it anymore
			unset($_product_variations);
		endif;
                $statement = $db->prepare('UPDATE "products" SET
                    "wc_last_update_attempt"=:wc_last_update_attempt,"wc_updated"=:wc_updated
                    WHERE "product_id"=:pid');
                $statement->bindValue(':pid', $product['sku']);
                $statement->bindValue(':wc_last_update_attempt', date('Y-m-d H:i:s'));
                $statement->bindValue(':wc_updated', true);
                $statement->execute();

	endforeach;
	

} catch ( HttpClientException $e ) {
    echo $e->getMessage(); // Error message
    status_message($e->getMessage(), $logger, 'ERROR');
}

/**
 * Merge products and variations together. 
 * Used to loop through products, then loop through product variations.
 *
 * @param  array $product_data
 * @param  array $product_variations_data
 * @return array
*/
function merge_products_and_variations( $product_data = array(), $product_variations_data = array() ) {
	foreach ( $product_data as $k => $product ) :
		foreach ( $product_variations_data as $k2 => $product_variation ) :
			if ( $product_variation['_parent_product_id'] == $product['_product_id'] ) :

				// Unset merge key. Don't need it anymore
				unset($product_variation['_parent_product_id']);

				$product_data[$k]['variations'][] = $product_variation;

			endif;
		endforeach;

		// Unset merge key. Don't need it anymore
		unset($product_data[$k]['_product_id']);
	endforeach;

	return $product_data;
}

/**
 * Get products from JSON and make them ready to import according WooCommerce API properties. 
 *
 * @param  array $json
 * @param  array $added_attributes
 * @return array
*/
function get_products_and_variations_from_json( $json, $added_attributes, $categories ) {

	$product = array();
	$product_variations = array();

	foreach ( $json as $key => $pre_product ) :

		if ( $pre_product['type'] == 'simple' ) :
			$product[$key]['_product_id'] = (string) $pre_product['product_id'];

			$product[$key]['name'] = (string) $pre_product['name'];
			$product[$key]['description'] = (string) $pre_product['description'];
			$product[$key]['regular_price'] = (string) $pre_product['regular_price'];
			$product[$key]['sku'] = (string) $pre_product['sku'];
			$product[$key]['categories'] = [
				[
					'id' => $categories[$pre_product['category']]
				]
			];

			// Stock
			$product[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product[$key]['in_stock'] = (bool) true;
				$product[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product[$key]['in_stock'] = (bool) false;
				$product[$key]['stock_quantity'] = (int) 0;
			endif;	

		elseif ( $pre_product['type'] == 'variable' ) :
			$product[$key]['_product_id'] = (string) $pre_product['product_id'];

			$product[$key]['type'] = 'variable';
			$product[$key]['name'] = (string) $pre_product['name'];
			$product[$key]['description'] = (string) $pre_product['description'];
			$product[$key]['regular_price'] = (string) $pre_product['regular_price'];
			$product[$key]['sku'] = (string) $pre_product['sku'];
			$product[$key]['categories'] = [
				[
					'id' => $categories[$pre_product['category']]
				]
			];

			// Stock
			$product[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product[$key]['in_stock'] = (bool) true;
				$product[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product[$key]['in_stock'] = (bool) false;
				$product[$key]['stock_quantity'] = (int) 0;
			endif;	

			$attribute_name = $pre_product['attribute_name'];

			$product[$key]['attributes'][] = array(
					'id' => (int) $added_attributes[$attribute_name]['id'],
					'name' => (string) $attribute_name,
					'position' => (int) 0,
					'visible' => true,
					'variation' => true,
					'options' => $added_attributes[$attribute_name]['terms']
			);

		elseif ( $pre_product['type'] == 'product_variation' ) :	

			$product_variations[$key]['_parent_product_id'] = (string) $pre_product['parent_product_id'];

			$product_variations[$key]['description'] = (string) $pre_product['description'];
			$product_variations[$key]['regular_price'] = (string) $pre_product['regular_price'];

			// Stock
			$product_variations[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product_variations[$key]['in_stock'] = (bool) true;
				$product_variations[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product_variations[$key]['in_stock'] = (bool) false;
				$product_variations[$key]['stock_quantity'] = (int) 0;
			endif;

			$attribute_name = $pre_product['attribute_name'];
			$attribute_value = $pre_product['attribute_value'];

			$product_variations[$key]['attributes'][] = array(
				'id' => (int) $added_attributes[$attribute_name]['id'],
				'name' => (string) $attribute_name,
				'option' => (string) $attribute_value
			);

		endif;		
	endforeach;		

	$data['products'] = $product;
	$data['product_variations'] = $product_variations;

	return $data;
}	

/**
 * Get attributes and terms from JSON.
 * Used to import product attributes.
 *
 * @param  array $json
 * @return array
*/
function get_attributes_from_json( $json ) {
	$product_attributes = array();

	foreach( $json as $key => $pre_product ) :
		if ( !empty( $pre_product['attribute_name'] ) && !empty( $pre_product['attribute_value'] ) ) :
			$product_attributes[$pre_product['attribute_name']]['terms'][] = $pre_product['attribute_value'];
		endif;
	endforeach;		

	return $product_attributes;

}	

/**
 * Get categories from JSON.
 * Used to import product categories.
 *
 * @param  array $json
 * @return array
*/
function get_categories_from_json( $json ) {
	$product_categories = array();

	foreach( $json as $key => $pre_product ) :
		if ( !empty( $pre_product['category'] ) ) :
			$product_categories[$pre_product['category']] = null;
		endif;
	endforeach;		

	return $product_categories;

}

/**
 * Parse JSON file.
 *
 * @param  string $file
 * @return array
*/
function parse_json( $file ) {
	$json = json_decode( file_get_contents( $file ), true );

	if ( is_array( $json ) && !empty( $json ) ) :
		return $json;	
	else :
		die( 'An error occurred while parsing ' . $file . ' file.' );

	endif;
}

/**
 * Print status message.
 *
 * @param  string $message
 * @param  object $logger
 * @param  string $log_level
 * @return string
 */
function status_message($message, $logger = null, $log_level = null) {
    echo $message . "\r\n";

    if (!is_null($logger)) :
        switch ($log_level) {
            case "ERROR":
                $logger->error($message);
                break;
            default:
                $logger->info($message);
        }
    endif;
}
