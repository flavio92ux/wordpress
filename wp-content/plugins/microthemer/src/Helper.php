<?php

namespace Microthemer;

/*
 * Helper
 *
 * A collection of static helper functions that are used in the admin, frontend, logic (inc stand alone)
 */

class Helper {

	public static $debug = false;
	public static $debugOutput = '';

	public static function debug($message, $data = false, $die = false){

		if (Helper::$debug){

			$output = $message;

			if ($data){
				$output.= ':<pre>' . print_r($data, 1) . '</pre>';
			}

			if (!$data){
				$output.= '<br /><br />';
			}

			if ($die){
				wp_die($output);
			} else {
				if (Helper::$debug !== 'silent'){
					echo $output;
				}
				//Helper::$debugOutput.= $output;
			}
		}

	}

	public static function isBlockAdminPage($id = null){

		global $pagenow;
		$postId = null;
		$isBlockAdmin = false;

		if (!is_admin()){
			return false;
		}

		if ($pagenow === 'post-new.php'){
			$postId = -1;
			$isBlockAdmin = true;
		} elseif ($pagenow === 'post.php' && isset($_GET['post'])){
			$postId = $_GET['post'];
			$isBlockAdmin = true;
		} elseif ($pagenow === 'site-editor.php' && isset($_GET['postId'])){
			$postId = $_GET['postId'];
			$isBlockAdmin = true;
		}

		return $isBlockAdmin && ($id === 'global' || $id == $postId);
	}

	// get the template file WordPress is loading e.g. page, single, single-with-sidebar
	// this is duplicate code of method with same name in Common class, which may not be present
	public static function getCurrentTemplateSlug(){

		return !empty(Logic::$cache['settings']['currentTemplate'])
			? Logic::$cache['settings']['currentTemplate']
			: '';

	}

	// determine what type of FSE page we're editing
	public static function getFSEType($postType, $categoryType){
		return $postType === 'wp_block' || $categoryType === 'pattern'
			? 'wp_pattern'
			: $postType;
	}

	// determine what type of FSE page we're editing
	public static function getTemplateFromPostId($postId, $post){

		if (Common::$live_template !== false){
			$currentTemplate = Common::$live_template;
			//echo 'Retrieved from Common::$live_template: ' . $currentTemplate . '<br>';
		} else {
			$currentTemplate = get_post_meta($postId, '_wp_page_template', true);
			//echo 'Retrieved from get_post_meta: ' . $currentTemplate . '<br>';
		}

		if (!$currentTemplate){
			$currentTemplate = $post
				? ($post->post_type === 'post'
					? 'single'
					: $post->post_type)
				: 'page';
		}
		return $currentTemplate;
	}

	// if it's a front page fallback on the FSE admin page
	public static function isFrontOrFallback($orDirectView = true){

		global $pagenow;

		$fse = $pagenow === 'site-editor.php';
		$regular = $pagenow === 'post.php';
		$post = isset($_GET['post']) ? $_GET['post'] : null;
		$postId = isset($_GET['postId']) ? $_GET['postId'] : null;
		$homePage = Helper::homePageData();
		$fseFallback = $fse && $postId === null;
		$fseDirect = $fse && $postId === $homePage['id'];
		$regularDirect = $regular && $post === $homePage['id'];
		$frontDirect = !is_admin() && (
			is_front_page() || ( $homePage['id'] > 0 && is_page($homePage['id']) )
		);

		return $fseFallback || ($orDirectView && ($fseDirect || $regularDirect || $frontDirect));
	}

	public static function getMicroRoot($wp_content_dir, $wp_content_url){
		
		global $blog_id;
		
		if (is_multisite()) {

			$filename = $wp_content_dir . "/blogs.dir/";

			if (file_exists($filename)){
				if ($blog_id == '1') {
					$dir = $wp_content_dir . '/blogs.dir/micro-themes/';
					$url = $wp_content_url . '/blogs.dir/micro-themes/';
				} else {
					$dir = $wp_content_dir . '/blogs.dir/' . $blog_id . '/micro-themes/';
					$url = $wp_content_url . '/blogs.dir/' . $blog_id . '/micro-themes/';
				}
			} else {
				if ($blog_id == '1') {
					$dir = $wp_content_dir . '/uploads/sites/micro-themes/';
					$url = $wp_content_url . '/uploads/sites/micro-themes/';
				} else {
					$dir = $wp_content_dir . '/uploads/sites/' . $blog_id . '/micro-themes/';
					$url = $wp_content_url . '/uploads/sites/' . $blog_id . '/micro-themes/';
				}
			}
		} else {
			$dir = $wp_content_dir . '/micro-themes/';
			$url = $wp_content_url . '/micro-themes/';
		}

		return array(
			'dir' => $dir,
			'url' => $url,
		);
	}

	public static function getTemplateMap($themeSlug = '', &$cache = array()){

		$themeSlug = $themeSlug ?: get_stylesheet();

		// return cache if available
		if (isset($cache['templateMap'][$themeSlug])){
			return $cache['templateMap'][$themeSlug];
		}

		// else read the file
		$map = null;
		$dir = Helper::getMicroRoot(wp_normalize_path(WP_CONTENT_DIR), content_url())['dir'];
		$templateMapFile = $dir . 'mt/cache/themes/'.$themeSlug.'/'.Helper::templateMapName().'.json';

		if (file_exists($templateMapFile)){

			$mapString = file_get_contents($templateMapFile);

			if ($mapString){
				$map = json_decode($mapString, true);
			}
		}

		// cache to save reading the file again
		$cache['templateMap'][$themeSlug] = $map;

		return $map;
	}

	public static function isLiveContentTest(){
		return isset($_POST['live_post_content']);
	}

	public static function getFSEPermalink($permalink){

		$themeSlug = get_stylesheet();

		// Get parameters in case we are in the FSE view
		extract(Helper::extractUrlParams($themeSlug));

		if ($postType === 'page' && $postId !== null){
			$permalink = get_permalink($postId);
		}

		return $permalink;
	}

	public static function extractUrlParams($themeSlug){
		$postType = isset($_GET['postType']) ? $_GET['postType'] : null;
		$postId = isset($_GET['postId'])
			? Helper::removeRedundantThemePrefix(
				Helper::maybeMakeNumber($_GET['postId']), $themeSlug
			)
			: null;
		$categoryType = isset($_GET['categoryType']) ? $_GET['categoryType'] : null;
		$fseType = Helper::getFSEType($postType, $categoryType);

		return array(
			'postType' => $postType,
			'postId' => $postId,
			'categoryType' => $categoryType,
			'fseType' => $fseType,
		);

	}

	public static function templateMapName(){
		return Helper::isLiveContentTest()
			? 'template-map-live'
			: 'template-map';
	}

	public static function extractSyncedPatterns($content){

		$pattern = '/<!-- wp:(block|navigation) {"ref":(\d+)}/';

		preg_match_all($pattern, $content,$matches);

		return isset($matches[2])
			? $matches
			: array();

	}

	// remove redundant theme slug if it matches the name of the file we are working with
	// this is a duplicate of a method in BlockTrait.php - todo create HelpersTrait
	public static function removeRedundantThemePrefix($slug, $themeSlug){

		$redundantPrefix = '/^'.$themeSlug . '\/\/?(.+)$/';

		if ($slug && preg_match($redundantPrefix, $slug, $matches)){
			$slug = $matches[1];
		}

		return $slug;
	}

	// Set numeric values to inter or floats
	public static function maybeMakeNumber($value){

		return is_numeric($value)
			? (strpos($value, '.') !== false
				? (float) $value
				: (int) $value
			)
			: $value;
	}

	public static function homePageData(){

		$id = -1;

		// Blog posts on home
		if (('posts' === get_option( 'show_on_front' ))){
			$title = esc_html__('Blog home', 'microthemer');
			$logic = '\Microthemer\is_post_or_page("front")';
		}

		// Static page on home
		else {
			$id = get_option('page_on_front');
			$post = get_post($id);
			$title = $post->post_title;
			$logic = '\Microthemer\is_post_or_page("'.$id.'")';
		}

		return array(
			'id' => $id,
			'title' => $title,
			'logic' => $logic
		);

	}

}