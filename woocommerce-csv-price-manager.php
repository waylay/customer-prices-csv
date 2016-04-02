<?php

if ( ! defined( 'ABSPATH' ) ) { 
    exit; // Exit if accessed directly
}

/*
Plugin Name: WooCommerce CSV Price Manager
Plugin URI: http://webcodesigner.com/
Description: Use CSV files to update product prices or to set custom prices for each user by using the product SKU and a user's CustomerID.
Author: Cristian Ionel
Version: 1.0
Author URI: http://webcodesigner.com/
*/

// Create the new table required when the plugin is activated
register_activation_hook( __FILE__, 'on_activation_create_custom_prices_table');


/**
 * Every single plugin action (beside the "activate" hook callback) is controlled
 * by this class. It will only instantiate if woocommerce is installed and
 * activated.
 */
class WooCommerceCSVPriceManager
{
    /**
     * Values used in the fields callbacks
     */
    private $options;

    private $table_name;
    /**
     * Start up
     */
    public function __construct()
    {
    	global $wpdb;

    	$this->table_name = get_option('custom_prices_table_name');

    	// Create the settings page and controls
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ),99);
        add_action( 'admin_init', array( $this, 'custom_prices_admin_page_init' ) , 99);

        // Show User Profile CustomerID and Clear Custom Prices
		add_action( 'edit_user_profile', array( $this, 'add_user_meta_field' ) );

		// Save User Profile CustomerID and trigger Clear Custom Prices
		add_action( 'edit_user_profile_update', array( $this, 'save_extra_user_meta_field') );

    	// Set a custom price for products based on customerid and SKU
		add_filter('woocommerce_get_price', array( $this, 'return_custom_product_price'), 2, 2);
		add_filter('woocommerce_get_regular_price', array( $this, 'return_custom_product_price'), 2, 2);
		add_filter('woocommerce_variable_price_html', array( $this, 'return_custom_variable_product_price'), 99, 2);

		// Display Price For Variable Product With Same Variations Prices
		add_filter('woocommerce_available_variation', function ($value, $object = null, $variation = null) {
		    if ($value['price_html'] == '') {
		        $value['price_html'] = '<span class="price">' . $variation->get_price_html() . '</span>';
		    }
		    return $value;
		}, 10, 3);


    }

    /**
     * Add the control page
     */
    public function add_plugin_page()
    {
        // This settings page will be under "WooCommerce"
        add_submenu_page( 
        	'woocommerce',
        	'Update products prices using CSV files',
        	'Customer Pricing',
        	'manage_woocommerce', 
        	'custom-prices-admin',
        	array( $this, 'create_custom_prices_admin_page' )
        );
    }

    /**
     * Options page callback
     */
    public function create_custom_prices_admin_page()
    {
    	$this->clear_custom_prices();

    	$csp_output = $this->handle_csp_file_upload();
    	$sp_output =  $this->handle_sp_file_upload();

    	$this->options['csp_file'] = get_option('csp_file');
    	$this->options['sp_file'] = get_option('sp_file');

        ?>
        <div class="wrap">

            <h2>Adjust Product Prices using CSV files</h2>
            
            <form name="custom_prices_form" method="post" action="" enctype="multipart/form-data" style="display:none">
            <hr>
            <h2>Clear Custom Prices</h2>
            <p>Use this button to delete all existing customer specific product prices.</p>
            <input type="hidden" name="clear_custom_prices" value="true"></input>
            <?php                
                wp_nonce_field( 'clear_custom_prices_form' );
                submit_button('Clear Custom Prices');
            ?>
            </form>


            <hr>
            <form name="custom_prices_form" method="post" action="" enctype="multipart/form-data">
            <?php
                do_settings_sections( 'custom-prices-admin' );
                wp_nonce_field( 'custom_prices_form' );
                submit_button('Update Prices'); 
            ?>

            </form>
            
            <?php
            // File upload output
            if($csp_output)
            {
            	echo "<h4> Customer Price List Upload Results:</h4><hr>";
            	echo $csp_output;
            }
            if($sp_output)
            {
            	echo "<h4> List Prices Upload Results:</h4><hr>";
            	echo $sp_output;
            }

            // File upload errors
            settings_errors();

            ?>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function custom_prices_admin_page_init()
    {        


        // Settings section for both CSV file types (Customer Price List and List Prices)
        add_settings_section(
            'custom_prices_settings_section', // ID
            'Upload your CSV files', // Title
             array( $this, 'custom_prices_settings_section_description' ), // Callback
            'custom-prices-admin' // Page
        );  
        

        // Customer Price List file
        add_settings_field(
            'csp', 
            'Upload Customer Price List', 
            array( $this, 'input_csp_file_upload' ), 
            'custom-prices-admin', 
            'custom_prices_settings_section'
        );

        // List Prices file
        add_settings_field(
            'sp', 
            'Upload List Prices', 
            array( $this, 'input_sp_file_upload' ), 
            'custom-prices-admin', 
            'custom_prices_settings_section'
        );

    }


  	// Customer Price List file input
	public function input_csp_file_upload()
	{ 
		?>
	    <input type="file" name="csp" /> 
	    <?php 
    	if(!empty($this->options['csp_file'])){
    		echo '<a href="'.$this->options['csp_file'].'" download>Download</a> the previous Customer Price List CSV file used.';
    	}else{
    		echo 'No CSV file has been uploaded yet.';
    	}
	}

	// List Prices file input
	public function input_sp_file_upload()
	{ 
		?>
	    <input type="file" name="sp" /> 
	    <?php 
    	if(!empty($this->options['sp_file'])){
    		echo '<a href="'.$this->options['sp_file'].'" download>Download</a> the previous List Prices CSV file used.';
    	}else{
    		echo 'No CSV file has been uploaded yet.';
    	}
	}
	// Content at the top of the section (between heading and fields)
	public function custom_prices_settings_section_description()
	{
		?>
		<p>Choose the correct CSV file to upload then click the button bellow to update the prices. You can upload both files at the same time.	</p>
		<?php
	}

    /**
    * Process the Customer Price List file input
    *
    * @return string Path to the Customer Price List file
	*/
	private function handle_csp_file_upload()
	{

	  	if( !empty($_FILES["csp"]["tmp_name"]) ){
		  	
		  	if( !check_admin_referer( 'custom_prices_form') ) {
		  		die('Security Check!');
		  	}

		  	if ( !$this->isCSVFileType($_FILES["csp"]["type"]) ) {
		  		return false;
		  	}

		  	
		  	$urls = wp_handle_upload($_FILES["csp"], array('test_form' => FALSE));
		    $movefile = $urls['url'];
		    

		    //Import uploaded file to Database
		    global $wpdb;
		    $entries = 0;
			$output = '';
	        $handle = fopen($movefile, "r");
		    $delimiter = $this->detectDelimiter($movefile);
		    
		    while (($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE) {

			    // Skip the first row as is likely column names
			    if ($entries === 0) {
			    	$entries++;
			        continue;
			    }

			    if ( count($data) == 3 && !is_null($data[0]) && !is_null($data[1]) && !is_null($data[2]) ) {
				    // Insert the row into the database only if we don't have any null values in the CSV

				    $new_price = wc_format_decimal( $data[2], wc_get_price_decimals() );

				    $update_row = array(
		    			'CustomerID' => $data[0],
		    			'SKU' => $data[1],
		    			'Price' => $new_price
	    			);

				    $update = $wpdb->replace($this->table_name, 
				    	$update_row, 
						array( 
			                '%d',
							'%s', 
							'%f' 
						) 
			    	);

			    	if($update){
			    		$entries++;

			    		// Show which Customer Prices were updated
			    		$output .= "<div style='background-color:#fff; padding: 6px 15px; margin: 5px 0;'>".'
			    				Customer ID: <strong> '.$update_row['CustomerID'].'</strong>, 
							    Product SKU: <strong> '.$update_row['SKU'].'</strong>, 
							    Customer Price: <strong>'.get_woocommerce_currency_symbol().$update_row['Price'].'</strong></div>';
			    	}		    	
		    	}	
		    	
			}
			// if we updated at least one row
			if($entries > 1) {
				// Show the updated message
				add_settings_error(
			        'csp_files_updated',
			        'settings_updated',
			        'Customer Price List file uploaded. <strong>'.($entries-1).'</strong> prices updated.',
			        'updated'
			    );
				

			    // Clear prduct transients
			    WC_Cache_Helper::get_transient_version( 'product', true );

			    // Set the 'previous' csp_file path
		    	update_option('csp_file', $movefile);

		    	return $output;

			} else {
				add_settings_error(
			        'csp_files_updated',
			        'settings_updated',
			        'Customer Price List file uploaded but no price was updated. Please check the file and try again',
			        'error'
			    );
			    return false;
			}
			
	  	}

		return false;
	}


    /**
    * Process the List Prices file input
    *
    * @return string Path to the List Prices file
	*/
	private function handle_sp_file_upload()
	{

	  if(!empty($_FILES["sp"]["tmp_name"]))
	  {

	  	if( !check_admin_referer( 'custom_prices_form') ){
	  		die('Security Check!');
	  	}

	  	if ( !$this->isCSVFileType($_FILES["sp"]["type"]) ) {
	  		return false;
	  	}


	  	$urls = wp_handle_upload($_FILES["sp"], array('test_form' => FALSE));
	    $movefile = $urls['url'];


	    //Import uploaded file to Database
	    global $wpdb;
	    $entries = 0;
	    $output = '';
	    $update_flag = 0;
        $handle = fopen($movefile, "r");
	    $delimiter = $this->detectDelimiter($movefile);
	    

	    while ( ($data = fgetcsv($handle, 10000, $delimiter)) !== FALSE ) {

		    // Skip the first row as is likely column names
		    if ($entries === 0) {
		        $entries++;
		        continue;
		    }
		    if ( count($data) == 2 && !is_null($data[0]) && !is_null($data[1]) ) {
		    	
		    	// Insert the row into the database
			    
			    // find the product_id using the sku from csv
				
				$sku = $data[0];
				$new_price = wc_format_decimal( $data[1], wc_get_price_decimals() );
				$product_id = $wpdb->get_var( $wpdb->prepare("
						SELECT post_id
						FROM $wpdb->postmeta
						WHERE meta_key = '_sku'
						AND meta_value = %s
					", $sku
				) );

		    	if ($product_id) {
		    		// find the current price
		    		$current_regular_price = $wpdb->get_var( $wpdb->prepare("
							SELECT meta_value
							FROM $wpdb->postmeta
							WHERE post_id = %d
							AND meta_key = '_price'
						", $product_id
					) );

		    		if(!$current_regular_price){
		    			$current_regular_price = $wpdb->get_var( $wpdb->prepare("
								SELECT meta_value
								FROM $wpdb->postmeta
								WHERE post_id = %d
								AND meta_key = '_regular_price'
							", $product_id
						) );
		    		}

		    		if( $current_regular_price !== false ){
		    			// Update the _price
		    			$meta_key = '_price';
		    			$price_updates = $wpdb->update( 
							$wpdb->postmeta, 
							array( 
								'meta_value' => $new_price	// column & new value
							), 
							array( 
								'post_id' => $product_id,          // where clause(s) match old meta_id
					        	'meta_key' => $meta_key ),  // match old prost_id (product_id)
							array( 
								'%s',	// update vals format type - set as string but it is parsed through floatval
							), 
							array( 
								'%d',    // meta_id = integer
				            	'%s'     // meta_key = string
					        ) 
						);

		    			// Update the _regular_price
		    			$meta_key = '_regular_price';
		    			$regular_price_updates = $wpdb->update( 
							$wpdb->postmeta, 
							array( 
								'meta_value' => $new_price	// column & new value
							), 
							array( 
								'post_id' => $product_id,          // where clause(s) match old meta_id
					        	'meta_key' => $meta_key ),  // match old prost_id (product_id)
							array( 
								'%s',	// update vals format type - set as string but it is parsed through floatval
							), 
							array( 
								'%d',    // meta_id = integer
				            	'%s'     // meta_key = string
					        ) 
						);

		    			$updates = $price_updates + $regular_price_updates;

		    			$output .= "<div style='background-color:#fff; padding: 6px 15px; margin: 5px 0;'>";
		    			$output .= "<strong>SKU: ".$sku."</strong><br>";

						if ($updates) {
						    $output .= "Updated $updates row(s).<br>";
						    $output .= 'Product with SKU:'.$sku.', from old price: '.get_woocommerce_currency_symbol().
						    			$current_regular_price.' to new price: '. get_woocommerce_currency_symbol().$new_price.'.';
						    $update_flag += 1;
						} elseif( false === $updates ){
						    $output .= 'Failed to update, please check the CSV file and try again.';
						} else {
							$output .= 'No update needed. Existing price is the same as the new price ('.get_woocommerce_currency_symbol().$current_regular_price.').';
						}
		    			
		    			$output .= "</div>";

		    		}else{
		    			$output .= "<div class='error'><p>No current price set for SKU: ".$sku.'.</p></div>';
		    		}
		    	}else{
		    		$output .= "<div class='error'><p>There is no product with SKU: ".$sku.'. Please check the CSV file and try again.</p></div>';
		    	} 

		    }else{
		    	$output .= "<div class='error'><p>Incorrect number of columns or CSV data on row: ".$entries.". SKU:".$data[0].", Price:".$data[1].'. Please check the CSV file and try again.</p></div>';
		    }

		    $entries++;
		    
		}

		//if we have at least one update show the top message and update the link to the last CSV file
		if($update_flag){
			add_settings_error(
		        'sp_files_updated',
		        'settings_updated',
		        $update_flag." product prices updated.",
		        'updated'
		    );
		    update_option( 'sp_file', $movefile );
		    WC_Cache_Helper::get_transient_version( 'product', true );
		}
		
		return $output;	     
	  }
	  
	  
	}

	// Triggered by the "Clear Custom Prices" button (hidden by default)

	private function clear_custom_prices()
	{
		if( !empty($_POST["clear_custom_prices"]) && $_POST["clear_custom_prices"] )
		{
			if( !check_admin_referer( 'clear_custom_prices_form') ){
				die('Security Check!');
			}

			global $wpdb;

			$result = $wpdb->query('TRUNCATE TABLE '.$this->table_name);
			if(false === $result)
			{
				add_settings_error(
			        'clear_custom_prices',
			        'settings_updated',
			        "An error occured when truncating the table, please try again",
			        'error'
			    );
			}
			else
			{
				add_settings_error(
			        'clear_custom_prices',
			        'settings_updated',
			        "Custom Prices table cleared",
			        'updated'
			    );
			}
			
		}

	}

	// Adds the CustomerID meta field and the button for resetting prices
	public function add_user_meta_field( $user )
	{
		if ( current_user_can( 'edit_user', $user->ID ) ){
	    ?>
        <h3>Custom Price Options</h3>

        <table class="form-table">
            <tr>
                <th><label for="customerid">CustomerID</label></th>
                <td>
                <input type="text" name="customerid" value="<?php echo esc_attr(get_user_meta($user->ID, 'customerid', true)); ?>" class="regular-text" />
                </td>
            </tr>
            <div style="position: absolute; left:-9999px;">
            	<?php submit_button(); // a trick to avoid clearing prices if eneter key is pressed when editing a profile ?>
            </div>
            <tr>
                <th><?php submit_button( 'Clear Custom Prices', 'delete', 'clear_customer_prices'); ?></th>
                <td>
                <p>This will delete all the custom prices added for this customer.</p>                
                </td>
            </tr>
        </table>	        
	    <?php
		}
	}

	// Save the customerid meta field. If the "Clear Custom Prices" button is pressed, clear the prices associated to this user
	public function save_extra_user_meta_field( $user_id )
	{
		if ( current_user_can( 'edit_user', $user_id ) ){
			$customerid = sanitize_text_field( $_POST['customerid'] );
		    update_user_meta( $user_id,'customerid',  $customerid);
		    
		    if( !isset( $_POST['submit'] ) && isset( $_POST['clear_customer_prices'] ) && $_POST['clear_customer_prices'] && $customerid ){
		    	// Execute SQL query to remove this customerid entries from the custom_price table
		    	global $wpdb;
		    	$wpdb->delete( $this->table_name, array( 'CustomerID' => $customerid ) );
		    }
		}
	}

	// Called each time a CSV file is updated to detect the delimiter used
	// This avoid hardcoding the delimter to ',' or ';'
	public function detectDelimiter($csvFile)
	{
	    $delimiters = array(
	        ';' => 0,
	        ',' => 0,
	        "\t" => 0,
	        "|" => 0
	    );

	    $handle = fopen($csvFile, "r");
	    $firstLine = fgets($handle);
	    fclose($handle); 
	    foreach ($delimiters as $delimiter => &$count) {
	        $count = count(str_getcsv($firstLine, $delimiter));
	    }

	    return array_search(max($delimiters), $delimiters);
	}

	// Called each time a CSV file is updated to detect the file type
	public function isCSVFileType($csvFile)
	{
	    $csv_mimetypes = array(
			    'text/csv',
			    'text/plain',
			    'application/csv',
			    'text/comma-separated-values',
			    'application/excel',
			    'application/vnd.ms-excel',
			    'application/vnd.msexcel',
			    'text/anytext',
			    'application/octet-stream',
			    'application/txt'
			);
			
	  	if ( !in_array($csvFile, $csv_mimetypes) ) {
	  		add_settings_error(
		        'sp_files_updated',
		        'settings_updated',
		        'Wrong File Type. Please upload a CSV file',
		        'error'
		    );
		    return false;
	  	}

	  	return true;
	}


	// Overrides the display price of a product if a users with a customerid is logged in
	public function return_custom_product_price($price, $product)
	{
		$user = wp_get_current_user();
		$customerid = get_user_meta($user->ID, 'customerid', true);
		$sku = $product->sku;
		
		if(!is_admin() && !empty($sku)){
		    
		    if(!is_user_logged_in() || !get_user_meta($user->ID, 'customerid', true)){
		    	return $price;
		    }
		    
	    	global $wpdb;
	    	$customer_price = $wpdb->get_var("SELECT `Price` FROM $this->table_name WHERE `CustomerID` = '$customerid' AND `SKU` = '$sku'");
	    	if(!empty($customer_price) ){
	    		$price = $customer_price;
	    	}

		}

		return $price;
	}


	// Overwrites the variation from - price display to incorporate the custom prices for users
	public function return_custom_variable_product_price($price, $product)
	{		
		$prices = array();
		$variations = $product->get_available_variations();
	    
	    foreach ( $variations as $variation ){
	        $prices[] = $variation[ 'display_price' ];
	    }

		if( count( $prices ) ){
			if(min( $prices ) < max( $prices )){
				$price = woocommerce_price(min($prices)) .' - '.woocommerce_price(max($prices));	
			}else{
				$price = woocommerce_price(min($prices));
			}
			
		}
	    return $price;
	}
}


/**
* Creates the table which holds the custom prices for each user
* This is only called once, when the plugin is activated
**/

function on_activation_create_custom_prices_table()
{
	if ( !get_option('custom_prices_table_name') ) {

		global $wpdb;
		$custom_prices_table_name = $wpdb->prefix.'woocommerce_custom_prices';

		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $custom_prices_table_name (
			ID int NOT NULL AUTO_INCREMENT,
			CustomerID mediumint(9) NOT NULL,
			SKU varchar(9) NOT NULL,
			Price decimal(8, 2) NOT NULL,
			PRIMARY KEY (`ID`),
			UNIQUE KEY (`CustomerID`, `SKU`)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		add_option( 'custom_prices_table_name', $custom_prices_table_name );
			
	}		
}

/**
 * Check if WooCommerce is active before instantiation the plugin
 **/

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
{
    $customer_prices_settings = new WooCommerceCSVPriceManager();
}
