<?php
/*
https://github.com/gridphp/phpgrid
Plugin Name: PHP Grid Control
Plugin URI: http://www.phpgrid.org/
Description: PHP Grid Control plugin from Abu Ghufran, with thanks to EkAndreas (flowcom.se), William Dutton
Author: Abu Ghufran
Version: 0.5.7
Author URI: http://www.phpgrid.org/
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Important to place the including class available to usage inside theme and other plugins!
include_once( WP_PLUGIN_DIR . "/phpgrid/lib/inc/jqgrid_dist.php");

/**
 * The class puts the dependent scripts in the page loading and creates a hook for header.
 */
class PHPGrid_Plugin{
    const MYSALT = '_mysalt';

    /**
     * The single instance of the class.
     *
     * @var PHPGrid_Plugin
     * @since 0.5.6
     */
    protected static $_instance = null;

    /** @var string the plugin path */
    private $plugin_path;

    /** @var string the plugin url */
    private $plugin_url;

    private $phpgrid_output;

    private $options = array('add' => false,
        'inlineadd' => true,
        'delete' => false,
        'edit' => false,
        'export' => false,
        'hidden' => array(),
        'caption' => '',
        'lang' => '');

    private $external_connection = false;

    /**
     * Activates actions
     */
    function __construct(){

        //@deprecated moved towared using proper shortcodes function usage and wordpress ajax
        // load core lib at template_redirect because we need the post data!
        //add_action( "template_redirect", array( &$this, 'phpgrid_header' ) );

        // load js and css files
        add_action( "wp_enqueue_scripts", array( &$this, 'wp_enqueue_scripts' ) );

        // added short code for display position
        add_shortcode( "phpgrid", array( &$this, 'shortcode_phpgrid' ) );

        // add an action for the output
        add_action('phpgrid_output', array($this, 'phpgrid_output' ) );

        // ajax
        add_action('wp_ajax_phpgrid_data', array($this, 'phpgrid_ajax' ) );
        //comment out if you don't want users who are not logged in using this function
        add_action('wp_ajax_nopriv_phpgrid_data', array($this, 'phpgrid_ajax' ) );
    }

    //class is used between shortcodes so may leak data if not reset
    public function reset() {
        $this->options = array('add' => false,
            'inlineadd' => true,
            'delete' => false,
            'edit' => false,
            'export' => false,
            'hidden' => array(),
            'caption' => '',
            'lang' => '');
    }
    public function enqueue_admin() {
        add_action( "admin_enqueue_scripts", array( &$this, 'wp_enqueue_scripts' ) );
    }

    public function phpgrid_ajax()
    {
        check_ajax_referer( 'phpgrid_data', 'security' );

        $attributeStore = $_REQUEST['attribute_store'];
        $attribute_store_hash = $_REQUEST['attribute_storehash'];
        $security = $_REQUEST['security'];

        //hmac security to ensure that shortcode data is not altered on the ajax callback
        $attributeStoreHash_check =  $this->hmac($attributeStore, $security);

        if (! $this->hash_compare($attribute_store_hash, $attributeStoreHash_check)) {
            die('attribute_store has been altered:' . $attribute_store_hash . ' vs:' . $attributeStoreHash_check);
        }

        $attributes =  unserialize( $this->base64url_decode($attributeStore));

        $select_command = (isset($_REQUEST['phpgrid_select_command'])) ? esc_attr($_REQUEST['phpgrid_select_command']) : null;
        $table = (isset($_REQUEST['phpgrid_table']))? esc_attr($_REQUEST['phpgrid_table']) : null;

        $this->generate_jqgrid($attributes, $select_command, $table);

        die();
    }

    /**
     * This is the custom action, placed in header at your theme before any html-output!
     * To be continued: hooks and filters to perform different grids on different tables and datasources.
     * @deprecated moved towards using proper shortcodes function usage and wordpress ajax
     */
    public function phpgrid_header() {
        $regex_pattern = get_shortcode_regex();

        global $post;
        preg_match_all ('/'.$regex_pattern.'/s', $post->post_content, $regex_matches);
        foreach($regex_matches[2] as $k=>$code) {
            if ($code == 'phpgrid') {
                $attribureStr = str_replace (" ", "&", trim ($regex_matches[3][$k]));
                $attribureStr = str_replace ('"', '', $attribureStr);

                $this->generate_jqgrid($attribureStr);

            } //end phpgrid
        } //end foreach

        //swiching back to WP
        if ( $this->external_connection ){
            global $wpdb;
            $wpdb->db_connect();
        }
    }

    public function generate_jqgrid($attribure, $select_command = '', $table = '') {
        $defaults = array ();
        $attributes = wp_parse_args($attribure, $defaults);

        // set database table for CRUD operations, override with filter 'phpgrid_table'.

        $db_conf = $this->get_database_config();

        if ( is_array( $db_conf ) )  {
            $g = $this->initialize_grid($db_conf);
            $this->external_connection = true;
        } else {
            $g = $this->initialize_grid();
        }

        if (isset($attributes['table'])) {
            $table = $attributes['table'];
        }

        $column_names = array();
        if (isset($attributes['columns'])) {
            $column_names = $attributes['columns'];
        }

        $column_titles = array();
        if (isset($attributes['titles'])) {
            $column_titles = $attributes['titles'];
        }

        $attributes = $this->configure_options($attributes);

        $list_id ='table';
        if (isset($attributes['id'])) {
            $list_id = $attributes['id'];
        }

        $grid_columns = array();
        if ( !empty($column_names) && !is_array( $column_names ) ) {

            $cols = array();
            $colnames_arr = explode( ",", $column_names );
            $coltitles = explode( ",", $column_titles );
            $this->options['hidden'] = explode( ",", $this->options['hidden'] );

            foreach( $colnames_arr as $key => $column ) {

                $col = array();
                $col['name'] = $column;
                $col['editable'] = true;

                if ( $coltitles[$key] ) $col['title'] = $coltitles[$key]; // caption of column
                //if ( in_array( $column, $this->hidden ) ) $col['hidden'] = true;
                $cols[] = $col;
            }

            $grid_columns = $cols;
        }

        // set actions to the grid
        $g->set_actions(apply_filters('phpgrid_actions', $this->get_actions()));

        $select_command = apply_filters( 'phpgrid_select_command', $select_command );
        $table = apply_filters( 'phpgrid_table', $table );

        if ( !empty( $table ) ) {
            $g->table = $table;
        } else if ( !empty( $select_command ) ) {
            $g->select_command = $select_command;
        } else {
            return;
        }

        $grid_columns = apply_filters('phpgrid_columns', $grid_columns);
        if (!empty($grid_columns)) {
            $g->set_columns($grid_columns);
        }

        $grid_events = apply_filters( 'phpgrid_events', array() );
        if (!empty($grid_events)) {
            $g->set_events($grid_events);
        }

        if ( empty($this->options['caption']) ) $this->options['caption'] = $table;

        $grid = array();
        // set some standard options to grid. Override this with filter 'phpgrid_options'.
        $grid["caption"] = $this->options['caption'];
        $grid["multiselect"] = false;
        $grid["autowidth"] = true;

        // fetch if filter is used otherwise use standard options
        $grid = apply_filters( 'phpgrid_options', $grid );

        // now use ajax! this is a wp override!
        $security =  wp_create_nonce("phpgrid_data");
        $attributeStore =  $this->base64url_encode(serialize($attribure));
        $attributeStoreHash = $this->hmac($attributeStore, $security);
        $grid["url"] = admin_url( 'admin-ajax.php' ) . '?action=phpgrid_data'
            . '&security=' .$security
            . '&attribute_store=' . $attributeStore
            . '&attribute_storehash=' . $attributeStoreHash;

        // set the options
        $g->set_options( $grid );

        if ( !empty( $this->options['lang'] ) ){
            add_filter( 'phpgrid_lang', array($this, 'lang') );
        }

        // render grid, possible to override the name with filter 'phpgrid_name'.
        $this->phpgrid_output["$list_id"] = $g->render( apply_filters( 'phpgrid_name', $list_id ) );
    }

    public function lang(){
        return $this->options['lang'];
    }

    /**
     * Register styles and scripts. The scripts are placed in the footer for compability issues.
     */
    public function wp_enqueue_scripts()
    {
        wp_enqueue_script( 'jquery' );
        //wp_enqueue_script( 'jquery-ui-core' );

        $theme = apply_filters( 'phpgrid_theme', 'metro-light' );
        $theme_script = apply_filters( 'phpgrid_theme_script', $this->get_plugin_url() . '/../phpgrid/lib/js/themes/' . $theme . '/jquery-ui.custom.css' );
        wp_register_style( 'phpgrid_theme', $theme_script );
        wp_enqueue_style( 'phpgrid_theme' );

        wp_register_style( 'jqgrid_css', $this->get_plugin_url() . '/../phpgrid/lib/js/jqgrid/css/ui.jqgrid.css' );
        wp_enqueue_style( 'jqgrid_css' );

        // fix for bootstrap based themes
        wp_register_style( 'jqgrid_bootstrap', $this->get_plugin_url() . '/../phpgrid/lib/js/jqgrid/css/ui.bootstrap.jqgrid.css' );
        wp_enqueue_style( 'jqgrid_bootstrap' );

        $lang = apply_filters( 'phpgrid_lang', 'en' );
        $localization = apply_filters( 'phpgrid_lang_script', $this->get_plugin_url() . '/../phpgrid/lib/js/jqgrid/js/i18n/grid.locale-' . $lang . '.js' );
        wp_register_script( 'jqgrid_localization', $localization, array('jquery'), false, true);
        wp_enqueue_script( 'jqgrid_localization' );

        wp_register_script( 'jqgrid', $this->get_plugin_url() . '/../phpgrid/lib/js/jqgrid/js/jquery.jqGrid.min.js', array('jquery'), false, true);
        wp_enqueue_script( 'jqgrid' );

        //wp_register_script( 'jqquery-ui-theme', $this->get_plugin_url() . '/../phpgrid/lib/js/themes/jquery-ui.custom.min.js', array('jquery'), false, true);
        //wp_enqueue_script( 'jqquery-ui-theme' );

    }

    /*
     * Output the shortcode
     */
    public function shortcode_phpgrid( $attr ) {
        $this->reset();
        $this->generate_jqgrid($attr);
        //swiching back to WP
        if ( $this->external_connection ){
            global $wpdb;
            $wpdb->db_connect();
            $this->external_connection = false;
        }
        return $this->phpgrid_output[$attr["id"]];
    }

    /*
     * Output the shortcode
     */
    public function phpgrid_output() {
        echo $this->phpgrid_output;
    }

    /**
     * Gets the absolute plugin path without a trailing slash, e.g.
     * /path/to/wp-content/plugins/plugin-directory
     *
     * @since 1.0
     * @return string plugin path
     */
    public function get_plugin_path() {
        if ( $this->plugin_path )
            return $this->plugin_path;

        return $this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );
    }

    /**
     * Gets the plugin url without a trailing slash
     *
     * @since 1.0
     * @return string the plugin url
     */
    public function get_plugin_url() {
        if ( $this->plugin_url )
            return $this->plugin_url;

        return $this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );
    }

    /**
     * @return array
     */
    private function get_database_config() {
        $db_conf = array(
            "type" => 'mysqli',
            "server" => DB_HOST,
            "user" => DB_USER,
            "password" => DB_PASSWORD,
            "database" => DB_NAME,
            "charset" => DB_CHARSET
        );
        return $db_conf;
    }

    /**
     * @param $db_conf
     * @return jqgrid
     */
    private function initialize_grid($db_conf = null) {
        return new jqgrid(apply_filters('phpgrid_connection', $db_conf));
    }

    /**
     * @return array|mixed|void
     */
    public function get_actions() {
        $options = $this->options;
        $actions = array(
            "add" => ($options['add'] === 'true'),
            "edit" => ($options['edit'] === 'true'),
            "delete" => ($options['delete'] === 'true'),
            "rowactions" => false,
            "export" => ($options['export'] === 'true'),
            "autofilter" => true,
            "search" => "advance",
            "inlineadd" => ($options['inlineadd'] === 'true'),
            "showhidecolumns" => false
        );
        return $actions;
    }

    public function hash_compare($a, $b) {
        if (!is_string($a) || !is_string($b)) {
            return false;
        }

        $len = strlen($a);
        if ($len !== strlen($b)) {
            return false;
        }

        $status = 0;
        for ($i = 0; $i < $len; $i++) {
            $status |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $status === 0;
    }

    /**
     * Main PHPGrid_Plugin Instance.
     *
     * Ensures only one instance of PHPGrid_Plugin is loaded or can be loaded.
     *
     * @since 0.04
     * @static
     * @see PHPGrid_Plugin()
     * @return PHPGrid_Plugin - Main instance.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * @param $attributes
     * @return mixed
     */
    public function configure_options($attributes)
    {
        if (isset($attributes['hidden'])) {
            $this->options['hidden'] = $attributes['hidden'];
        }

        if (isset($attributes['add'])) {
            $this->options['add'] = $attributes['add'];
        }

        if (isset($attributes['inlineadd'])) {
            $this->options['inlineadd'] = $attributes['inlineadd'];
        }

        if (isset($attributes['delete'])) {
            $this->options['delete'] = $attributes['delete'];
        }

        if (isset($attributes['edit'])) {
            $this->options['edit'] = $attributes['edit'];
        }

        if (isset($attributes['caption'])) {
            $this->options['caption'] = $attributes['caption'];
        }

        if (isset($attributes['export'])) {
            $this->options['export'] = $attributes['export'];
        }

        if (isset($attributes['language'])) {
            $this->options['lang'] = $attributes['language'];
            return $attributes;
        }
        return $attributes;
    }

    /**
     * @param $attributeStore
     * @param $security
     * @return string
     */
    public function hmac($attributeStore, $security)
    {
        $attributeStoreHash = $this->base64url_encode(hash_hmac('sha256', $attributeStore, $security . self::MYSALT, true));
        return $attributeStoreHash;
    }

    function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

}

function PHPGrid() {
    return PHPGrid_Plugin::instance();
}

//Create an object instance of the class
$phpgrid_plugin = PHPGrid();