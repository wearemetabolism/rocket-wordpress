<?php

namespace Rocket;

use Dflydev\DotAccessData\Data as DotAccessData;

use Rocket\Traits\ApplicationTrait,
    Rocket\Traits\SingletonTrait;

use Rocket\Model\CustomPostType,
    Rocket\Model\Menu,
    Rocket\Model\Taxonomy,
    Rocket\Model\Router,
	Rocket\Model\PageTemplater,
	Rocket\Model\Terms;

use Symfony\Component\Routing\Route as Route;

include 'Helper/Functions.php';

/**
 * Class Rocket Framework
 */
abstract class Application {


    // Use of cross-framework functions by extending traits
    use ApplicationTrait, SingletonTrait;


    /**
     * @var string plugin domain name for translations
     */
	public static $acf_folder, $languages_folder;

    public static $domain_name = 'default';
    public static $bo_domain_name = 'bo_default';

    protected $router, $global_context, $class_loader, $prevent_recurssion;

    public $remote_url;


	/**
	 * Set context
	 * @param $context
	 */
	public function setContext($context)
	{
		$this->global_context = $context;
	}


    /**
     * Get context
     */
	protected function getContext($context){ return []; }


    /**
     * Get archive id
     */
	protected function getSlug($id, $type){

		if( $type == 'archive' )
			return $this->config->get('post_types.'.$id.'.has_archive', $id.'s');

		if( $type == 'post' )
			return $this->config->get('post_types.'.$id.'.rewrite.slug', $id);
	}


    /**
     * Quickly upload file
     */
	protected function uploadFile($file='file', $allowed_type = ['image/jpeg', 'image/gif', 'image/png'], $path='/user', $max_size=1048576){

		if( !isset($_FILES[$file]) or empty($_FILES[$file]) )
			return false;

		$file = $_FILES[$file];

		if ($file['error'] !== UPLOAD_ERR_OK)
			return ['error' => true, 'message' => 'Sorry, there was an error uploading your file.' ];

		if ($file['size'] > $max_size)
			return ['error' => true, 'message' => 'Sorry, the file is too large.' ];

		$mime_type = mime_content_type($file['tmp_name']);

		if( !in_array($mime_type, $allowed_type) )
			return ['error' => true, 'message' => 'Sorry, this file format is not permitted' ];

		$name = preg_replace("/[^A-Z0-9._-]/i", "_", basename( $file['name']) );

		$target_file = '/uploads'.$path.'/'.uniqid().'_'.$name;
		$upload_dir = WP_CONTENT_DIR.'/uploads'.$path;

		if( !is_dir($upload_dir) )
			mkdir($upload_dir, 0777, true);

		if( !is_writable($upload_dir) )
			return ['error' => true, 'message' => 'Sorry, upload directory is not writable.' ];

		if( move_uploaded_file($file['tmp_name'], WP_CONTENT_DIR.$target_file) )
			return ['filename' => $target_file, 'original_filename' => basename( $file['name']), 'type' => $mime_type ];
		else
			return ['error' => true, 'message' => 'Sorry, there was an error uploading your file.' ];
	}


	/**
	 * Quickly send form
	 */
	protected function sendForm($fields=[], $files=[], $subject='New message from website', $email_id='email', $delete_attachements=true){

		$email = $this->getRequest( $email_id );

		if ( $email && is_email( $email ) )
		{
			$body = $subject." :\n\n";

			foreach ( $fields as $key )
			{
				$value = $this->getRequest( $key );
				$body  .= ( $value ? ' - ' . $key . ' : ' . $value . "\n" : '' );
			}

			$attachments = [];

			foreach ( $files as $file )
			{
				$file = $this->getRequest( $file );

				if ( file_exists( WP_CONTENT_DIR.$file ) )
					$attachments[] = WP_CONTENT_DIR.$file;
			}

			if ( wp_mail( get_option( 'admin_email' ), $subject, $body, $attachments ) )
			{
				if( $delete_attachements )
				{
					foreach ( $attachments as $file )
						@unlink($file);
				}

				return true;
			}
			else
				return ['error' => 2, 'message' => "Sorry, the server wasn't able to complete this request"];

		}
		else
		{
			return $this->json( ['error' => 1, 'message' => "Invalid email address. Please type a valid email address."] );
		}
	}


	/**
	 * Get request parameter
	 */
	protected function getRequest( $key, $limit=500 ) {

		if ( !isset( $_REQUEST[ $key ] ) )
			return false;
		else
			return substr( trim(sanitize_text_field( $_REQUEST[ $key ] )), 0, $limit );
	}


    /**
     * Application Constructor
     */
    public function setup()
    {
    	if( defined('WP_INSTALLING') and WP_INSTALLING )
		    return;

        $this->definePaths();
        $this->loadConfig();

        $this->registerFilters();

        // Global init action
        add_action( 'init', function()
        {
	        $this->addPostTypes();
	        $this->addTaxonomies();
	        $this->addMenus();
	        $this->addMaintenanceMode();
	        $this->setPermalink();
	        $this->registerActions();

	        if( is_admin() )
	        {
		        $this->setTheme();
		        $this->addOptionPages();
	        }
	        else
	        {
		        $this->router = new Router();
		        $this->router->setLocale(get_locale());

		        $this->registerRoutes();
	        }

	        $this->init();
        });


        // When viewing admin
        if( is_admin() )
        {
            // Setup ACF Settings
            add_action( 'acf/init', [$this, 'ACFInit'] );

            // Remove image sizes for thumbnails
            add_filter( 'intermediate_image_sizes_advanced', [$this, 'intermediateImageSizesAdvanced'] );
	        add_filter( 'wp_terms_checklist_args', [Terms::getInstance(), 'wp_terms_checklist_args'] );
	        add_filter( 'mce_buttons', [$this, 'TinyMceButtons']);
	        add_filter( 'wp_editor_settings', [$this, 'editorSettings'], 10, 2);
	        add_filter( 'map_meta_cap', [$this, 'addUnfilteredHtmlCapabilityToEditors'], 1, 3 );

            // Removes or add pages
            add_action( 'admin_menu', [$this, 'adminMenu']);
	        add_action( 'admin_footer', [$this, 'adminFooter'] );
	        add_action( 'admin_init', [$this, 'adminInit'] );
	        add_action( 'admin_head', [$this, 'hideUpdateNotice'], 1 );
	        add_action( 'wpmu_options', [$this, 'wpmuOptions'] );
	        add_action( 'wp_handle_upload_prefilter', [$this, 'cleanFilename']);

	        // Replicate media on network
	        if( $this->config->get('multisite.shared_media') and is_multisite() )
	        {
		        add_action( 'add_attachment', [$this, 'addAttachment']);
		        add_action( 'delete_attachment', [$this, 'deleteAttachment']);
		        add_filter( 'wp_update_attachment_metadata', [$this, 'updateAttachment'], 10, 2);
		        add_filter( 'wpmu_delete_blog_upload_dir', '__return_false' );
	        }

            //check loaded plugin
            add_action( 'plugins_loaded', [$this, 'pluginsLoaded']);
            add_action( 'admin_notices', [$this, 'adminNotices']);

            $this->defineSupport();
        }
        else
        {
            add_action( 'after_setup_theme', [$this, 'afterSetupTheme']);
            add_action( 'wp_footer', [$this, 'wpFooter']);
	        add_action( 'pre_get_posts', [$this, 'preGetPosts'] );
        }
    }


    /**
     * hide dashboard update notices
     */
    public function hideUpdateNotice()
    {
	    if (!current_user_can('update_core'))
		    remove_action( 'admin_notices', 'update_nag', 3 );
    }


    /**
     * delete attachment reference on other blog
     */
    public function updateAttachment($data, $attachment_ID )
    {
	    if( $this->prevent_recurssion || !isset($_REQUEST['action']) || $_REQUEST['action'] != 'image-editor')
		    return $data;

	    $this->prevent_recurssion = true;

	    global $wpdb;

	    $current_site_id = get_current_blog_id();
	    $original_attachment_id = get_post_meta( $attachment_ID, '_wp_original_attachment_id', true );

	    foreach ( get_sites() as $site ) {

		    if ( (int) $site->blog_id !== $current_site_id ) {

			    switch_to_blog( $site->blog_id );

			    if( $original_attachment_id )
			    {
				    $results = $wpdb->get_results( "select `post_id` from $wpdb->postmeta where `meta_value` = '$original_attachment_id' AND `meta_key` = '_wp_original_attachment_id'", ARRAY_A );

				    if( !empty($results) )
					    wp_update_attachment_metadata($results[0]['post_id'], $data);
			    }
			    else
			    {
				    wp_update_attachment_metadata($attachment_ID, $data);
			    }
		    }
	    }

	    restore_current_blog();

	    $this->prevent_recurssion = false;

	    return $data;
    }


    /**
     * delete attachment reference on other blog
     */
    public function deleteAttachment( $attachment_ID )
    {
	    if( $this->prevent_recurssion )
		    return;

	    $this->prevent_recurssion = true;

	    global $wpdb;

	    $current_site_id = get_current_blog_id();
	    $original_attachment_id = get_post_meta( $attachment_ID, '_wp_original_attachment_id', true );

	    foreach ( get_sites() as $site ) {

		    if ( (int) $site->blog_id !== $current_site_id ) {

			    switch_to_blog( $site->blog_id );

			    if( $original_attachment_id )
			    {
				    $results = $wpdb->get_results( "select `post_id` from $wpdb->postmeta where `meta_value` = '$original_attachment_id' AND `meta_key` = '_wp_original_attachment_id'", ARRAY_A );

				    if( !empty($results) )
					    wp_delete_attachment($results[0]['post_id']);
			    }
			    else
			    {
				    wp_delete_attachment($attachment_ID);
			    }
		    }
	    }

	    restore_current_blog();

	    $this->prevent_recurssion = false;
    }


    /**
     * add attachment to other blog by reference
     */
    public function addAttachment( $attachment_ID )
    {
	    if( $this->prevent_recurssion )
		    return;

	    $this->prevent_recurssion = true;

	    $attachment = get_post( $attachment_ID );
	    $current_site_id = get_current_blog_id();

	    $attr = [
		    'post_mime_type' => $attachment->post_mime_type,
		    'filename'       => $attachment->guid,
		    'post_title'     => $attachment->post_title,
		    'post_status'    => $attachment->post_status,
		    'post_parent'    => 0,
		    'post_content'   => $attachment->post_content,
		    'guid'           => $attachment->guid
	    ];

	    $file = get_attached_file( $attachment_ID );
	    $attachment_metadata = wp_generate_attachment_metadata( $attachment_ID, $file );

	    add_post_meta( $attachment_ID, '_wp_original_attachment_id', $attachment_ID );

	    foreach ( get_sites() as $site ) {

		    if ( (int) $site->blog_id !== $current_site_id ) {

			    switch_to_blog( $site->blog_id );

			    $inserted_id = wp_insert_attachment( $attr, $file );
			    if ( !is_wp_error($inserted_id) )
			    {
				    wp_update_attachment_metadata( $inserted_id, $attachment_metadata );
				    add_post_meta( $inserted_id, '_wp_original_attachment_id', $attachment_ID );
			    }

		    }
	    }

	    restore_current_blog();

	    $this->prevent_recurssion = false;
    }


    /**
     * Add custom post type for taxonomy archive page
     */
    public function addMaintenanceMode()
    {
    	if( is_admin() )
	    {
		    add_action( 'admin_init', function(){

		    	add_settings_field('maintenance_field', __('Maintenance Mode'), function(){

				    echo '<input type="checkbox" id="maintenance_field" name="maintenance_field" value="1" ' . checked( 1, get_option('maintenance_field'), false ) . ' />'.__('Activate maintenance mode');

			    }, 'general');

			    register_setting('general', 'maintenance_field');
		    });
	    }

	    add_action( 'admin_bar_menu', function( $wp_admin_bar )
	    {
		    if( !is_admin() && is_post_type_archive() )
		    {
		    	$object = get_queried_object();

			    $args = [
				    'id'    => 'edit',
				    'title' => __('Edit Posts'),
				    'href'  => get_admin_url( null, '/edit.php?post_type='.$object->name ),
				    'meta'   => ['class' => 'ab-item']
			    ];

			    $wp_admin_bar->add_node( $args );
		    }

		    $args = [
			    'id'    => 'maintenance',
			    'title' => __('Maintenance mode').' : '.( get_option( 'maintenance_field', false) ? __('On') : __('Off')),
			    'href'  => get_admin_url( null, '/options-general.php#maintenance_field' )
		    ];

		    $wp_admin_bar->add_node( $args );

	    }, 999 );
    }


    /**
     * Add custom post type for taxonomy archive page
     */
    public function preGetPosts( $query )
    {
	    if( ! $query->is_main_query() || is_admin() )
		    return;

	    if ( $query->is_tax )
	    {
		    $post_type = get_query_var('post_type');

		    if( !$post_type )
		    {
			    global $wp_taxonomies;

			    $taxo = get_queried_object();
			    $post_type = ( isset($taxo->taxonomy, $wp_taxonomies[$taxo->taxonomy] ) ) ? $wp_taxonomies[$taxo->taxonomy]->object_type : array();

			    $query->set('post_type', $post_type);
			    $query->query['post_type'] = $post_type;
		    }
	    }

	    return $query;
    }


    /**
     * Add custom post type for taxonomy archive page
     */
    public function editorSettings( $settings, $editor_id )
    {
	    if ( $editor_id == 'description' and class_exists('WPSEO_Taxonomy') and \WPSEO_Taxonomy::is_term_edit( $GLOBALS['pagenow'] ) )
	    {
		    $settings[ 'tinymce' ] = false;
		    $settings[ 'wpautop' ] = false;
		    $settings[ 'media_buttons' ] = false;
		    $settings[ 'quicktags' ] = false;
		    $settings[ 'default_editor' ] = '';
		    $settings[ 'textarea_rows' ] = 4;
	    }

	    return $settings;
    }


	/**
	 * Allow iframe for editor in WYSIWYG
	 */
	public function addUnfilteredHtmlCapabilityToEditors( $caps, $cap, $user_id )
	{
		if ( 'unfiltered_html' === $cap && user_can( $user_id, 'editor' ) )
			$caps = array( 'unfiltered_html' );

		return $caps;
	}


	/**
	 * Configure Tiny MCE first line buttons
	 */
	public function TinyMceButtons( $mce_buttons )
	{
		$mce_buttons = array(
			'formatselect','bold','italic','underline','strikethrough','bullist','numlist','blockquote','hr','alignleft',
			'aligncenter','alignright','alignjustify','link','unlink','wp_more','spellchecker','wp_adv','dfw'
		);
	    return $mce_buttons;
    }


    /**
     * Unset thumbnail image
     */
    public function intermediateImageSizesAdvanced($sizes)
    {
        unset($sizes['medium'], $sizes['medium_large'], $sizes['large']);
        return $sizes;
    }


    /**
     * Define rocket theme as default theme.
     */
    public function setTheme()
    {
        $current_theme = wp_get_theme();

        if ($current_theme->get_stylesheet() != 'rocket')
            switch_theme('rocket');
    }


    /**
     * Clean WP Head
     */
    public function afterSetupTheme()
    {
    	if( is_dir($this::$languages_folder) )
		    load_theme_textdomain( $this::$domain_name, $this::$languages_folder );

	    remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'print_emoji_detection_script', 7 );
        remove_action('wp_print_styles', 'print_emoji_styles' );
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'wp_resource_hints', 2 );
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
	    remove_action('template_redirect', 'rest_output_link_header', 11 );
	    remove_action('template_redirect', 'wp_shortlink_header', 11 );
    }


    /**
     * Clean WP Footer
     */
    public function wpFooter()
    {
        wp_deregister_script( 'wp-embed' );
    }


    /**
     * Custom theme compatibilities according to created project.
     */
    protected function defineSupport()
    {
    	$theme_support = $this->config->get('theme_support', []);

        if( in_array('post_thumbnails', $theme_support) )
            add_theme_support( 'post-thumbnails' );

	    if( in_array('woocommerce', $theme_support) )
		    add_theme_support( 'woocommerce' );

        add_post_type_support( 'page', 'excerpt' );
    }



    /**
     * Adds or remove pages from menu admin.
     */
    public function adminMenu()
    {
    	//clean interface
        foreach ( $this->config->get('remove_menu_page', []) as $menu)
        {
            remove_menu_page($menu);
        }

	    remove_submenu_page('themes.php', 'themes.php');

    	//clean interface
        foreach ( $this->config->get('remove_submenu_page', []) as $menu=>$submenu)
        {
	        remove_submenu_page($menu, $submenu);
        }
    }


    /**
     * Adds specific post types here
     * @see CustomPostType
     */
    public function addPostTypes()
    {
        foreach ( $this->config->get('post_types', []) as $slug => $data )
        {
            $data = new DotAccessData($data);

            $label = __(ucfirst($this->config->get('taxonomies.'.$slug.'.name', $slug.'s')), Application::$bo_domain_name);

            if( $slug != 'post' )
            {
	            $post_type = new CustomPostType($label, $slug);
	            $post_type->hydrate($data);
	            $post_type->register();
            }
        };
    }


    /**
     * Adds Custom taxonomies
     * @see Taxonomy
     */
    public function addTaxonomies()
    {
        foreach ( $this->config->get('taxonomies', []) as $slug => $data )
        {
            $data = new DotAccessData($data);
            $label = __(ucfirst( $this->config->get('taxonomies.'.$slug.'.name', $slug.'s')), Application::$bo_domain_name);

            $taxonomy = new Taxonomy($label, $slug);
            $taxonomy->hydrate($data);
            $taxonomy->register();
        }
    }


    protected function registerRoutes() {}
	protected function registerActions() {}
	public function adminFooter() {}
	public function initContext() {}


	/**
	 * add network parameters
	 */
	public function wpmuOptions()
	{
		// Remove generated thumbnails option
		$thumbnails = $this->getThumbnails(true);

		if( count($thumbnails) )
		{
			echo '<h2>Images</h2>';
			echo '<table id="thumbnails" class="form-table">
			<tbody><tr>
				<th scope="row">'.__('Generated thumbnails').'</th>
				<td><a class="button button-primary" href="'.get_admin_url().'?clear_all_thumbnails">Remove '.count($thumbnails).' images</a></td>
			</tr>
		</tbody></table>';
		}
	}


	/**
	 * add admin parameters
	 */
	public function adminInit()
	{
		if( isset($_GET['clear_thumbnails']) )
			$this->clearThumbnails();

		if( isset($_GET['clear_all_thumbnails']) )
			$this->clearThumbnails(true);

		$role_object = get_role( 'editor' );

		if( !$role_object->has_cap('edit_theme_options') )
			$role_object->add_cap( 'edit_theme_options' );

		// Remove generated thumbnails option
		add_settings_field('clean_image_thumbnails', __('Generated thumbnails'), function(){

			$thumbnails = $this->getThumbnails();

			if( count($thumbnails) )
				echo '<a class="button button-primary" href="'.get_admin_url().'?clear_thumbnails">'.__('Remove').' '.count($thumbnails).' images</a>';
			else
				echo __('Nothing to remove');

		}, 'media');

	}


	/**
     * Remove all thumbnails
     */
    private function getThumbnails($all=false)
    {
	    $folder = BASE_URI. '/src/WordpressBundle/uploads/';

	    if( is_multisite() && get_current_blog_id() != 1 && !$this->config->get('multisite.shared_media') && !$all )
		    $folder = BASE_URI. '/src/WordpressBundle/uploads/sites/' . get_current_blog_id() . '/';

	    $file_list = [];

    	if( is_dir($folder) )
	    {
		    $dir = new \RecursiveDirectoryIterator($folder);
		    $ite = new \RecursiveIteratorIterator($dir);
		    $files = new \RegexIterator($ite, '/(?!.*150x150).*-[0-9]+x[0-9]+(-c-default|-c-center)?\.[a-z]{3,4}$/', \RegexIterator::GET_MATCH);
		    $file_list = [];

		    foreach($files as $file)
			    $file_list[] = $file[0];
	    }

	   return $file_list;
    }


	/**
     * Remove all thumbnails
     */
    private function clearThumbnails($all=false)
    {
	    if ( current_user_can('administrator') && (!$all || is_super_admin()) )
	    {
		    $thumbnails = $this->getThumbnails($all);

		    foreach($thumbnails as $file)
			    unlink($file);
	    }

	    clearstatcache();

	    wp_redirect( get_admin_url(null, $all?'network/settings.php':'options-media.php') );
    }


	/**
	 * Clean filename
	 */
	function cleanFilename($file) {

		$path = pathinfo($file['name']);
		$new_filename = preg_replace('/.' . $path['extension'] . '$/', '', $file['name']);
		$file['name'] = sanitize_title($new_filename) . '.' . $path['extension'];

		return $file;
	}


	/**
     * Register wp path
     */
    private function definePaths()
    {
        $this->paths = $this->getPaths();
        $this->paths['wp'] = CMS_URI;
    }


    /**
     * Allows user to add specific process on Wordpress functions
     */
    public function registerFilters()
    {
	    if( $this->config->get('multisite.shared_media') and is_multisite() )
		    add_filter( 'upload_dir', [$this, 'uploadDir'], 11 );

	    add_filter('pings_open', '__return_false');
	    add_filter('xmlrpc_enabled', '__return_false');

	    add_filter('posts_request', [$this, 'postsRequest'] );

	    add_filter('woocommerce_template_path', function($array){ return '../../../WoocommerceBundle/'; });
	    add_filter('woocommerce_enqueue_styles', '__return_empty_array' );

        add_filter('acf/settings/save_json', function(){ return $this::$acf_folder; });
        add_filter('acf/settings/load_json', function(){ return [$this::$acf_folder]; });

	    add_filter('timber/post/get_preview/read_more_link', '__return_null' );
        add_filter('wp_calculate_image_srcset_meta', '__return_null');

        // Handle /edition in url
	    add_filter('option_siteurl', [$this, 'optionSiteURL'] );
	    add_filter('network_site_url', [$this, 'networkSiteURL'] );

        if( $jpeg_quality = $this->config->get('jpeg_quality') )
            add_filter( 'jpeg_quality', function() use ($jpeg_quality){ return $jpeg_quality; });
    }


	/**
	 * Create Menu instances from configs
	 * @see Menu
	 */
	public function postsRequest($input)
	{
		if( $this->config->get('debug.show_query'))
			var_dump($input);

		return $input;
	}


	/**
	 * Create Menu instances from configs
	 * @see Menu
	 */
	public function uploadDir($dirs)
	{
		$dirs['baseurl'] = str_replace($dirs['relative'],'/uploads', $dirs['baseurl']);
		$dirs['basedir'] = str_replace($dirs['relative'],'/uploads', $dirs['basedir']);

		$dirs['url']  = str_replace($dirs['relative'],'/uploads', $dirs['url']);
		$dirs['path'] = str_replace($dirs['relative'],'/uploads', $dirs['path']);

		$dirs['relative'] = '/uploads';

		return $dirs;
	}


	/**
	 * Set permalink stucture
	 */
	public function setPermalink()
	{
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure('/%postname%');

		update_option( "rewrite_rules", FALSE );

		$wp_rewrite->flush_rules( true );
	}


	/**
     * Add edition folder to option url
     */
    public function networkSiteURL($url)
    {
	    if( strpos($url,'/edition') === false )
		    return str_replace('/wp-admin', '/edition/wp-admin', $url);
	    else
		    return $url;
    }


    /**
     * Add edition folder to option url
     */
    public function optionSiteURL($url)
    {
        return strpos($url, 'edition') === false ? $url.'/edition' : $url;
    }


    /**
     * Load App configuration
     */
    private function loadConfig()
    {
        $this->config = $this->getConfig('wordpress');

        self::$domain_name = $this->config->get('domain_name', 'customer');
        self::$bo_domain_name = 'bo_'.self::$domain_name;

	    self::$acf_folder = WP_CONTENT_DIR.'/acf-json';
	    self::$languages_folder = WP_CONTENT_DIR . '/languages';
    }


    /**
     * Create Menu instances from configs
     * @see Menu
     */
    public function addMenus()
    {
        foreach ($this->config->get('menus', []) as $slug => $name)
        {
            new Menu($name, $slug);
        }
    }


    /**
     * Init handler
     * @see Menu
     */
    public function init(){}


    /**
     * Define route manager
     * @param $template
     * @param bool $context
     * @return array
     */
    protected function page($template, $context=false)
    {
        return [$template, $context];
    }


	/**
	 * Return json data / Silex compatibility
	 * @param $data
	 * @return bool
	 */
    protected function json($data, $status_code = null)
    {
        wp_send_json($data, $status_code);

        return true;
    }


    /**
     * Add settings to acf
     */
    public function ACFInit()
    {
        acf_update_setting('google_api_key', $this->config->get('options.gmap_api_key', ''));
    }


    /**
     * Register route
     * @param $pattern
     * @param $controller
     * @return Route
     */
    protected function route($pattern, $controller)
    {
        return $this->router->add($pattern, $controller);
    }


	/**
	 * Register route
	 * @param $id
	 * @param $controller
	 * @param bool $no_private
	 */
    protected function action($id, $controller, $no_private=true)
    {
    	if( class_exists( 'WooCommerce' ) )
	    {
		    add_action( 'woocommerce_api_'.$id, $controller );
	    }
	    else
	    {

		    add_action( 'wp_ajax_'.$id, $controller );

		    if( $no_private )
			    add_action( 'wp_ajax_nopriv_'.$id, $controller );
	    }
    }


    /**
     * Define route manager
     * @return bool|mixed
     */
    public function solve()
    {
        return $this->router->solve();
    }


	/**
	 * Define route manager
	 * @param int $code
	 * @return bool|mixed
	 */
    public function getErrorPage($code=404)
    {
        return $this->router->error($code);
    }


    /**
     * Add wordpress configuration 'options_page' fields as ACF Options pages
     */
    protected function addOptionPages()
    {
        if( function_exists('acf_add_options_page') )
        {
            acf_add_options_page();

            foreach ( $this->config->get('options_page', []) as $name )
            {
            	if( isset($name['menu_slug']) )
		            $name['menu_slug'] = 'acf-options-'.$name['menu_slug'];

                acf_add_options_sub_page($name);
            }
        }
    }


    /**
     * Check if ACF and Timber are enabled
     */
    public function pluginsLoaded()
    {
	    new PageTemplater($this->config->get('page_templates', []));

	    $notices = [];

        if ( !class_exists( 'Timber' ) )
            $notices [] = '<div class="error"><p>Timber not activated. Make sure you activate the plugin in <a href="' . esc_url( admin_url( 'plugins.php#timber' ) ) . '">' . esc_url( admin_url( 'plugins.php' ) ) . '</a></p></div>';

        if ( !class_exists( 'acf' ) )
            $notices[] = '<div class="error"><p>Advanced Custom Fields not activated. Make sure you activate the plugin in <a href="' . esc_url( admin_url( 'plugins.php#acf' ) ) . '">' . esc_url( admin_url( 'plugins.php' ) ) . '</a></p></div>';

        if( !empty($notices) )
        {
            add_action( 'admin_notices', function() use($notices)
            {
                echo implode('<br/>', $notices );
            });
        }
    }


	/**
	 * Check symlinks and forders
	 */
	public function adminNotices(){

		$notices = [];

		//check folder wright
		foreach (['src/WordpressBundle/languages', 'src/WordpressBundle/uploads', 'src/WordpressBundle/upgrade'] as $folder ){

			$path = BASE_URI.'/'.$folder;

			if( !file_exists($path) or !is_writable($path) )
				$notices [] = $folder.' folder doesn\'t exist or is not writable';
		}

		if( !empty($notices) )
			echo '<div class="error"><p>'.implode('<br/>', $notices ).'</p></div>';


		$notices = [];

		//check symlink
		foreach (['web/uploads', 'web/plugins', 'web/ajax.php', 'web/static'] as $file ){

			$path = BASE_URI.'/'.$file;

			if( !is_link($path) )
				$notices [] = $file.' is not a valid symlink';
		}

		if( !empty($notices) )
			echo '<div class="error"><p>'.implode('<br/>', $notices ).'</p></div>';
	}


    public function __construct($autoloader=false)
    {
        $this->class_loader = $autoloader;
        $this->global_context = [];

        if( !defined('WPINC') )
            include CMS_URI.'/wp-blog-header.php';
    }
}
