<?php
/*
Plugin Name: PHP Grid Control
Plugin URI: http://www.phpgrid.org/
Description: PHP Grid Control plugin from Abu Ghufran, with thanks to EkAndreas (flowcom.se)
Author: Abu Ghufran
Version: 0.5.6
Author URI: http://www.phpgrid.org/
*/


//Important to place the including class available to usage inside theme and other plugins!
include_once( WP_PLUGIN_DIR . "/phpgrid/lib/inc/jqgrid_dist.php");

/**
 * The class puts the dependent scripts in the page loading and creates a hook for header.
 */
class PHPGrid_Plugin{
    private $phpgrid_output;

    private $add = false;
    private $inlineadd = false;
    private $delete = false;
    private $edit = false;
    private $export = false;
    private $hidden = array();
    private $caption = '';
    private $lang = '';

    /**
     * Activates actions
     */
    function __construct()
    {
        // load core lib at template_redirect because we need the post data!
        add_action( "template_redirect", array( &$this, 'phpgrid_header' ) );

        // load js and css files
        add_action( "wp_enqueue_scripts", array( &$this, 'wp_enqueue_scripts' ) );

        // added short code for display position
        add_shortcode( "phpgrid", array( &$this, 'shortcode_phpgrid' ) );

        // add an action for the output
        add_action('phpgrid_output', array($this, 'phpgrid_output' ) );

        // ajax
        add_action('wp_ajax_phpgrid_data', array($this, 'phpgrid_header' ) );
        add_action('wp_ajax_nopriv_phpgrid_data', array($this, 'phpgrid_header' ) );
    }

    /**
     * This is the custom action, placed in header at your theme before any html-output!
     * To be continued: hooks and filters to perform different grids on different tables and datasources.
     */
    function phpgrid_header()
    {
        global $post;

        $ajax = false;
        $external_connection = false;

        if (isset($_REQUEST['action']) && esc_attr( $_REQUEST['action'] ) == 'phpgrid_data' ){
            $ajax = true;
        }

        $grid_columns = array();
        $grid = array();

        $regex_pattern = get_shortcode_regex();
        preg_match_all ('/'.$regex_pattern.'/s', $post->post_content, $regex_matches);
        foreach($regex_matches[2] as $k=>$code)
        {
            if ($code == 'phpgrid')
            {

                // set database table for CRUD operations, override with filter 'phpgrid_table'.
                $table = '';
                $select_command = '';

                $g = new jqgrid();

                $db_conf = apply_filters( 'phpgrid_connection', '' );

                //$external_connection = true;

                if ( is_array( $db_conf ) )
                {
                    $g = new jqgrid( $db_conf );
                    $external_connection = true;
                }

                $attribureStr = str_replace (" ", "&", trim ($regex_matches[3][$k]));
                $attribureStr = str_replace ('"', '', $attribureStr);

                $defaults = array ();
                $attributes = wp_parse_args($attribureStr, $defaults);

                $column_names = array();
                $column_titles = array();

                if (isset($attributes['table'])){
                    $table = $attributes['table'];
                }

                if (isset($attributes['columns'])){
                    $column_names = $attributes['columns'];
                }

                if (isset($attributes['titles'])){
                    $column_titles = $attributes['titles'];
                }

                if (isset($attributes['hidden'])){
                    $this->hidden = $attributes['hidden'];
                }

                if (isset($attributes['add'])){
                    $this->add = $attributes['add'];
                }

                if (isset($attributes['inlineadd'])){
                    $this->inlineadd = $attributes['inlineadd'];
                }

                if (isset($attributes['delete'])){
                    $this->delete = $attributes['delete'];
                }

                if (isset($attributes['edit'])){
                    $this->edit = $attributes['edit'];
                }

                if (isset($attributes['caption'])){
                    $this->caption = $attributes['caption'];
                }

                if (isset($attributes['export'])){
                    $this->export = $attributes['export'];
                }

                if (isset($attributes['language'])){
                    $this->lang = $attributes['language'];
                }

                if (isset($attributes['id'])){
                    $list_id = $attributes['id'];
                }

                if ( !empty($column_names) && !is_array( $column_names ) ) {

                    $cols = array();
                    $colnames_arr = explode( ",", $column_names );
                    $coltitles = explode( ",", $column_titles );
                    $this->hidden = explode( ",", $this->hidden );

                    foreach( $colnames_arr as $key => $column ){

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
                $actions = array(
                    "add"               => ($this->add === 'true'),
                    "edit"              => ($this->edit === 'true'),
                    "delete"            => ($this->delete === 'true'),
                    "rowactions"        => false,
                    "export"            => ($this->export === 'true'),
                    "autofilter"        => true,
                    "search"            => "simple",
                    "inlineadd"         => ($this->inlineadd === 'true'),
                    "showhidecolumns"   => false
                );

                // open actions for filters
                $actions = apply_filters( 'phpgrid_actions', $actions );
                $g->set_actions( $actions );

                if ( $ajax && isset( $_REQUEST['phpgrid_select_command'] ) ) $select_command = esc_attr( $_REQUEST['phpgrid_select_command'] );

                $select_command = apply_filters( 'phpgrid_select_command', $select_command );

                if ( $ajax && isset( $_REQUEST['phpgrid_table'] ) ) $table = esc_attr( $_REQUEST['phpgrid_table'] );

                $table = apply_filters( 'phpgrid_table', $table );

                if ( !empty( $table ) )
                {
                    $g->table = $table;
                }
                else if ( !empty( $select_command ) )
                {
                    $g->select_command = $select_command;
                }
                else
                {
                    return;
                }

                if (!empty($grid_columns))
                    $g->set_columns( apply_filters( 'phpgrid_columns', $grid_columns ) );

                if ( empty($this->caption) ) $this->caption = $table;

                // set some standard options to grid. Override this with filter 'phpgrid_options'.
                $grid["caption"] = $this->caption;
                $grid["multiselect"] = false;
                $grid["autowidth"] = true;

                // fetch if filter is used otherwise use standard options
                $grid = apply_filters( 'phpgrid_options', $grid );

                // now use ajax! this is a wp override!
                //$grid["url"] = admin_url( 'admin-ajax.php' ) . '?action=phpgrid_data&phpgrid_table=' . $table;

                // set the options
                $g->set_options( $grid );

                if ( !empty( $this->lang ) ){
                    add_filter( 'phpgrid_lang', array($this, 'lang') );
                }

                // render grid, possible to override the name with filter 'phpgrid_name'.
                $this->phpgrid_output["$list_id"] = $g->render( apply_filters( 'phpgrid_name', $list_id ) );

            }
        }

        //swiching back to WP
        if ( $external_connection ){
            mysql_connect( DB_HOST, DB_USER, DB_PASSWORD );
            mysql_select_db( DB_NAME );
        }

        if ( $ajax ){
            die(0);
        }

    }

    function lang(){
        return $this->lang;
    }

    /**
     * Register styles and scripts. The scripts are placed in the footer for compability issues.
     */
    function wp_enqueue_scripts()
    {
        wp_enqueue_script( 'jquery' );
        //wp_enqueue_script( 'jquery-ui-core' );

        $theme = apply_filters( 'phpgrid_theme', 'redmond' );
        $theme_script = apply_filters( 'phpgrid_theme_script', WP_PLUGIN_URL . '/phpgrid/lib/js/themes/' . $theme . '/jquery-ui.custom.css' );
        wp_register_style( 'phpgrid_theme', $theme_script );
        wp_enqueue_style( 'phpgrid_theme' );

        wp_register_style( 'jqgrid_css', WP_PLUGIN_URL . '/phpgrid/lib/js/jqgrid/css/ui.jqgrid.css' );
        wp_enqueue_style( 'jqgrid_css' );

        // fix for bootstrap based themes
        wp_register_style( 'jqgrid_bootstrap', WP_PLUGIN_URL . '/phpgrid/lib/js/jqgrid/css/ui.bootstrap.jqgrid.css' );
        wp_enqueue_style( 'jqgrid_bootstrap' );

        $lang = apply_filters( 'phpgrid_lang', 'en' );
        $localization = apply_filters( 'phpgrid_lang_script', WP_PLUGIN_URL . '/phpgrid/lib/js/jqgrid/js/i18n/grid.locale-' . $lang . '.js' );
        wp_register_script( 'jqgrid_localization', $localization, array('jquery'), false, true);
        wp_enqueue_script( 'jqgrid_localization' );

        wp_register_script( 'jqgrid', WP_PLUGIN_URL . '/phpgrid/lib/js/jqgrid/js/jquery.jqGrid.min.js', array('jquery'), false, true);
        wp_enqueue_script( 'jqgrid' );

        //wp_register_script( 'jqquery-ui-theme', WP_PLUGIN_URL . '/phpgrid/lib/js/themes/jquery-ui.custom.min.js', array('jquery'), false, true);
        //wp_enqueue_script( 'jqquery-ui-theme' );

    }

    /*
     * Output the shortcode
     */
    function shortcode_phpgrid( $attr )
    {
        return $this->phpgrid_output[$attr["id"]];
    }

    /*
     * Output the shortcode
     */
    function phpgrid_output()
    {
        echo $this->phpgrid_output;
    }
}


//Create an object instance of the class
$phpgrid_plugin = new PHPGrid_Plugin();