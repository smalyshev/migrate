<?php
$chk = new Checker();

function usage($argv) {
	$help = <<<ENDH
Usage: $argv[0] [options] {file(s) to check}
options:
    -h|--help       This help
    -d L|--debug L  Set debug level L 

File(s) can be either filenames or directories. 
ENDH;
	echo $help;
	exit();
}

$needhelp = true;

for($i=1;$i<$_SERVER['argc'];$i++) {
	$option = $_SERVER['argv'][$i];
	if($option == '-h' || $option == '-?' || $option == "--help") {
		usage($_SERVER['argv']);
	}
	
	if($option == "-d" || $option == "--debug") {
		$i++;
		if($i<$_SERVER['argc']) {
			$chk->debug = intval($_SERVER['argv'][$i]);
		} else {
			usage($_SERVER['argv']);
		}
		continue;
	}
	$needhelp = false;
	$chk->check($option);
}

if($needhelp) {
	usage($_SERVER['argv']);
}

class Checker 
{
	public $debug = 0;
	
	protected $_filename;
	
	const DEPRECATED = 'deprecated-func';
	const DEPRECATED_ALT = 'deprecated-with-alt';
	const KEYWORD = 'keyword';
	const TOSTRING = 'tostring-noparam';
	const NOSTATIC = 'no-static';
	const NOTPUBLIC = 'not-public';
	
	protected $_messages = array(
		self::DEPRECATED => "Function '%s' is deprecated, its use is no longer recommended",
		self::DEPRECATED_ALT => "Function '%s' is deprecated, please use '%s' instead",
		self::KEYWORD => "'%s' is now a keyword, rename the function",
		self::TOSTRING => "__toString() method should not take any parameters",
		self::NOSTATIC => "Magic method %s can not be declared as static",
		self::NOTPUBLIC => "Magic method %s should be declared as public",
		);
	
	protected $_deprecated = array(
		"ereg" => "preg_match",
		"eregi" => "preg_match",
		"ereg_replace" => "preg_replace",
		"eregi_replace" => "preg_replace",
		"split" => "explode' or 'preg_split",
		"spliti" => "preg_split",
		"sql_regcase" => true,
		"mcrypt_generic_end" => "mcrypt_generic_deinit",
		"mysql_create_db" => "mysql_query",
		"mysql_drop_db" => "mysql_query",
		"mysql_list_tables" => "mysql_query",
		"mysql_createdb" => "mysql_query",
		"mysql_dropdb" => "mysql_query",
		"mysql_listtables" => "mysql_query",
		"session_register" => true,
		"session_unregister" => true,
		"session_is_registered" => true,
		"magic_quotes_runtime" => true,
		"set_magic_quotes_runtime" => true,
		"call_user_method" => "call_user_func",
		"call_user_method_array" => "call_user_func_array",
		"set_socket_blocking" => "stream_set_blocking",
		"socket_set_blocking" => "stream_set_blocking",
		"define_syslog_variables" => true,
	);
	
	/**
	 * Generate and display warning
	 * 
	 * @param string $type Warning type
	 * @param int $line File line
	 * @param array $args Warning arguments
	 * @return Checker
	 */
	protected function warning($type, $line, $args = null)
	{
		echo $this->getWarning($type, $line, $args);
		echo "\n";
		return $this;
	}
	
	/**
	 * Generate warning
	 * 
	 * @param string $type Warning type
	 * @param int $line File line
	 * @param array $args Warning arguments
	 * @return string
	 */
	protected function getWarning($type, $line, $args = null)
	{
		$message = $this->_messages[$type];
		if(!empty($args)) {
			array_unshift($args, $message);
			$message = call_user_func_array("sprintf", $args);
		}
		return sprintf("WARNING: %s in file %s line %d", $message, $this->_filename, $line); 
	}
	
	protected function _getAccess($tokens, $i)
	{
		$access = 0;
		while(--$i) {
			switch($tokens[$i][0]) {
				case T_STATIC:
					$access |= ReflectionMethod::IS_STATIC;
					break;
				case T_PROTECTED:
					$access |= ReflectionMethod::IS_PROTECTED;
					break;
				case T_PRIVATE:
					$access |= ReflectionMethod::IS_PRIVATE;
					break;
				case '&':
				case T_PUBLIC:
				case T_FINAL:
				case T_ABSTRACT:
				case T_FUNCTION:
					break;
				default:
					break 2;
			}
		}
		return $access;
	}
	
	/**
	 * Ensure that method is not declared static
	 * 
	 * @param array $token
	 * @param int $access
	 */
	protected function _checkStatic($token, $access) 
	{
		if($access & ReflectionMethod::IS_STATIC) {
			$this->warning(self::NOSTATIC, $token[2], array($token[1]));
		}
	}
	
	/**
	 * Ensure that method is declared public
	 * 
	 * @param array $token
	 * @param int $access
	 */
	protected function _checkPublic($token, $access) 
	{
		if($access & (ReflectionMethod::IS_PROTECTED|ReflectionMethod::IS_PRIVATE)) {
			$this->warning(self::NOTPUBLIC, $token[2], array($token[1]));
		}
	}
	
	protected function _mcheckToString($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		if($tokens[$i+1][0] == '(') {
			$i++; // skip '('
		} 
		if($tokens[$i+1][0] == T_STRING || $tokens[$i+1][0] == T_VARIABLE) {
			// oops, __toString with args!
			$this->warning(self::TOSTRING, $tokens[$i+1][2]);
		}
	}
	
	protected function _mcheckGet($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		$this->_checkPublic($token, $access);
	}
	
	protected function _mcheckSet($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		$this->_checkPublic($token, $access);
	}
	protected function _mcheckIsset($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		$this->_checkPublic($token, $access);
	}
	
	protected function _mcheckUnset($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		$this->_checkPublic($token, $access);
	}
	
	protected function _mcheckCall($token, $i, $tokens)
	{
		$access = $this->_getAccess($tokens, $i);
		$this->_checkStatic($token, $access);
		$this->_checkPublic($token, $access);
	}
	
	protected function checkFunctionDef($token, $i, $tokens)
	{
		if(!is_array($token)) {
			return;
		}
		if($this->debug >= 2) {
			echo "FUNCTION: $token[1] at $token[2]\n";
		}	
		$lwrtoken = strtolower($token[1]);
		
		if($token[1] == 'goto' || $token[1] == 'namespace') {
			$this->warning(self::KEYWORD, $token[2], array($token[1]));
		}
		
		if(substr($token[1], 0, 2) == '__' &&
			is_callable(array($this, "_mcheck".substr($token[1], 2)))) {
				// check magic methods
			call_user_func(array($this, "_mcheck".substr($token[1], 2)), $token, $i, $tokens);				
		}
	}
	
	protected function checkFunctionCall($token)
	{
		if(!is_array($token)) {
			return;
		}
		
		if($this->debug >= 2) {
			echo "CALL: $token[1] at $token[2]\n";	
		}
		$lfunc = strtolower($token[1]);
		if(isset($this->_deprecated[$lfunc])) {
			$newfunc = $this->_deprecated[$lfunc];
			if(is_string($newfunc)) {
				$this->warning(self::DEPRECATED_ALT, $token[2], array($token[1], $newfunc));
			} else {
				$this->warning(self::DEPRECATED, $token[2], array($token[1]));
			}	
		}	
	}

	protected function recursiveCheck($filename)
	{
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filename));
		foreach($files as $object) {
			if($object->isFile() && preg_match('/\.php$/', $object->getFilename())) {
				$this->check($object->getPathName());
			}
		}	
		return $this;
	}
	
	public function check($filename) 
	{
		if($this->debug >= 1) {
			echo "CHECKING: $filename\n";
			flush();
		}
		if(is_dir($filename)) {
			return $this->recursiveCheck($filename);
		}
		
		if(!file_exists($filename)) {
			echo "ERROR: file $filename does not exist";
			return $this;
		}
		
		$this->_filename = $filename;
		$data = file_get_contents($filename);
		$tokens = token_get_all($data);
		
		// weed out whitespace & comments
		foreach($tokens as $i => $token) {
			if($token[0] == T_WHITESPACE || $token[0] == T_COMMENT || $token[0] == T_DOC_COMMENT) {
				unset($tokens[$i]);
			}
		}
		// compact array
		$tokens = array_values($tokens);
		
		while(list($i, $token) = each($tokens)) {
			if($this->debug >= 3) {
				if(is_int($token[0])) {
					echo sprintf("%d: Token %s(%d) -> %s\n", $token[2], token_name($token[0]), $token[0], substr($token[1], 0, 20));
				} else {
					echo sprintf("Token '%s'\n", $token);
				}
			}
			
			if($token[0] == T_FUNCTION) {
				// function definition
				do{
					list($i, $token) = each($tokens);
				} while($token && (is_array($token) && $token[0] != T_STRING || $token == '&'));
				if($token == '(') {
					// got to ( - we must have mistaken func name for something else - rewind
					prev($tokens);prev($tokens);
					list($i, $token) = each($tokens);
				}
				$this->checkFunctionDef($token, $i, $tokens);
				if($tokens[$i+1] == '(') {
					next($tokens); // skip '('
				}
			} else if($token[0] ==  '(' && $tokens[$i-1][0] == T_STRING
					&& $tokens[$i-2][0] != T_OBJECT_OPERATOR
					&& $tokens[$i-2][0] != T_DOUBLE_COLON
					&& $tokens[$i-2][0] != T_NEW) {
				// function call
				$this->checkFunctionCall($tokens[$i-1]);
			} 
		}
		return $this;
	}
}