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
    register_setting( 'wc_descuentos_vip_group', $this->option_key, array( $this, 'sanitize_options' ) );

    add_settings_section( 'wc_descuentos_vip_main', __( 'Configuración Descuentos VIP', 'wc-descuentos-vip' ), null, 'wc-descuentos_vip' );

    add_settings_field( 'enabled', __( 'Activar descuento', 'wc-descuentos-vip' ), array( $this, 'field_enabled' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'porcentaje', __( 'Porcentaje (%)', 'wc-descuentos-vip' ), array( $this, 'field_porcentaje' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'minimo', __( 'Monto mínimo del carrito', 'wc-descuentos-vip' ), array( $this, 'field_minimo' ), 'wc_descuentos_vip', 'wc_descuentos_vip_main' );

   }

   public function sanitize_options( $input ) {
    $out = array();
    $out['enabled'] = ( isset( $input['enabled'] ) && $input['enabled'] === 'yes' ) ? 'yes' : 'no';
    $out['porcentaje'] = isset( $input['porcentaje'] ) ? floatval( $input['porcentaje'] ) : 0;
    $out['minimo'] = isset( $input['minimo'] ) ? number_format( floatval( $input['minimo'] ), 2, '.', '' ) : '0.00';
    return $out;

   }


   public function field_enabled() {
    $opts = get_option( $this->option_key );
    $val = ( isset( $opts['enabled'] ) && $opts['enabled'] === 'yes' ) ? 'yes' : 'no';
    ?>
    <label><input type="checkbox" name="<?php echo esc_attr( $this->option_key ); ?>[enabled]" value="yes" <?php checked( $val, 'yes' ); ?> /> <?php _e( 'Habilitar descuentos VIP', 'wc-descuentos-vip' ); ?></label>
    <?php

   }

   public function field_porcentaje() {
    $opts = get_option( $this->option_key );
    $val = isset( $opts['porcentaje'] ) ? esc_attr( $opts['porcentaje'] ) : '10';
    ?>
    <input type="number" step="0.01" min="0" name="<?php echo esc_attr( $this->option_key ); ?>[porcentaje]" value="<?php echo $val; ?>" /> %
    <?php

   }

   public function admin_menu() {
      add_submenu_page(
        'woocommerce',
        __( 'Descuentos personalizados', 'wc-descuentos-vip' ),
        __( 'Descuentos personalizados', 'wc-descuentos-vip' ),
        'manage_woocommerce',
        'wc-descuentos-vip',
        array( $this, 'admin_page' )
     );

      add_submenu_page(
        'woocommerce',
        __( 'Log Descuentos', 'wc-descuentos-vip' ),
        __( 'Log Descuentos', 'wc-descuentos-vip' ),
        'manage_woocommerce',
        'wc-descuentos-vip-log',
        array( $this, 'admin_log_page' )
      );
    }

    public function admin_page() {
      if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( __( 'No autorizado', 'wc-descuentos-vip' ) );
      ?>
      <div class="wrap">
        <h1><?php _e( 'Descuentos personalizados VIP', 'wc-descuentos-vip' ); ?></h1>
         <form method="post" action="options.php">
            <?php
            settings_fields( 'wc_descuentos_vip_group' );
            do_settings_sections( 'wc_descuentos_vip' );
            submit_button();
            ?>
         </form>
        </div>
      <?php
    }

    public function maybe_apply_discount( $cart ) {
      if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
      if ( ! WC()->cart ) return;

      $opts = get_option( $this->option_key );
      if ( empty( $opts['enabled'] ) || $opts['enabled'] !== 'yes' ) return;

      $user_id = get_current_user_id();
      if ( ! $user_id ) return;

      $user = get_userdata( $user_id );
      if ( ! $user ) return;

      $roles = (array) $user->roles;
      if ( ! in_array( 'cliente_vip', $roles, true ) ) return;

      $minimo = isset( $opts['minimo'] ) ? floatval( $opts['minimo'] ) : 0;
      $porc = isset( $opts['porcentaje'] ) ? floatval( $opts['porcentaje'] ) : 0;

      $subtotal = floatval( WC()->cart->subtotal );

      if ( $subtotal < $minimo ) return;

      $descuento = round( $subtotal * ( $porc / 100 ), 2 );

      if ( $descuento <= 0 ) return;

      $label = sprintf( __( 'Descuento VIP (%s%%)', 'wc-descuentos-vip' ), number_format( $porc, 2 ) );
      WC()->cart->add_fee( $label, -$descuento );

      WC()->session->set( 'wc_descuentos_vip_aplicado', array(
        'user_id' => $user_id,
        'descuento' => $descuento,
        'porcentaje' => $porc,
        'subtotal' => $subtotal,
        'timestamp' => current_time( 'mysql' ),
      ) );
    }


}