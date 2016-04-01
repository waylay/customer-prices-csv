<?php
    if( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit();
    global $wpdb;
    
	if ( get_option('custom_prices_table_name') )  
	{
		global $wpdb;
		$custom_prices_table_name = get_option('custom_prices_table_name');
		$sql = "DROP TABLE IF EXISTS $custom_prices_table_name";
		$result = $wpdb->query($sql);
		
		delete_option('custom_prices_table_name');    	
	}		

?>