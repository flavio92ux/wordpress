<?php

namespace Microthemer;

/*
 * Logic
 *
 * Evaluate a PHP syntax conditional expression as a text string without using eval()
 * Supports a handful of WP functions and: ||, or, &&, and, (, ), !, =
 * Test String: is_page('test') and is_page("some-slug") && ! has_category() or has_tag() || is_date() === 23 or is_date() !== 'My string'
 * Test Regex: (?:(!)?\s*([a-z_]+)\('?"?(.*?)'?"?\))|(and|&&)|(or|\|\|)|([=!]{2,3})|(\d+)|(?:['"]{1}(.+?)['"]{1})
 */

class Logic {

	protected $test = false;

	// we cache condition results at various levels of granularity for maximum performance
	public static $cache = array(
		'conditions' => array(),
		'statements' => array(),
		'functions' => array(),
	);
	protected $statementCount = 0;
	protected $settings = array();

	// parenthesis parsing variables
	protected $stack = null;
	protected $current = null;
	protected $string = null;
	protected $position = null;
	protected $buffer_start = null;
	protected $length;

	// Regex patterns for reading logic
	protected $patterns = array(
		"andOrSurrSpace" => "/\s+\b(and|AND|or|OR)\b\s+/",
		"functionName" => "(!)?\s*[a-zA-Z_\\\\]+",
		"comparison" => "/\s*(?<comparison><=|<|>|>=|!==?|===?)\s*/",
		"expressions" => array(
			"(?:(?<negation>!)?\s*(?<functionName>[a-zA-Z_\\\\]+)\((?<parameter>.*?)\))",
			"(?:[$]_?(?<global>GET|POST|GLOBALS)\['?\"?(?<key>.*?)'?\"?\])",
			"(?<string>['\"].+?['\"])",
			"(?<boolean>true|false|null|TRUE|FALSE|NULL)",
			"(?<number>-?\d+)",

		)
	);

	// PHP functions the user is allowed to use in the logic
	protected $allowedFunctions = array(

		'get_post_type',
		'has_action',
		'has_block',
		'has_category',
		'has_filter',
		'has_meta',
		'has_post_format',
		'has_tag',
		//'has_term', // covered by other functions and maybe too broad for asset assignment
		'is_404',
		'is_admin',
		'is_archive',
		'is_author',
		'is_category',
		'is_date',
		'is_front_page',
		'is_home',
		'is_page',
		'is_post_type_archive',
		'is_search',
		'is_single',
		'is_singular',
		'is_super_admin',
		'is_tag',
		'is_tax',
		'is_login',
		'is_user_logged_in',

		// custom namespaced Microthemer functions
		'\\'.__NAMESPACE__.'\has_template',
		'\\'.__NAMESPACE__.'\is_active',
		'\\'.__NAMESPACE__.'\is_admin_page',
		//'\\'.__NAMESPACE__.'\has_block_content',
		'\\'.__NAMESPACE__.'\is_post_or_page',
		'\\'.__NAMESPACE__.'\is_public',
		'\\'.__NAMESPACE__.'\is_public_or_admin',
		'\\'.__NAMESPACE__.'\match_url_path',
		'\\'.__NAMESPACE__.'\query_admin_screen',
		'\\'.__NAMESPACE__.'\user_has_role',

		// native PHP
		'isset',
	);

	protected $allowedSuperglobals = array(
		'$_GET',
	);

	function __construct($settings = array()){

		Logic::$cache['settings'] = $settings;

		// maybe allow user defined whitelist of functions here
	}

	// normalise &&, || for simpler regex and logical comparisons
	protected function normaliseAndOr($string){

		return str_replace(
			array("&&", "||"),
			array("and", "or"),
			$string
		);
	}

	// replace is_page(2) with is_page^^2^^ so that parenthesis in function doesn't create a new group
	protected function addCarets($string){

		return preg_replace(
			"/(".$this->patterns['functionName'].")\((.*?)\)/s",
			'$1^^$3^^',
			$string
		);
	}

	protected function removeCarets($string){

		return preg_replace(
			"/(".$this->patterns['functionName'].")\^\^(.*?)\^\^/s",
			'$1($3)',
			$string
		);
	}

	protected function splitStatements($value){

		return preg_split(
			$this->patterns["andOrSurrSpace"],
			trim($value),
			-1,
			PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE
		);
	}

	protected function push(){

		if ($this->buffer_start !== null) {

			// extract string from buffer start to current position
			$buffer = substr($this->string, $this->buffer_start, $this->position - $this->buffer_start);

			// clean buffer
			$this->buffer_start = null;

			// throw token into current scope
			$statementsArray = $this->splitStatements(
				$this->removeCarets(
					$buffer
				)
			);

			if (count($statementsArray)){
				$this->current = array_merge($this->current, $statementsArray);
			}
		}
	}

	// Tease apart parenthesis groups
	protected function parseStatements($string){
		return $this->parse($string);
	}

	// walk over a multidimensional array recursively, applying a callback on non-array values
	protected function traverseStatements(&$array, $callback, $level = 0){

		$result = false;

		foreach ($array as $index => &$value){

			// if we are on a parenthesis group, get the result of the group
			if (is_array($value)){

				$result = $this->traverseStatements($value, $callback, (++$level));

				Helper::debug('Group result ', array(
					'result' => $result,
					'group' => $value
				));
			}

			// get the result of the individual statement
			else {

				// simply move onto the next statement if we're on and/or
				if ($value === 'and' || $value === 'or' || $value === 'AND' || $value === 'OR'){
					continue;
				}

				// check result
				$result = $this->evaluateStatement($value);
				$resultString = $result
					? 'true'
					: ($result === null ? 'null' : 'false');

				// now that we have processed the logical statement, add some debug info
				$array[$index].= ' ['.$resultString.']';

				Helper::debug('Statement result ('.$value.'): '.$result);
			}

			// look for the following and/or and possibly return early
			$nextIndex = $index + 1;
			$nextStatement = isset($array[$nextIndex]) ? $array[$nextIndex] : false;

			if (
				!$nextStatement ||
				($result && ($nextStatement === 'or' || $nextStatement === 'OR')) ||
				(!$result && ($nextStatement === 'and' || $nextStatement === 'AND'))
			){

				// mark final result
				if (!is_array($array[$index])){
					$array[$index].= '[result]';
				}

				return $result;
			}

			// true result
			/*if ($result){

				// if the next statement is an 'or',
				// we can safely return the TRUE result
				if ($nextStatement === 'or' || $nextStatement === 'OR'){
					Helper::debug('Return early as true and next is OR ' . json_encode($value));
					// now that we have processed the logical statement, add some debug info
					$array[$index].= '[result]';
					return $result;
				}

			}

			// false result
			else {

				// if the next statement is an 'and',
				// we can safely return the FALSE result
				if ($nextStatement === 'and' || $nextStatement === 'AND'){
					Helper::debug('Return early as false and next is AND ' . json_encode($value));
					$array[$index].= '[result]';
					return $result;
				}
			}*/

		}

		return $result;
	}

	protected function parseStatement($string){

		preg_match(
			"/" . implode('|', $this->patterns['expressions']) . "/s",
			$string,
			$matches
			//PREG_PATTERN_ORDER
		);

		return $matches;
	}

	protected function statementResult($parsedStatement){

		Helper::debug('Statement parsed in callback', $parsedStatement);
		
		$result = false;

		// query any GET/POST values
		$global = isset($parsedStatement['global']) ? $parsedStatement['global'] : false;
		if ($global){

			$key = $parsedStatement['key'];

			if (!$key){
				return false;
			}

			if ($global == 'GET'){
				$result = isset($_GET[$key]) ? $_GET[$key] : false;
			} elseif ($global == 'POST'){
				$result = isset($_POST[$key]) ? $_POST[$key] : false;
			} elseif ($global == 'GLOBALS'){
				$result = isset($GLOBALS[$key]) ? $GLOBALS[$key] : false;
			}

		}

		// query any allowed function results
		$functionName = isset($parsedStatement['functionName']) ? $parsedStatement['functionName'] : false;
		if ($functionName){

			// bail if the function isn't allowed, or doesn't exist
			if (
				!in_array($functionName, $this->allowedFunctions) || !function_exists($functionName)
			    //(!function_exists($functionName) && !function_exists( 'Microthemer\\' .$functionName))
			){
				Helper::debug('Disallowed or does not exist:', [
					'$functionName' => $functionName,
					'not allowed' => !in_array($functionName, $this->allowedFunctions),
					'does not exist' => !function_exists($functionName)
				]);
				return null;
			}

			$parameter = isset($parsedStatement['parameter']) ? $parsedStatement['parameter'] : '';
			$parameters = $parameter
				? preg_split("/\s*,\s*/", $parameter)
				: array();

			Helper::debug('Parameter Strings', $parameters);

			// native PHP functions cannot be called with call_user_func_array (as not user function)
			if ($functionName === 'isset'){

				// we have a parameter
				if (isset($parameters[0])){

					$parsedParameter = $this->parseStatement($parameters[0]);
					$globalParameter = isset($parsedParameter['global']) ? $parsedParameter['global'] : false;

					// we have a global parameter
					if ($globalParameter){

						$key = $parsedParameter['key'];

						if (!$key){
							return false;
						}

						if ($globalParameter == 'GET'){
							$result = isset($_GET[$key]);
						} elseif ($globalParameter == 'POST'){
							$result = isset($_POST[$key]);
						} elseif ($globalParameter == 'GLOBALS'){
							$result = isset($GLOBALS[$key]);
						}
					}
				}

				// no parameter, so false
				else {
					$result = null;
				}
			}

			// run function
			else {

				$cacheKey = $functionName . '('.$parameter.')';

				// draw from function call cache if available
				if (isset(Logic::$cache['functions'][$cacheKey])){

					$result = Logic::$cache['functions'][$cacheKey];

					Helper::debug('Pulling function result from cache:', array(
						'function' => $cacheKey,
						'result' => $result,
					));
				}

				else {

					// convert parameter strings to PHP result
					foreach ($parameters as $i => $parameterString){

						$parsedParameter = $this->parseStatement($parameterString);

						if (!$parsedParameter){
							Helper::debug('Cannot parse $parameterString: ' . $parameterString);
						} else {
							$parameters[$i] = $this->statementResult($parsedParameter);
						}
					}

					Helper::debug('Parameters converted', $parameters);

					$result = call_user_func_array(
						$functionName,
						$parameters
					);

					Logic::$cache['functions'][$cacheKey] = $result;
				}

			}

			// reverse result if negation has been used e.g. !is_page(20)
			$negation = isset($parsedStatement['negation']) && $parsedStatement['negation'];

			if ($negation){
				$result = !$result;
			}

		}

		// boolean
		$boolean = isset($parsedStatement['boolean']) ? $parsedStatement['boolean'] : false;
		if ($boolean){
			$result = $boolean === 'true';
		}

		// number
		$number = isset($parsedStatement['number']) ? $parsedStatement['number'] : false;
		if ($number){
			$result = strpos($number, '.') === false ? intval($number) : floatval($number);
		}

		// string
		$string = isset($parsedStatement['string']) ? $parsedStatement['string'] : false;
		if ($string){
			$result = str_replace(array('"', "'"), '', $string);
		}

		return $result;
	}

	protected function evaluateStatement($value){

		// split the statement on the comparison (e.g. ===)
		$results = preg_split(
			$this->patterns["comparison"],
			trim($value),
			-1,
			PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE
		);

		$comparison = false;

		Helper::debug('Split on any comparison', $results);

		foreach ($results as $index => $part){

			if ($index === 1){
				$comparison = $part;
			}

			// process the result of the statement
			else {

				// draw from statement cache if available
				if (isset(Logic::$cache['statements'][$part])){

					$results[$index] = Logic::$cache['statements'][$part];

					Helper::debug('Pulling statement result from cache:', array(
						'statement' => $part,
						'result' => $results[$index],
					));
				}

				// statement needs to be run
				else {
					$parsedStatement = $this->parseStatement($part);

					if (!$parsedStatement) {
						Helper::debug( 'Cannot parse statement: ' . $part);
						$results[$index] = null;
					} else {
						Helper::debug( 'Could parse statement: ' . $part, $parsedStatement);
						$results[$index] = $this->statementResult($parsedStatement);
					}

					// cache result so evaluation of the same statement only happens once
					Logic::$cache['statements'][$part] = $results[$index];
				}

			}
		}

		Helper::debug('Processed statement results:', $results);

		// return comparison if defined and we have two values
		if ($comparison && count($results) > 2){

			$a = $results[0];
			$b = $results[2];

			switch ($comparison) {
				case '==':
					return $a == $b;
				case '===':
					return $a === $b;
				case '!=':
					return $a != $b;
				case '!==':
					return $a !== $b;
				case '>':
					return $a > $b;
				case '<':
					return $a < $b;
				case '>=':
					return $a >= $b;
				case '<=':
					return $a <= $b;
				default:
					return false;
			}

		}

		// otherwise simply return the first result
		return isset($results[0])
					? $results[0]
					: null;

	}

	public function parse($string){

		if (!$string) {
			return array();
		}

		$this->current = array();
		$this->stack = array();
		$quotesOpen = array();

		// use caret ^^ placeholder for function parenthesis we don't create an extra group
		// and replace && with and for simpler regex/logic
		$string = $this->normaliseAndOr(
			$this->addCarets(
				trim($string)
			)
		);

		$this->string = $string;
		$this->length = strlen($this->string);

		// look at each character
		for ($this->position=0; $this->position < $this->length; $this->position++) {

			$char = $this->string[$this->position];
			$isInsideQuotes = count($quotesOpen);

			switch ($char) {
				case '(':
					if (!$isInsideQuotes){
						$this->push();
						// push current scope to the stack and begin a new scope
						$this->stack[] = $this->current;
						$this->current = array();
					}
					break;

				case ')':
					if (!$isInsideQuotes){
						$this->push();
						// save current scope
						$t = $this->current;
						$this->current = array_pop($this->stack);

						// add just saved scope to current scope
						if (count($t)){

							// get the last scope from stack
							$this->current[] = $t;
							break;
						}
					}

					break;

				default:
					// remember the offset to do a string capture later
					// could've also done $buffer .= $string[$position]
					// but that would just be wasting resources…
					if ($this->buffer_start === null) {
						$this->buffer_start = $this->position;
					}

					// flag if we are inside quotes
					if ($char === "'" || $char === '"'){
						$altQuote = $char === '"' ? "'" : '"';
						if (isset($quotesOpen[$char])){
							unset($quotesOpen[$char]);
						} else {
							// if we are not inside the other type of quote (which would escape it)
							if (!isset($quotesOpen[$altQuote])){
								$quotesOpen[$char] = 1;
							}
						}
					}

			}
		}

		// catch any trailing text
		if ($this->buffer_start <= $this->position) {
			$this->push();
		}

		return $this->current;
	}

	public function evaluate($statementsArray, $string, $test, $fileExists = null){

		$result = $this->traverseStatements($statementsArray, 'evaluateStatement');

		Helper::debug('Debug', array(
			'result' => $result,
			'load' => $result ? 'Yes' : 'No',
			'logic' => $string,
			'num_statements' => count($statementsArray, COUNT_RECURSIVE),
			'analysis' => '<pre>'.print_r($statementsArray, 1).'</pre>',
			//'cache' => Logic::$cache
		), false);

		return !$test
			? $result
			: array(
				'fileExists' => $fileExists,
				'empty' => !$fileExists,
				'blocksOnly' => $result === 'blocksOnly',
				'resultIsString' => is_string($result) ? $result : false, // e.g. blocksOnly
				'result' => $result,
				'resultString' => $result
					? 'true'
					: ($result === null ? 'null' : 'false'),
				'load' => $result ? 'Yes' : 'No',
				'logic' => $string,
				'num_statements' => $this->countNumStatements($statementsArray),
				'analysis' => '<pre>'.print_r($statementsArray, 1).'</pre>'
			);

			/*(
				$fileExists === false && false // seb test todo this causes issues - but check why needed...
					? array(
						'result' => 0,
						'resultString' => 'false',
						'load' => 'No',
						'logic' => $string,
						'num_statements' => $this->countNumStatements($statementsArray),
						'analysis' => 'The folder is not loading because it has no styles. <br /> 
									   Therefore, the logic result is irrelevent: <br /><br />' .
						              '<pre>'.print_r($statementsArray, 1).'</pre>'
					)
					: array(
						'result' => $result,
						'resultString' => $result
							? 'true'
							: ($result === null ? 'null' : 'false'),
						'load' => $result ? 'Yes' : 'No',
						'logic' => $string,
						'num_statements' => $this->countNumStatements($statementsArray),
						'analysis' => '<pre>'.print_r($statementsArray, 1).'</pre>'
					)
			);*/

	}

	public function countNumStatements($array){

		foreach ($array as $value) {

			if ( is_array( $value ) ) {
				$this->countNumStatements($value);
			} else {
				if ($value === 'and' || $value === 'or' || $value === 'AND' || $value === 'OR'){
					continue;
				}
				++$this->statementCount;
			}
		}

		return $this->statementCount;
	}

	public function result($string, $test = false, $fileExists = null){

		Helper::debug('String received: ' . $string);

		$result = null;
		$error = false;
		$statementsArray = $this->parseStatements($string);

		// draw from full conditional statements string cache if available
		if (isset(Logic::$cache['conditions'][$string])){

			$result = Logic::$cache['conditions'][$string];

			Helper::debug('Pulling condition result from cache:', array(
				'condition' => $string,
				'result' => $result,
			));
		}

		else {

			// Running a function could result in an error which we should capture but suppress
			try {
				$result = $this->evaluate($statementsArray, $string, $test, $fileExists);
			}

				// 'Throwable' is executed in PHP 7+, but ignored in lower PHP versions
			catch (\Throwable $t) {
				$error = $t->getMessage();
			}

				// 'Exception' is executed in PHP 5, this will not be reached in PHP 7+
			catch (\Exception $e) {
				$error = $e->getMessage();
			}
		}



		// return error result if a PHP exception occurs - this should fail silently
		if ($error){

			if ($test){

				$result = array(
					'error' => $error,
					'result' => null,
					'resultString' => 'null',
					'load' => 'No',
					'logic' => $string,
					'num_statements' => 0,
					'analysis' => 'Your condition generated a PHP error. The folder will not load until you fix it: ' . '<br /><br /><b><pre>' . $error . '</pre></b>'
				);
			}

			// the folder just won't load, but no errors will display on the frontend
			else {
				$result = null;
			}

		}

		// cache result in case same condition is used in another folder
		Logic::$cache['conditions'][$string] = $result;

		return $result;

	}

	public function getAllowedPHPSyntax(){
		return array(
			'functions' => $this->allowedFunctions,
			'superglobals' => $this->allowedSuperglobals,
			'characters' => 'or | and & ( ) ! = > <'
		);
	}


	// Integrations

	// Bricks Templates
	public static function getBricksTemplateIds($template_id, &$template_ids, $content_type = 'nested'){

		if (is_numeric($template_id) && $template_id > 0
		    && !isset($template_ids[$template_id])
		    && !\Bricks\Database::is_template_disabled($content_type)) {

			$template_ids[intval($template_id)] = $content_type;
			$meta_key = $content_type === 'header'
				? BRICKS_DB_PAGE_HEADER
				: ($content_type === 'footer'
					? BRICKS_DB_PAGE_FOOTER
					: BRICKS_DB_PAGE_CONTENT);
			$bricks_data = get_post_meta( $template_id, $meta_key, true );

			if (is_array($bricks_data)){
				foreach($bricks_data as $item){
					if (!empty($item['settings']['template'])){
						Logic::getBricksTemplateIds($item['settings']['template'], $template_ids);
					}
				}
			}
		}

	}

	public static function debugger(){

	}

	public static function getGutenbergTemplateIds($source, &$id, &$template_ids){

		global $post, $pagenow;

		$map = null;
		$startingPoints = array();
		$isFSE = $pagenow === 'site-editor.php';
		$themeSlug = get_stylesheet();
		$id = Helper::removeRedundantThemePrefix($id, $themeSlug);
		$currentTemplate = '';

		// Get parameters in case we are in the FSE view
		extract(Helper::extractUrlParams($themeSlug));

		// Get the current template from GET or captured from hook on frontend
		if ($isFSE){

			// FSE falls back to the home page if no $postId is set (for overview pages)
			// So we need to grab the post for the single page if set
			$homePageFallback = false;
			if (!$postId){

				// single page assigned to the front
				if (get_option('show_on_front') === 'page'){
					$postId = get_option('page_on_front');
					$post = get_post($postId);
					$homePageFallback = true;
					Helper::debug('FSE fallback to home page (single page): '.$currentTemplate);
				}

				// Recent posts page
				else {
					$currentTemplate = 'home';
					Helper::debug('FSE fallback to blog home: '.$currentTemplate);
				}

			}

			// single template and page views
			if ($fseType === 'wp_template'){
				$currentTemplate = $postId;
			} elseif($fseType === 'page' || ($postId && $homePageFallback)){
				if (!$homePageFallback){
					$post = get_post($postId);
				}
				$currentTemplate = Helper::getTemplateFromPostId($postId, $post);
				Helper::debug('FSE page template: '.$currentTemplate);
			}
		} else {

			// regular Gutenberg editor
			if ($pagenow === 'post.php' && isset($_GET['post'])){
				$postId = $_GET['post'];
				$post = get_post($postId);
				$currentTemplate = Helper::getTemplateFromPostId($postId, $post);
			}

			// any other front or admin page
			else {
				$currentTemplate = Helper::getCurrentTemplateSlug();
			}

		}


		Helper::debug('Check '.($isFSE ? 'FSE' : 'front/other').' for '.$source.' with id: '.$id . ' (current template: '.$currentTemplate.')');

		// the check for a template is simple in FSE or frontend - there is just one value to compare
		if ($source === 'wp_template'){
			if ($currentTemplate == $id){ // loose, so user can use quotes e.g. "404"
				Helper::debug('Found wp_template: ' . $id);
				$template_ids[$id] = 'blocksOnly';
			}
		}

		// for parts, patterns, and navigation
		else {
			
			// we need to check the cached map
			$map = Helper::getTemplateMap($themeSlug, Logic::$cache);
			
			// we should extract any synced pattern references from the post content,
			// so they can be checked in the map too
			if ($post instanceof \WP_Post){

				$content = $post->post_content;

				// allow for the live post override
				if (Helper::isLiveContentTest()){
					Helper::debug('Live post override');
					$content = Common::$live_post_content;
				}

				$matches = Helper::extractSyncedPatterns($content);
				$types = $matches[1];
				$syncedPatternIds = $matches[2];

				if (count($syncedPatternIds)){

					Helper::debug('Found synced patterns in post content: ' . implode(', ', $syncedPatternIds));

					foreach ($syncedPatternIds as $i => $syncedPatternId){

						$type = $types[$i];
						$key = $type === 'block' ? 'wp_pattern' : 'wp_' . $type;

						if (isset($map[$key][$syncedPatternId])){

							$startingPoints[] = $map[$key][$syncedPatternId];

							// log if we found the pattern we're looking for
							if ($source === $key && $id == $syncedPatternId){
								$template_ids[$syncedPatternId] = 'blocksOnly';
							}
						}
					}
				}

			}

			else {
				Helper::debug('Post not available', $post);
			}

			// with FSE, we still want to check the map recursively
			// determine the starting point based on the GET parameters
			if ($isFSE && $fseType !== 'page'){

				Helper::debug('$fseType is '.$fseType.' ('.$categoryType.'): '.$postType);

				// if we have a match of logic parameters and get parameters
				if ($fseType === $source && $postId == $id){ // loose, so user can use quotes e.g. "404"
					Helper::debug('Top level FSE GET parameters match ('.$source.'): '.$id);
					$template_ids[$id] = 'blocksOnly';
				}

				// otherwise search map from current type starting point
				elseif (isset($map[$fseType][$postId])){
					$startingPoints[] = $map[$fseType][$postId];
				}

			}

			// if any page other than FSE template/part/pattern (so FSE page is included here),
			// we scan the map from the current template starting point
			else {
				if ($currentTemplate){
					if (isset($map['wp_template'][$currentTemplate])){
						$startingPoints[] = $map['wp_template'][$currentTemplate];
					}
				}
			}

			// if we have a valid starting point, search for the item in the map recursively
			if (count($startingPoints) && empty($template_ids[$id])){

				$numStartingPoint = count($startingPoints);

				foreach ($startingPoints as $i => $startingPoint){

					Logic::checkGutenbergMap(
						$startingPoint, $map, $id, $template_ids, $source
					);

					// stop searching any further if we've found something
					if (!empty($template_ids[$id])){
						Helper::debug('Stop searching at point '.($i+1).'/'.$numStartingPoint.' ('.$source.'): ' . $id);
						break;
					}
				}

			}


		}

		if (Helper::$debug){
			/*if ('twentytwentyfour//page-with-sidebar' == $id){
				wp_die('<pre>'.print_r([
						'source' => $source,
						'$currentTemplate' => $currentTemplate,
						'$currentTemplate Type' => gettype($currentTemplate),
						'theme' => $themeSlug,
						'id' => $id,
						'id Type' => gettype($id),
						'template_ids' => $template_ids,
						'$startingPoints' =>$startingPoints,
						'map' => $map,
					], true).'</pre>');
			}*/




		}


	}

	// Check Gutenberg parts / patterns
	public static function checkGutenbergMap($array, $map, $id, &$template_ids, $source){

		// bail if we've already checked the item and its sub-items
		if (isset($template_ids[$id])){
			Helper::debug('Bail as id is set ('.$source.'): ' . $id);
			return;
		}

		// parts can't have sub-parts, but they can have sub-patterns (nav is a type of pattern)
		// patterns can have both sub-patterns and sub-parts
		// So we need to recursively check both types
		$sources = array('wp_template_part', 'wp_pattern', 'wp_navigation');

		foreach ($sources as $itemSource){

			if (!empty($array[$itemSource])){

				$subArray = $array[$itemSource];

				if (is_array($subArray)){

					foreach($subArray as $itemId => $enabled){

						/*echo '<br /><br />$itemSource: '.$itemSource .
						     '<br />$source: '.$source .
						     '<br />$itemId: '.$itemId .
						     '<br />$id: '.$id;*/

						if ($itemSource === $source && Helper::maybeMakeNumber($itemId) == $id){ // loose, so user can use quotes e.g. "404"
							$template_ids[$id] = 'blocksOnly';
							Helper::debug('Match found ('.$itemSource.'): ' . $id);
						}

						elseif (!empty($map[$itemSource][$itemId])){
							Helper::debug('Recursive check ('.$itemSource.'): ' . $itemId);
							Logic::checkGutenbergMap(
								$map[$itemSource][$itemId], $map, $id, $template_ids, $source
							);
						}
					}
				}
			}
		}
	}

}

/*
 * Custom (namespaced) microthemer functions for use with logical conditions
 * These fill gaps in WordPress API and can support integrations with other plugins
 * IMPORTANT - all params must be optional to prevent user from generating a fatal error (extra params OK it seems)
 */

// check what admin page the user is on - allow the page name or an id
function is_admin_page($pageNameOrId = false){

	global $post;

	return is_admin() && !$pageNameOrId

	       // e.g. edit.php
	       || (isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === $pageNameOrId)

	       // e.g. 123
	       || (is_numeric($pageNameOrId) && isset($_GET['post']) && intval($_GET['post']) === intval($pageNameOrId))

	       // e.g. my-post-slug
	       || (!is_numeric($pageNameOrId) && isset($post->post_name) && $post->post_name === $pageNameOrId);
}

// wp_die('<pre>has_blocks: ' . has_blocks() . '</pre>');


// check what page the user is on (frontend or admin)
function is_post_or_page($id = null){

	$globalOrFrontMatch = ($id === 'front' && Helper::isFrontOrFallback()) ||
	                      ($id === 'global' && (is_public() || Helper::isBlockAdminPage('global')));
	
	return is_public() && (is_page($id) || is_single($id) || $globalOrFrontMatch)
		? true
		: (is_admin() && (Helper::isBlockAdminPage($id) || $globalOrFrontMatch )
			? 'blocksOnly'
			: false);
}

// check what admin page the user is on
function is_public(){
	return !is_admin();
}

// check what admin page the user is on
function is_public_or_admin($postOrPageId = null){
	return !$postOrPageId
	       || ( !is_admin() && is_post_or_page($postOrPageId) )
	       || is_admin_page($postOrPageId);
}

function query_admin_screen($key = null, $value = null){

	if (!function_exists('get_current_screen')){
		return false;
	}

	$current_screen = get_current_screen();

	return ($key === null || isset($current_screen->$key))
	       && ($value === null || $current_screen->$key === $value);
}

// check if the user has a particular role or user id
function user_has_role($roleOrUserId = null){
	return is_user_logged_in() && $roleOrUserId === null ||
	       wp_get_current_user()->roles[0] === $roleOrUserId ||
	       (is_numeric($roleOrUserId) && intval($roleOrUserId) === get_current_user_id());
}

// check if a theme or plugin is active, slug is the directory slug e.g. 'microthemer' or 'divi'
function is_active($item = null, $slug = null){
	switch ($item) {
		case 'plugin':
			$active_plugins = get_option('active_plugins', array());
			foreach($active_plugins as $path){
				if (strpos($path, $slug) !== false){
					return true;
				}
			}
			return is_plugin_active_for_network($slug);
		case 'theme':
			$theme = wp_get_theme();
			return $theme->get_stylesheet() === $slug;
		default:
			return false;
	}
}

// check if the current url matches a path
function match_url_path($value = null, $regex = false){
	$urlPath = $_SERVER['REQUEST_URI'];
	return $regex
		? preg_match('/'.$value.'/', $urlPath)
		: strpos($urlPath, $value) !== false;
}

function has_template($source = null, $id = null, $label = null){

	global $post;

	$cache = !empty(Logic::$cache[$source]['template_ids'])
		? Logic::$cache[$source]['template_ids']
		: false;
	$template_ids = $cache ?: array();
	$returnType = true;

	if (!$source || !$id){
		return false;
	} if ($cache){
		return !empty($cache[$id]) ? $cache[$id] : false;
	}

	// maybe populate template_ids
	switch ($source) {

		case 'bricks':
			if ( \Bricks\Helpers::render_with_bricks($post->ID) ) {
				foreach (\Bricks\Database::$active_templates as $content_type => $template_id){
					Logic::getBricksTemplateIds($template_id, $template_ids, $content_type);
				}
			}
			break;

		case 'wp_template':
		case 'wp_template_part':
		case 'wp_pattern':
		case 'wp_navigation':
			$returnType = 'blocksOnly';
			Logic::getGutenbergTemplateIds($source, $id,$template_ids);
			break;

		case 'elementor': // todo
			break;
	}

	// cache the template analysis for the source
	Logic::$cache[$source]['template_ids'] = $template_ids;


	return !empty($template_ids[$id]) ? $returnType : false;
}