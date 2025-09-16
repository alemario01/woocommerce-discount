<?php

if ( ! defined('ABSPATH') ) {
    exit;
}

class WC_Discount_Main {

    private static $instance = null;
    private $table_name;
    private $option_key = 'wc_discount_settings_options';

    public static function instance () {
        if(self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }


    public function init(){
        global $wpdb;
        $this-> table_name = $wpdb-> prefix . 'descuentos_log';

        register_activation_hook( __FILE__, array($this, 'activate' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu'), 20 );
        add_action( 'admin_init', array( $this, 'register_settings') );

        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'maybe_apply_discount' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'log_order_discount' ), 10, 1 );

        add_action( 'init', array( $this, 'set_defaults' ) );
    }

    public function activate(){
        $this->create_table();
        $this->set_defaults();
    }

    public function create_table(){
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table = $this-> table_name;

        $sql = "CREATE TABLE IF NOT EXISTS {$table}(
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NULL,
            order_id bigint(20) unsigned NULL,
            descuento_aplicado decimal(12,2) NOT NULL DEFAULT 0,
            fecha datetime NOT NULL,
            PRIMARY KEY (id),
            INDEX(user_id),
            INDEX(order_id),
            INDEX(fecha)
        ){$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

   public function set_defaults(){
    $defaults = array('enable' => 'yes', 'porcentaje' => '20', 'minimo' => '100.00' );

    $opts = get_option( $this->option_key );


    if($opts === false){
        add_option( $this->option_key, $defaults );
    }
   }


   public function register_settings(){
    register_setting( 'wc_descuento_vip_group', $this->option_key, array( $this, 'sanitize_options' ) );

    add_settings_section( 'wc_descuentos_vip_main', __( 'Configuración Descuentos VIP', 'wc-descuentos-vip' ), null, 'wc-descuentos_vip' );

    add_settings_field( 'enabled', __( 'Activar descuento', 'wc-descuentos-vip' ), array( $this, 'field_enabled' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'porcentaje', __( 'Porcentaje (%)', 'wc-descuentos-vip' ), array( $this, 'field_porcentaje' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'minimo', __( 'Monto mínimo del carrito', 'wc-descuentos-vip' ), array( $this, 'field_minimo' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );

   }


}