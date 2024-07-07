<?php

/*
 * AssetAuth
 * 
 * For logged in administrators
 * Load asset editing resources on the frontend and admin area (if edit $context is passed into the construct method)
 * This class loads on the admin area even when not editing, so that a response can be given to MT from the admin area too
 */

namespace Microthemer;

class AssetAuth extends AssetLoad {

    use PluginTrait;

    var $builderBlockedEdit = false;
    var $assetLoadingKey = 'asset_loading';
	var $globalStylesheetRequiredKey = "global_stylesheet_required";
	var $globalJSRequiredKey = "load_js";

    function __construct($context){

        $this->context = $context;

		// no need to run MT frontend script on Oxygen intermediate iframe
		// only one the actual site preview
		if (isset($_GET['ct_builder']) && !isset($_GET['oxygen_iframe'])){
			return;
		}

        // run common init with standalone asset loader
		parent::__construct();

        // initialise functionality for administrator
	    $this->initAuth();
	}

    // editing-specific functionality
    function initAuth(){

	    // get the directory paths
	    include dirname(__FILE__) .'/../get-dir-paths.inc.php';

        // setup plugin text domain - not sure if this is needed as JS text strings run on parent window
        // but let's see when reviewing code
        $this->loadTextDomain();

        // determine if we're displaying draft or actively published content
        $this->getFileStub();

		// hook save post
	    $this->hookPostSaved();

        if ($this->isFrontend){
	        $this->hookRedirect();
	        $this->hookNonLoggedIn();
	        $this->hookAdminBarLink();
        }

        // hookJS doesn't run in the admin area so just hook MT JavaScript
        if ($this->isAdminArea){

            // don't show on Divi page - this can cause issues
            $exclude = isset($_GET['et_fb']);

            if (!$exclude){
	            $this->deferHookIfAdmin('current_screen', 'hookMTJS');
            }

        }

        // note, must come after hookMTJS as inline data is attached to the tvr_mcth_frontend handle
	    //$this->deferHookIfAdmin('current_screen', 'hookFrontendData');
    }

    // support viewing the frontend as a logged-out user
	function hookNonLoggedIn(){
		add_action('init',  array(&$this, 'nonLoggedInMode'), $this->defaultActionHookOrder);
	}

    // support redirection
	function hookRedirect(){
		add_action('wp',  array(&$this, 'redirect'), $this->defaultActionHookOrder);
	}

    // Add link to admin bar
    function hookAdminBarLink(){
	    if (!empty($this->preferences['admin_bar_shortcut'])) {
		    add_action( 'admin_bar_menu', array(&$this, 'adminBarLink'), $this->defaultActionHookOrder);
	    }
    }

	// add_action( 'save_post', 'set_private_categories' );
	function hookPostSaved(){
		add_action('save_post', array(&$this, 'postSaved'));
	}

	// add frontend JS data (inc the login page)
	/*function hookFrontendData(){

        $p = &$this->preferences;

		$action_hook = $this->getCSSActionHook($p);

        // determine the action execution order
		$action_order = $this->getCSSActionOrder($p);

		//wp_die('$action_hook: ' . $action_hook);

		// load on login page too
		if ($this->isFrontend){
			add_action( 'login_head', array(&$this, 'addFrontendData'), $action_order);
		}

        // add the frontend data script
        add_action( $action_hook, array(&$this, 'addFrontendData'), $action_order);

	}*/

	// action hook when a post is saved
	// we need to update the theme template map as the arrangement of patterns and template parts may have changed
	function postSaved($post_id){
		Common::maybeUpdateTemplateCache($this->micro_root_dir, null, true);
	}

	function addMTPlaceholder(){

        // this interferes with the logic test by echoing output, and isn't needed then
        if (!isset($_GET['test_logic'])){

            $this->enqueueOrAdd(
		        true,
		        'mt-placeholder', // id must not start with 'microthemer' or it will be removed on browser tab sync
		        '',
		        array(
			        'inline' => true,
			        'code' => '.wp-block {}'
		        )
	        );
        }

    }

	function addMTCSS(){

		// use the $wp_styles->add() method rather than enqueue if order is specified
		// or loading stylesheets in footer which only works if using $wp_styles->add()
		$add = $this->addInsteadOfEnqueue();

		// dev vs production stylesheet
		$min = !TVR_DEV_MODE ? '.min' : '';

		// load file
		$this->enqueueOrAdd(
			$add,
			'microthemer-overlay',
			$this->thispluginurl.'css/frontend'.$min.'.css?v='.$this->version
		);

	}

	function hookMTJS(){

        $action_hook = $this->checkBlockEditorScreen()
	        ? 'enqueue_block_assets'
	        : $this->hooks['enqueue_scripts'];

	   add_action($action_hook, array(&$this, 'addMTJS'), $this->defaultActionHookOrder);
	}

	function addMTJS(){

		$min = !TVR_DEV_MODE ? '-min' : '/page';

		// Common dependencies
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-tooltip');

        // load mt-block.js on block editor pages
        if ($this->isBlockEditorScreen){

            $js_path = 'js' . (TVR_DEV_MODE ? '/mod/' : '-min/') . 'mt-block.js';

            wp_enqueue_script(
		        'tvr_block_classes',
		        $this->thispluginurl . $js_path,
		        array( 'wp-blocks', 'wp-element', 'wp-compose', 'lodash' ),
		        filemtime($this->thisplugindir . $js_path),
		        false // Set it to true if you want it to be loaded in the footer
	        );
        }

		// MT preview script
		// For editing styles on the frontend and the admin area
		// But also for the Microthemer interface to receive a response from the admin area without editing
		// e.g. Frontend loaded, Folder loading config
		wp_register_script(
			'tvr_mcth_frontend',
			$this->thispluginurl.'js'.$min.'/frontend.js?v='.$this->version,
			array('jquery', 'jquery-ui-tooltip')
		);

		wp_enqueue_script( 'tvr_mcth_frontend');

		// the previous system of hooking this separately did not work on the wp-login.php page
		// And I think it added unnecessary complexity too
		$this->addFrontendData();
	}

	function addFrontendData($returnData = false) {

		if ( is_user_logged_in() || isset($_GET['mt_nonlog']) ) {

			global $wp_version;
			$p = &$this->preferences;
            $min = !TVR_DEV_MODE ? '-min' : '/mod';
			$asset_loading = !empty($p[$this->assetLoadingKey])
				? $p[$this->assetLoadingKey]
				: array();

			// ensure that folderLoading config has been set
			// it won't be if stylesheet_order has a value
			if (!$this->folderLoadingChecked && isset($asset_loading['logic']) && count($asset_loading['logic'])){
				$this->conditionalAssets($asset_loading['logic'], true);
			}

            // Get the folder loading status of any draft folder too
			$eligibleForLoading = !$this->isAdminArea || $this->supportAdminAssets();
			$draftFolder = isset($_COOKIE['microthemer_draft_folder'])
				? json_decode(stripslashes($_COOKIE['microthemer_draft_folder']), true)
				: false;

            // if we need to test draft folder logic that hasn't been saved
			if ($draftFolder && $eligibleForLoading){
				$logic = new Logic($this->logicSettings);
				$this->folderLoading[$draftFolder['slug']] = $logic->result($draftFolder['expr'])
                    ? 'empty'
                    : 0;
			}

			$MTDynFrontData = array_merge(
				array(
                    'draftFolder' => $draftFolder,
					'iframe-url' => rawurlencode(
						esc_url(
							Common::strip_page_builder_and_other_params($this->currentPageURL()),
							null,
							'read'
						)
					),
					'mt-show-admin-bar' => !empty($p['admin_bar_preview'])
						? intval($p['admin_bar_preview'])
						: 1,

					// note: folderLoading may need to hook this data to wp_footer if stylesheet_in_footer
					'folderLoading' => $this->folderLoading,
					'assetLoadingLogic' => !empty($p['asset_loading']['logic'])
						? $p['asset_loading']['logic']
						: array(),
					'builderBlockedEdit' => $this->builderBlockedEdit,
					'broadcast' => !empty($p['sync_browser_tabs'])
						? $this->thispluginurl . 'js'.$min.'/mt-broadcast.js?v='.$this->version
						: false,
					'isAdminArea' => $this->isAdminArea,

                    'add_block_classes_all' => !empty($p['add_block_classes_all']),

					// Flag to the frontend script that asset editing is / isn't supported
					'interactions' => $this->context === 'edit',

					// flag if bricks builder is active
					'bricksBuilderActive' => $this->isBricksUi(),

					// WordPress info
                    'wp_version' => $wp_version,
                    'theme' => get_stylesheet(),
                    'template' => Helper::getCurrentTemplateSlug(),
					'home_url' => $this->home_url

				),
                $this->pageMeta()
            );

			// get Oxygen page width
			if ( function_exists('oxygen_vsb_get_page_width') ){

				$MTDynFrontData['oxygen'] = array(
					'page-width' => intval( oxygen_vsb_get_page_width() )
				);
			}

			wp_add_inline_script(
				'tvr_mcth_frontend',
				'window.MTDynFrontData = '. json_encode( $MTDynFrontData ) .';',
				'before'
			);

			if ($returnData){
				return $MTDynFrontData;
			}

			//wp_die('$returnData: <pre>'.print_r(['$MTDynFrontData' => wp_scripts()], 1).'</pre>' );

		}
	}

    // return logic for targeting the current page or type of page
    function pageMeta(){

	    global $wp_query, $post, $pagenow;

	    //$header_template = get_post_meta( $post->ID);

	    //wp_die('<pre>The $wp_query'.print_r($header_template, true).'</pre>');

	    $logic = null;
        $alt = array();
        $title = null;
        $type = null;
        $id = null;
        $post_id = 0;
	    $isFSE = $pagenow === 'site-editor.php';
	    $permalink = home_url(); // default to the home page
	    $post_title = '';
        $slug = null;
	    $isAdminPostPageEdit = ($this->isAdminArea && !empty($_GET['post']) && isset($post->post_name));
        $screen = $this->isAdminArea && function_exists('get_current_screen')
            ? get_current_screen()
            : null;
        $adminPrefix = esc_html__('Admin - ', 'microthemer');
	    $blockPrefix = '';
        $isBlockEditorScreen = $this->isBlockEditorScreen;
	    //wp_die('<pre>$post: '.print_r($post, 1).' </pre>');
	    //wp_die('<pre>$current_screen: '.print_r($screen, 1).' </pre>');

        // single post/page on frontend or admin side
	    if ( $wp_query->is_page || $wp_query->is_single || $isAdminPostPageEdit ) {
		    $post_id = $id = $post->ID;
            $slug = $post->post_name ?: $post_id; // with certain previews the slug might not be set so default to the id
		    $type = $wp_query->is_page ? 'page' : 'single';
		    $blockPrefix = esc_html__('Blocks - ', 'microthemer');
		    $post_title = $title = ($this->isAdminArea ? $adminPrefix : '') .  $post->post_title;

            // general page logic
            $pageLogic = $isAdminPostPageEdit
                ? '\Microthemer\is_public_or_admin("'.$slug.'")'
                :  '\Microthemer\is_post_or_page('.$post_id.', "'.$post->post_title.'")'; // 'is_'.$type.'("'.$slug.'")';

		    $logic = $pageLogic;

            // more alt logic for admin side
            if ($isAdminPostPageEdit && $screen){
	            $alt[] = array(
                        'logic' => '\Microthemer\query_admin_screen("base", "'.$screen->base.'")',
                        'title' => $adminPrefix . ucwords($screen->base)
                );
            }

            // Save the permalink, for turning off Gutenberg
		    $permalink = get_permalink($post_id);

		    //wp_die('<pre>$current_screen: '.print_r($screen, 1).' </pre>');
	    }

        // todo has_template() when using site editor templates, patterns, template parts,

        // any other admin screen that isn't edit post/page
        elseif ($this->isAdminArea && $screen) {

            // Add Post/Page - need to convert the folder to page-specific if editor content items exist inside
            // So flag with identifiable logic
            if ($screen->action === 'add' && $screen->base === 'post'){
	            $logic = '\Microthemer\query_admin_screen("action", "add") && \Microthemer\query_admin_screen("base", "post")';
	            $title = $adminPrefix . $screen->action . ' ' . $screen->base;
            }

            else {
	            $logic = '\Microthemer\query_admin_screen("base", "'.$screen->base.'")';
	            $title = $adminPrefix . $screen->base;
            }

            if ($screen->parent_base){
	            $alt[] = array(
		            'logic' => '\Microthemer\query_admin_screen("parent_base", "'.$screen->parent_base.'")',
		            'title' => $adminPrefix . ucwords($screen->parent_base)
	            );
            }

			// It's a full site editor page, see if we can get a link for pages
	        if ($isFSE){
				$permalink = Helper::getFSEPermalink($permalink);
				//wp_die('<pre>$permalink: '  . print_r([$permalink], true) . '</pre>');
	        }

		    //wp_die('<pre>$current_screen: '.print_r($screen, 1).' </pre>');
	    } elseif ( $wp_query->is_home ) {
		    $logic = 'is_home()';
		    $title = esc_html__('Blog home', 'microthemer');
		    $type = 'blog-home';
	    } elseif ( $wp_query->is_category ) {
		    $id = $wp_query->query_vars['cat'];
		    $slug = $wp_query->query['category_name'];
		    $logic = 'is_category("'.$slug.'")';
		    $title = esc_html__('Category archive: ', 'microthemer') . $wp_query->queried_object->name;
            $type = 'category';
	    } elseif ( $wp_query->is_tag ) {
		    $id = $wp_query->query_vars['tag'];
		    $slug = $wp_query->query['tag_name'];
		    $logic = 'is_tag()';
		    $title = esc_html__('Tag archive', 'microthemer');
		    $type = 'tag';
	    } elseif ( $wp_query->is_author ) {
		    $id = $wp_query->query_vars['author'];
		    $slug = $wp_query->query['author_name'];
		    $logic = 'is_author()';
		    $title = esc_html__('Author archive', 'microthemer');
		    $type = 'author';
	    } elseif ( $wp_query->is_archive ) {
		    $logic = 'is_archive()';
		    $title = esc_html__('Archive', 'microthemer');
		    $type = 'archive';
	    } elseif ( $wp_query->is_search ) {
		    $logic = 'is_search()';
		    $title = esc_html__('Search results', 'microthemer');
		    $type = 'search';
	    } elseif ( $wp_query->is_404 ) {
		    $logic = 'is_404()';
		    $title = esc_html__('404 page', 'microthemer');
		    $type = '404';
	    }

	    // Complete the 3 levels of logic when editing the admin area
        // 1 = most specific e.g. single page or current screen
        // 2 = base or parent base
        // 3 = is_admin()
        if ($this->isAdminArea){
            $alt[] = array(
	            'logic' => 'is_admin()',
	            'title' => esc_html__('Admin area', 'microthemer')
            );
        }

        $min = !TVR_DEV_MODE ? '-min' : '/page';



	    return array(
		    'post_id' => $post_id,
		    'post_title' => $post_title,
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'logic' => $logic,
            'alt_logic' => $alt,
            'type' => $type,
            'isBlockEditorScreen' => $isBlockEditorScreen,
            'adminPrefix' => $adminPrefix,
            'blockPrefix' => $blockPrefix,
            'permalink' => $permalink,
            'pagenow' => $pagenow,
		    'jQueryScript' => includes_url().'js/jquery/jquery.min.js?v='.$this->version,
            'MTFscript' => $this->thispluginurl.'js'.$min.'/frontend.js?v='.$this->version
        );



    }

	function adminBarLink($wp_admin_bar) {

        if (!current_user_can('administrator')){
            return false;
        }

        $parent = !empty($this->preferences['top_level_shortcut']) ? false : 'site-name';
        $currentPageURL = Common::strip_page_builder_and_other_params($this->currentPageURL());
        $post = $this->pageMeta(); //$this->getCurrentPostData();

        // format URL
        $href = $this->wp_blog_admin_url . 'admin.php?page=' . $this->microthemeruipage .
                '&mt_preview_url=' . rawurlencode(esc_url($currentPageURL))
                . '&mt_item_id=' . rawurlencode($post['post_id'])
                . '&mt_path_label=' . rawurlencode($post['post_title'])
                . '&_wpnonce=' . wp_create_nonce( 'mt-preview-nonce' );

        // add menu item
        $wp_admin_bar->add_node(array(
            'id' => 'wp-mcr-shortcut',
            'title' => 'Microthemer',
            'parent' => $parent,
            'href' => $href,
            'meta' => array(
                'class' => 'wp-mcr-shortcut',
                'title' => __('Edit with Microthemer', 'microthemer')
            )
        ));
	}

	function getFileStub(){

        $user_id = get_current_user_id();

		if (!empty($this->preferences['draft_mode'])
            && !empty($this->preferences['draft_mode_uids'])
		    && in_array($user_id, $this->preferences['draft_mode_uids'])
        ) {
			$this->fileStub = 'draft';
        }
	}

	// perform redirect for e.g. Oxygen edit URL params for the current post
	// this is more performant than getting the edit links in the quick edit menu
	function redirect(){

		// redirect to Oxygen edit page
		if ( isset($_GET['mto2_edit_link']) && function_exists('oxygen_add_posts_quick_action_link') ){

			$nonce = !empty($_GET['_wpnonce']) ? $_GET['_wpnonce'] : false;

			if (current_user_can("administrator") && wp_verify_nonce( $nonce, 'mt_builder_redirect_check' )) {

				global $post;

				// try to get link
				$edit_link = \oxygen_add_posts_quick_action_link(array(), $post);

				// we have a valid URL
				if (!empty($edit_link['oxy_edit'])){

					preg_match('/href="(.+?)"/', $edit_link['oxy_edit'], $matches);

					if (!empty($matches[1])){

						$edit_url = $matches[1];

						wp_redirect( esc_url($edit_url) );
					}
				}

				else {

					$reason = 'unknown';

					// warn that oxygen did not allow edit screen
					if (!oxygen_vsb_current_user_can_access()) {
						$reason = 'user-privileges';
					} if (get_option("oxygen_vsb_ignore_post_type_{$post->post_type}") == 'true') {
						$reason = 'post-type';
					} if (is_oxygen_edit_post_locked()) {
						$reason = 'edit-lock';
					}

					$this->builderBlockedEdit = array(
						'builder' => 'oxygen',
						'reason' => $reason
					);
				}
			}

            else {
				die('Permission denied');
			}
		}
	}

	function nonLoggedInMode(){

        if (isset($_GET['mt_nonlog'])) {

			$nonce = !empty($_GET['_wpnonce']) ? $_GET['_wpnonce'] : false;

            if (current_user_can("administrator") and wp_verify_nonce( $nonce, 'mt_nonlog_check' ) ) {
				wp_set_current_user(-1);
			} else {
				die('Permission denied');
			}
		}
	}

	function getCacheParam(){
		return 'nomtcache=' . time();
	}

	// get the current page for iframe-meta and loading WP page after clicking WP admin MT option
	function currentPageURL() {
		return Common::get_protocol() . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	function getCurrentPostData($id = 0){

		$post = get_post($id);

		return array(
			'post_title' => isset( $post->post_title ) ? $post->post_title : '',
			'post_id' => isset( $post->ID ) ? $post->ID : 0
		);
	}

    // we support the logic test if this file (for administrators only) is in use and GET param is set
	function supportLogicTest(){
		return isset($_GET['test_logic']);
	}

	function doLogicTest($folders, $logic, $forceAll = false){

		$testFolder = isset($_GET['test_logic'])
			? $_GET['test_logic']
			: null;
		$testAll = isset($_GET['test_all']) || $forceAll;
		$getStylesheets = isset($_GET['get_simple_stylesheets']);
		$stylesheets = '';
		$getFrontData = isset($_GET['get_front_data']);
        $defaultResponse = array(
	        'result' => 1,
	        'resultString' => 'true',
	        'logic' => 'Not set',
	        'analysis' => 'No logic has been defined, so this folder will load globally (on the frontend)',
	        'num_statements' => 0,
	        'load' => 'Yes'
        );
        $adminSupportedResponse = array_merge($defaultResponse, array(
	        'result' => 0,
	        'resultString' => 'false',
	        'load' => 'No',
	        'analysis' => 'No logic has been defined, so this folder will load on the frontend only',
        ));
		$adminUnsupportedResponse = array_merge($adminSupportedResponse, array(
			'analysis' => 'CSS in the admin area has not been enabled, optionally do this via Settings > Preferences.',
		));

		// if Microthemer has provided live gutenberg HTML with new template-parts/patterns/navigation
		// we need to update the map and possibly the post_content
		if (Helper::isLiveContentTest()){
			Common::updateLiveTemplateData($this->micro_root_dir);
		}

        // set default evaluation response
		$evaluation = $this->isAdminArea
            ? (
			    $this->supportAdminAssets()
                    ? $adminSupportedResponse
                    : $adminUnsupportedResponse
			)
            : $defaultResponse;

        $eligibleForLoading = !$this->isAdminArea || $this->supportAdminAssets();

        // 
		foreach ($folders as $folder){

			$slug = $folder['slug'];
			$file_exists = file_exists($this->rootDir . 'mt/conditional/draft/' . $slug . '.css');

			// if a condition has been set
			if (isset($folder['expr'])){

				// log all folders that load on the current page
				if ($testAll){
					$result = $logic->result($folder['expr']);
					$this->folderLoading[$slug] = $eligibleForLoading && $result
                        ? ($file_exists
							? (is_string($result) ? $result : 1) // preserve string result like 'blocksOnly'
							: 'empty')
                        : 0;

					// For FSE page changes (which only replaces inner content) we need to replace MT assets
					if ($getStylesheets && $file_exists && $this->folderLoading[$slug]){
						$stylesheets.= '<link rel="stylesheet" href="'.$this->micro_root_url.'mt/conditional/draft/'.$slug.'.css?'.$this->cacheParam.'" id="microthemer-'.$slug.'-css">' . "\n";
					}
				}

				// test a single folder, and provide debug info
				else {

					// bail if we have the result for a test folder
					if ($eligibleForLoading && $testFolder === $folder['slug']){

						$evaluation = $logic->result(
							$folder['expr'],
							true,
							$file_exists
						);

						break;
					}
				}
			}

		}

		$dataToReturn = $testAll
			? ($getFrontData
				? $this->addFrontendData(true)
				: ($getStylesheets
					// as array (not a string), so we can tease apart from any leading HTML before the json
					? array('stylesheets' => $stylesheets)
					: $this->folderLoading)
			)
			: $evaluation;

		//$dataToReturn['debugOutput'] = Helper::$debugOutput;
		//wp_die('Test all <pre>' . print_r($this->folderLoading, 1) . '</pre>');
		//echo 'Helper::$debugOutput <pre>' . Helper::$debugOutput . '</pre>';

		// return test folder result - unless we are just running this to set folderLoading
		if (!$forceAll){
			$this->testResultResponse($dataToReturn);
		}

	}

	function testResultResponse($testEvaluation){
		ob_clean();
		header('Content-type: application/json');
		http_response_code(200);
		echo json_encode($testEvaluation);
		exit;
	}

}

