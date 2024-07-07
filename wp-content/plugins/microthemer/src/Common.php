<?php

namespace Microthemer;

class Common {

	public static $live_post_content = '';
	public static $live_template = false;

	public static function get_protocol() {
		$isSSL = is_ssl(); // (!empty($_SERVER["HTTPS"]) and $_SERVER["HTTPS"] == "on");
		return 'http' . ($isSSL ? 's' : '') . '://';
	}

	public static function get_custom_code() {
		return array(
			'hand_coded_css' => array (
				'tab-key' => 'all-browsers',
				'label' => esc_html__('CSS', 'microthemer'),
				//'label' => esc_html__('CSS', 'microthemer'),
				'type' => 'css'
			),
			'js' => array (
				'tab-key' => 'js',
				'label' => esc_html__('JS', 'microthemer'),
				'type' => 'javascript'
			),
		);
	}

	// add a param to an existing url if it doesn't exist, using the correct joining char
	public static function append_url_param($url, $param, $val = false){

		// bail if already present
		if (strpos($url, $param) !== false){
			return $url;
		}

		// we do need to add param, so determine joiner
		$joiner = strpos($url, '?') !== false ? '&': '?';

		// is there param val?
		$param = $val ? $param.'='.$val : $param;

		// return new url
		return $url . $joiner . $param;

	}

	// strip a single parameter from an url (adapted from JS function)
	public static function strip_url_param($url, $param, $withVal = true){

		$param = $withVal ? $param . '(?:=[a-z0-9]+)?' : $param;
		$pattern = '/(?:&|\?)' . $param . '/';
		$url = preg_replace($pattern, '', $url);

		// check we don't have an any params that start with & instead of ?
		if (strpos($url, '&') !== false && strpos($url, '?') === false){
			preg_replace('/&/', '?', $url, 1); // just replaces the first instance of & with ?
		}

		return $url;
	}

	// &preview= and ?preview= cause problems - strip everything after (more heavy handed than above function)
	public static function strip_preview_params($url){
		//$url = explode('preview=', $url); // which didn't support regex (for e.g. elementor)
		$url = preg_split('/(?:elementor-)?preview=/', $url, -1);
		$url = rtrim($url[0], '?&');
		return $url;
	}

	public static function params_to_strip(){
		return array(

			// wordpress params
			array(
				'param' => '_wpnonce',
				'withVal' => true,
			),
			array(
				'param' => 'ver',
				'withVal' => true,
			),

			array(
				'param' => 'mt_nonlog',
				'withVal' => false,
			),
			array(
				'param' => 'mto2_edit_link',
				'withVal' => true,
			),
			array(
				'param' => 'elementor-preview',
				'withVal' => true,
			),
			array(
				'param' => 'brizy-edit-iframe', // strip brizy
				'withVal' => false,
			),
			array(
				'param' => 'et_fb', // strip Divi param which causes iframe to break out of parent
				'withVal' => true,
			),
			array(
				'param' => 'fl_builder', // strip beaver builder
				'withVal' => false,
			),
			// oxygen params
			array(
				'param' => 'ct_builder',
				'withVal' => true,
				'unless' => array('ct_template') // ct_template also requires ct_builder to work
			),
			array(
				'param' => 'ct_inner',
				'withVal' => true,
			),
			/* Keep as necessary for showing specific content
			 * array(
				'param' => 'ct_template',
				'withVal' => true,
			),*/
			array(
				'param' => 'oxygen_iframe',
				'withVal' => true,
			),


			// MT cache parameter: nomtcache
			array(
				'param' => 'nomtcache',
				'withVal' => true,
			),

			// MT logic parameters: test_logic, test_all, get_simple_stylesheets, get_front_data
			array(
				'param' => 'test_logic',
				'withVal' => true,
			),
			array(
				'param' => 'test_all',
				'withVal' => true,
			),
			array(
				'param' => 'get_simple_stylesheets',
				'withVal' => true,
			),
			array(
				'param' => 'get_front_data',
				'withVal' => true,
			),

			// elementor doesn't pass a parameter to the frontend it runs on the admin side

		);
	}

	// we don't strip params that are required when another param is present
	public static function has_excluded_param($url, $array){

		$unless = !empty($array['unless']) ? $array['unless'] : false;
		if ($unless){
			foreach ($unless as $i => $excl){
				if (strpos($url, $excl) !== false){
					return true;
				}
			}
		}

		return false;
	}

	// strip preview= and page builder parameters
	public static function strip_page_builder_and_other_params($url, $strip_preview = true){

		// strip preview params (regular and elementor)
		//$url = Common::strip_preview_params($url); // test what happens

		$other_params = Common::params_to_strip();

		foreach ($other_params as $key => $array){

			// we don't strip params that are required when another param is present
			if (Common::has_excluded_param($url, $array)){
				continue;
			}

			$url = Common::strip_url_param($url, $array['param'], $array['withVal']);
		}

		// strip brizy
		/*$url = Common::strip_url_param($url, 'brizy-edit-iframe', false);

		// strip Divi param which causes iframe to break out of parent
		$url = Common::strip_url_param($url, 'et_fb', true); // this has issue with divi builder

		// strip beaver builder - NO, we're currently checking fl_builder for JS logic.
		$url = Common::strip_url_param($url, 'fl_builder', false);*/

		return $url;

	}

	// we are adding user google fonts on admin side too so they can be shown in UI (todo)
	public static function add_user_google_fonts($p){

		// use g_url_with_subsets value generated when writing stylesheet
		$google_url = !empty($p['g_url_with_subsets'])
			? $p['g_url_with_subsets']

			// fallback to g_url if user has yet to save settings since g_url_with_subsets was added
			: (!empty($p['gfont_subset']) ? $p['g_url'].$p['gfont_subset'] : $p['g_url']);

		if (!empty($google_url)){
			Common::mt_enqueue_or_add(!empty($p['stylesheet_order']), 'microthemer_g_font', $google_url);
		}

	}

	public static function mt_enqueue_or_add($add, $handle, $url, $in_footer = false, $data_key = false, $data_val = false){

		global $wp_styles;

		// special case for loading CSS after Oxygen
		if ($add){

			$wp_styles->add($handle, $url);
			$wp_styles->enqueue(array($handle));

			if ($data_key){
				$wp_styles->add_data($handle, $data_key, $data_val);
			}

			// allow CSS to load in footer if O2 is active so MT comes after O2 even when O2 active without O2
			// Note this didn't work on my local install, but did on a customer who reported issue with Agency Tools
			// so better to use a more deliberate action hook e.g. wp_footer
			// Ideally, O2 would enqueue a placeholder stylesheet and replace rather than append to head
			/*if ( !defined( 'SHOW_CT_BUILDER' ) ) {
				$wp_styles->do_items($handle);
			}*/

			// (feels a bit risky, but can add if MT loading before O2 when active by itself causes issue for people)
			$wp_styles->do_items($handle);
		}

		else {
			wp_register_style($handle, $url, false);
			wp_enqueue_style($handle);
		}

	}

	// dequeue rougue styles or scripts loading on MT UI page that cause issues for it
	public static function dequeue_rogue_assets(){

		$conflict_styles = array(

			// admin 2020 plugin assets
			'uikitcss',
			'ma_admin_head_css',
			'ma_admin_editor_css',
			'ma_admin_menu_css',
			'ma_admin_mobile_css',
			'custom_wp_admin_css',
			'ma_admin_media_css',

			// for UIPress
			'uip-app',
			'uip-app-rtl',
			'uip-icons',
			'uip-font',

			// TK shortcodes
			'tk-shortcodes',
			'tk-shortcodes-fa'


		);

		// for UIPress
		/*if ( class_exists('uipress') ){
			$conflict_styles = array_merge($conflict_styles, array(

			));
		}*/

		foreach ($conflict_styles as $style_handle){
			wp_dequeue_style($style_handle);
		}
	}

	// get the template file WordPress is loading e.g. page, single, single-with-sidebar
	/*public static function getCurrentTemplateSlug(){

		global $post;

		$slug = get_page_template_slug();

		// get_page_template_slug sets an empty string for single and page, normalise if so
		if ($slug === '' && !empty($post->post_type)){
			return $post->post_type === 'post' && is_single()
						? 'single'
						: $post->post_type;
		}

		return $slug;
	}*/



	/////////// GUTENBERG BLOCK FUNCTIONS //////////////////
	///
	/// the save_post action hook runs on the frontend surprisingly,
	/// so we need these functions available to both main classes

	// In order to efficiently check if a particular template is loading in a Block theme,
	// Microthemer creates a map of templates and the sub-templates they use
	// This map will be added if it doesn't exist for the current theme, or a preference to update it is set
	public static function maybeUpdateTemplateCache($micro_root_dir, $themeSlug = null, $force = false){

		// bail if it's not a block theme
		if (!wp_is_block_theme()){
			return false;
		}

		// default to the current theme
		$themeSlug = $themeSlug ?: get_stylesheet();
		$templateMapFile = $micro_root_dir . 'mt/cache/themes/'.$themeSlug.'/'.Helper::templateMapName().'.json';

		// bail if the file already exists and if we're not forcing a refresh
		if (!$force && file_exists($templateMapFile)){
			return false;
		}

		// get the default / user-customised templates/parts array from the database
		$resolvedTemplates = get_block_templates();
		$resolvedTemplateParts = get_block_templates(array(), 'wp_template_part');
		$registeredPatterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();
		$block_query = new \WP_Query(array(
			'post_type' => array('wp_block', 'wp_navigation'),
			'posts_per_page' => -1
		));
		$allPatterns = array_merge($registeredPatterns, $block_query->posts);

		// loop each template and parse the blocks
		$map = array();

		// templates
		foreach ($resolvedTemplates as $template){
			Common::updateTemplateMap($template, $map, 'wp_template', $themeSlug);
		}

		// template parts
		foreach ($resolvedTemplateParts as $templatePart){
			Common::updateTemplateMap($templatePart, $map, 'wp_template_part', $themeSlug);
		}

		// template file patterns and DB patterns/navigation stored in the DB
		foreach ($allPatterns as $pattern){

			$item = (object) $pattern;

			$type = !isset($item->post_type) || $item->post_type === 'wp_block'
				? 'wp_pattern'
				: $item->post_type; // e.g. wp_navigation

			Common::updateTemplateMap($item, $map, $type, $themeSlug);
		}

		// write to a json file
		$write_file = @fopen($templateMapFile, 'w');
		fwrite($write_file, json_encode($map));
		fclose($write_file);

		/*wp_die('<pre>'.print_r([
			'map' => $map,
			//'reg_patterns' => $registeredPatterns,
			//'all_patterns' => $allPatterns,
			], true).'</pre>');*/

	}

	public static function updateLiveTemplateData($micro_root_dir){

		Common::$live_post_content = stripslashes($_POST['live_post_content']);
		Common::$live_template = stripslashes($_POST['live_template']);

		Helper::debug('updateLiveTemplateData');

		// update the live map
		Common::maybeUpdateTemplateCache($micro_root_dir, null, true);
	}

	// update a single item in the templateMap
	/*public static function updateTemplateMapItem($map){

	}*/

	// mark
	public static function updateTemplateMap($item, &$map, $type = 'wp_template', $themeSlug = ''){

		$isDBPost = isset($item->post_content);
		$slug = $isDBPost
			? $item->ID // user pattern/navigation
			: (isset($item->slug)
				? $item->slug // template
				: $item->name); // theme pattern
		$slug = Helper::removeRedundantThemePrefix($slug, $themeSlug);
		$content = $isDBPost ? $item->post_content : $item->content;

		// use live data in place of DB stored data if we're performing a test of client-side Gutenberg content
		extract(Helper::extractUrlParams($themeSlug));
		if (Helper::isLiveContentTest() && $fseType === $type && $postId == $slug){
			$content = Common::$live_post_content;
			Helper::debug('Live content used to update the live map ('.$fseType.'): ' . $postId);
		}

		$store = array(
			'wp_template_part' => array(),
			'wp_pattern' => array(),
			'wp_navigation' => array(),
		);
		$entry = array();

		// extract template parts and patterns from the content
		$blocks = parse_blocks($content);
		Common::extractTemplatePartsPatterns($blocks, $store, $themeSlug);

		if (!empty($item->area)){
			$entry['area'] = $item->area;
		} if (count($store['wp_template_part'])){
			$entry['wp_template_part'] = $store['wp_template_part'];
		} if (count($store['wp_pattern'])){
			$entry['wp_pattern'] = $store['wp_pattern'];
		} if (count($store['wp_navigation'])){
			$entry['wp_navigation'] = $store['wp_navigation'];
		}

		// debug
		//$entry['content'] = esc_html($item->content);
		//$entry['blocks'] = $blocks;

		$map[$type][$slug] = count($entry) ? $entry : 1;

	}

	public static function extractTemplatePartsPatterns($blocks, &$store, $themeSlug){

		foreach ($blocks as $block){

			$name = $block['blockName'];
			$ref = isset($block['attrs']['ref']) ? $block['attrs']['ref'] : null;

			// template part
			if ($name === 'core/template-part'){
				$key = Helper::removeRedundantThemePrefix($block['attrs']['slug'], $themeSlug);
				$store['wp_template_part'][$key] = 1;
			}

			// pattern defined in the theme/patterns directory or a user-defined pattern/navigation
			elseif ($name === 'core/pattern' || $ref){
				$storeKey = $name === 'core/navigation'
					? 'wp_navigation'
					: 'wp_pattern';
				$key = isset($block['attrs']['slug'])
					? Helper::removeRedundantThemePrefix($block['attrs']['slug'], $themeSlug)
					: Helper::maybeMakeNumber($ref);

				$store[$storeKey][$key] = 1;
			}

			// recurse
			if (count($block['innerBlocks'])){
				Common::extractTemplatePartsPatterns($block['innerBlocks'], $store, $themeSlug);
			}
		}
	}



}
