<?php
/*
Plugin Name: WooCommerce Descuentos Personalizados
Description: Aplica descuentos a clientes VIP y guarda un log en la base de datos.
Version: 0.1
Author: Alejandro Alcalá
*/
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once plugin_dir_path( __FILE__ ) . 'discount-main.php';


add_action( 'plugins_loaded', array( 'WC_Discount_Main', 'instance' ) );