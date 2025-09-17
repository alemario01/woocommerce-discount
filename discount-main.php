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

        add_action( 'admin_menu', array( $this, 'admin_menu') );
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

    add_settings_section( 'wc_descuentos_vip_main', __( 'Configuración Descuentos VIP', 'wc-descuentos-vip' ), null, 'wc-descuentos-vip' );

    add_settings_field( 'enabled', __( 'Activar descuento', 'wc-descuentos-vip' ), array( $this, 'field_enabled' ), 'wc-descuentos-vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'porcentaje', __( 'Porcentaje (%)', 'wc-descuentos-vip' ), array( $this, 'field_porcentaje' ), 'wc-descuentos-vip', 'wc_descuentos_vip_main' );
    add_settings_field( 'minimo', __( 'Monto mínimo del carrito', 'wc-descuentos-vip' ), array( $this, 'field_minimo' ), 'wc-descuentos-vip', 'wc_descuentos_vip_main' );

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

   public function field_minimo() {
    $opts = get_option( $this->option_key );
    $val = isset( $opts['minimo'] ) ? esc_attr( $opts['minimo'] ) : '100.00';
    ?>
    <input type="number" step="0.01" min="0" name="<?php echo esc_attr( $this->option_key ); ?>[minimo]" value="<?php echo $val; ?>" />
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
            do_settings_sections( 'wc-descuentos-vip' );
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

    public function log_order_discount( $order_id ) {
        global $wpdb;
        $order_id = intval( $order_id );
        if ( $order_id <= 0 ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $aplicado = get_post_meta( $order_id, '_wc_descuentos_vip_meta', true );

        if ( empty( $aplicado ) ) {
            
            $fees = $order->get_fees();
            foreach ( $fees as $fee ) {
                $label = $fee->get_name();
                if ( stripos( $label, 'Descuento VIP' ) !== false ) {
                    $descuento = abs( (float) $fee->get_total() );
                    $user_id = $order->get_user_id();
                    $aplicado = array(
                        'user_id' => $user_id,
                        'descuento' => $descuento,
                        'porcentaje' => null,
                        'subtotal' => $order->get_subtotal(),
                        'timestamp' => current_time( 'mysql' ),
                    );
                    break;
                }
            }
        }

        if ( empty( $aplicado ) ) return;

        $table = $this->table_name;
        $data = array(
            'user_id' => isset( $aplicado['user_id'] ) ? intval( $aplicado['user_id'] ) : null,
            'order_id' => $order_id,
            'descuento_aplicado' => number_format( (float) $aplicado['descuento'], 2, '.', '' ),
            'fecha' => current_time( 'mysql' ),
        );
        $format = array( '%d', '%d', '%f', '%s' );

        $wpdb->insert( $table, $data, $format );
    }

    public function admin_log_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( __( 'No autorizado', 'wc-descuentos-vip' ) );

        $table = new WC_Descuentos_VIP_Log_Table( $this->table_name );

        ?>
        <div class="wrap">
            <h1><?php _e( 'Log de descuentos VIP', 'wc-descuentos-vip' ); ?></h1>

            <form method="get">
                <input type="hidden" name="page" value="wc-descuentos-vip-log" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="user_id"><?php _e( 'Usuario (ID)', 'wc-descuentos-vip' ); ?></label></th>
                            <td><input type="number" id="user_id" name="user_id" value="<?php echo isset( $_GET['user_id'] ) ? esc_attr( $_GET['user_id'] ) : ''; ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="date_from"><?php _e( 'Fecha desde', 'wc-descuentos-vip' ); ?></label></th>
                            <td><input type="date" id="date_from" name="date_from" value="<?php echo isset( $_GET['date_from'] ) ? esc_attr( $_GET['date_from'] ) : ''; ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="date_to"><?php _e( 'Fecha hasta', 'wc-descuentos-vip' ); ?></label></th>
                            <td><input type="date" id="date_to" name="date_to" value="<?php echo isset( $_GET['date_to'] ) ? esc_attr( $_GET['date_to'] ) : ''; ?>" /></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Filtrar', 'wc-descuentos-vip' ), 'primary', 'submit', false ); ?>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-descuentos-vip-log' ) ); ?>"><?php _e( 'Limpiar', 'wc-descuentos-vip' ); ?></a>
            </form>

            <form method="post">
                <?php
                $table->prepare_items();
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }

}

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

if ( ! class_exists( 'WC_Descuentos_VIP_Log_Table' ) ) {
    class WC_Descuentos_VIP_Log_Table extends WP_List_Table {
        private $table_name;
        private $per_page = 20;
        public function __construct( $table_name ) {
                parent::__construct( array(
                    'singular' => 'descuento_log',
                    'plural'   => 'descuentos_log',
                    'ajax'     => false
                ) );
                global $wpdb;
                $this->table_name = $table_name;
            }

            public function get_columns() {
                return array(
                    'cb' => '<input type="checkbox" />',
                    'id' => __( 'ID', 'wc-descuentos-vip' ),
                    'user_id' => __( 'Usuario', 'wc-descuentos-vip' ),
                    'order_id' => __( 'Orden', 'wc-descuentos-vip' ),
                    'descuento_aplicado' => __( 'Descuento', 'wc-descuentos-vip' ),
                    'fecha' => __( 'Fecha', 'wc-descuentos-vip' ),
                );
            }

            public function column_cb( $item ) {
                return sprintf( '<input type="checkbox" name="bulk[]" value="%d" />', $item->id );
            }

            public function column_user_id( $item ) {
                if ( $item->user_id ) {
                    $u = get_userdata( $item->user_id );
                    if ( $u ) return sprintf( '<a href="%s">%s (#%d)</a>', esc_url( admin_url( 'user-edit.php?user_id=' . $item->user_id ) ), esc_html( $u->user_login ), intval( $item->user_id ) );
                    return esc_html( 'ID: ' . intval( $item->user_id ) );
                }
                return '-';
            }

            public function column_order_id( $item ) {
                if ( $item->order_id ) {
                    $link = admin_url( 'post.php?post=' . intval( $item->order_id ) . '&action=edit' );
                    return sprintf( '<a href="%s">#%d</a>', esc_url( $link ), intval( $item->order_id ) );
                }
                return '-';
            }

            public function column_descuento_aplicado( $item ) {
                return wc_price( $item->descuento_aplicado );
            }

            public function column_fecha( $item ) {
                return esc_html( $item->fecha );
            }

            public function prepare_items() {
                global $wpdb;

                $columns = $this->get_columns();
                $hidden = array();
                $sortable = array();
                $this->_column_headers = array( $columns, $hidden, $sortable );

                $per_page = $this->per_page;
                $current_page = $this->get_pagenum();

                $where = array();
                $params = array();

                if ( isset( $_GET['user_id'] ) && $_GET['user_id'] !== '' ) {
                    $uid = intval( $_GET['user_id'] );
                    if ( $uid > 0 ) {
                        $where[] = 'user_id = %d';
                        $params[] = $uid;
                    }
                }

                if ( isset( $_GET['date_from'] ) && $_GET['date_from'] !== '' ) {
                    $date_from = sanitize_text_field( $_GET['date_from'] );
                    if ( strtotime( $date_from ) ) {
                        $where[] = 'fecha >= %s';
                        $params[] = date( 'Y-m-d 00:00:00', strtotime( $date_from ) );
                    }
                }

                if ( isset( $_GET['date_to'] ) && $_GET['date_to'] !== '' ) {
                    $date_to = sanitize_text_field( $_GET['date_to'] );
                    if ( strtotime( $date_to ) ) {
                        $where[] = 'fecha <= %s';
                        $params[] = date( 'Y-m-d 23:59:59', strtotime( $date_to ) );
                    }
                }

                $where_sql = '';
                if ( ! empty( $where ) ) {
                    $where_sql = 'WHERE ' . implode( ' AND ', $where );
                }

                $sql_count = $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}", $params );
                $total_items = $wpdb->get_var( $sql_count );

                $offset = ( $current_page - 1 ) * $per_page;
                $sql = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY fecha DESC LIMIT %d OFFSET %d";
                $params_with_limit = array_merge( $params, array( $per_page, $offset ) );

                $query = $wpdb->prepare( $sql, $params_with_limit );
                $items = $wpdb->get_results( $query );

                $this->items = $items;
                $this->set_pagination_args( array(
                    'total_items' => $total_items,
                    'per_page' => $per_page,
                    'total_pages' => ceil( $total_items / $per_page ),
                ) );
            }
    }
}

WC_Discount_Main::instance();

add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    if ( ! is_a( $order, 'WC_Order' ) ) return;
    $session = WC()->session ? WC()->session->get( 'wc_descuentos_vip_aplicado' ) : false;
    if ( ! empty( $session ) && is_array( $session ) ) {
        $order->update_meta_data( '_wc_descuentos_vip_meta', $session );
    }
}, 20, 2 );