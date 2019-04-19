<?php

class TOGoS_GitIgnore_Util
{
	public static function aize($word) {
		if( preg_match('/^[aeiou]/', $word) ) return "an $word";
		return "a $word";
	}
	
	public static function describe($val) {
		if( $val === null ) return "null";
		if( is_float($val) or is_int($val) ) return "the number $val";
		if( is_bool($val) ) return $val ? "true" : "false";
		if( is_object($val) ) return self::aize(get_class($val));
		return aize(gettype($val));
	}
}

class TOGoS_GitIgnore_Pattern
{
	protected $patternString;
	protected $regex;
	protected function __construct($pattern, $regex) {
		$this->patternString = $pattern;
		$this->regex = $regex;
	}

	public function getPatternString() {
		return $this->patternString;
	}

	protected static function patternToRegex($pp) {
		preg_match_all('/\*|\*\*|\?|[^\*\?]|\[![^\]+]|\[[^\]+]/', $pp, $bifs);
		$regex = '';
		foreach( $bifs[0] as $part ) {
			if( $part == '**' ) $regex .= ".*";
			else if( $part == '*' ) $regex .= "[^/]*";
			else if( $part == '?' ) $regex .= '?';
			else if( $part[0] == '[' ) {
				// Not exactly, but maybe close enough.
				// Maybe fnmatch is the thing to use
				if( $part[1] == '!' ) $part[1] = '^';
				$regex .= $part;
			}
			else $regex .= preg_quote($part, '#');
		}
		return $regex;
	}

	public static function parse($pattern) {
		$r = self::patternToRegex($pattern);
		if( $pattern[0] == '/' ) {
			$r = '#^'.substr($r,1).'(?:$|/)#';
		} else {
			$r = '#(?:^|/)'.$r.'(?:$|/)#';
		}
		return new self($pattern, $r);
	}

	public function match($path) {
		if( $path[0] == '/' ) {
			throw new Exception("Paths passed to #match should not start with a slash; given: «".$path."»");
		}
		if( !is_string($path) ) {
			throw new Exception(__METHOD__." expects a string; given ".TOGoS_GitIgnore_Util::describe($path));
		}
		return preg_match($this->regex, $path);
	}
}

class TOGoS_GitIgnore_Rule
{
	protected $isExclusion;
	protected $pattern;

	public function __construct(TOGoS_GitIgnore_Pattern $pattern, $isExclusion) {
		$this->pattern = $pattern;
		$this->isExclusion = $isExclusion;
	}
	
	/** @return true: include this file, false: exclude this file, null: rule does not apply to this file */
	public function match($path) {
		if( !is_string($path) ) {
			throw new Exception(__METHOD__." expects a string; given ".TOGoS_GitIgnore_Util::describe($path));
		}
		if( $this->pattern->match($path) ) {
			return $this->isExclusion ? false : true;
		}
		return null;
	}

	public static function parse($str) {
		$isExclusion = false;
		if( $str[0] == '!' ) {
			$isExclusion = true;
			$str = substr($str, 1);
		}
		$pattern = TOGoS_GitIgnore_Pattern::parse($str);
		return new self($pattern, $isExclusion);
	}
}

class TOGoS_GitIgnore_Ruleset
{
	protected $rules;
	
	public function addRule($rule) {
		if( is_string($rule) ) {
			$str = trim($rule);
			if( $str == '' ) return;
			if( $str[0] == '#' ) return;
			if( substr($str,0,2) == '\\#' ) $str = substr($str,1);
			$rule = TOGoS_GitIgnore_Rule::parse($str);
		}
		if( !($rule instanceof TOGoS_GitIgnore_Rule) ) {
			throw new Exception("Argument to TOGoS_GitIgnore_Ruleset#addRule should be a string or TOGoS_GitIgnore_Rule; received ".TOGoS_GitIgnore_Util::describe($rule));
		}
		$this->rules[] = $rule;
	}

	public function match($path) {
		if( !is_string($path) ) {
			throw new Exception(__METHOD__." expects a string; given ".TOGoS_GitIgnore_Util::describe($path));
		}
		$lastResult = null;
		foreach( $this->rules as $rule ) {
			$result = $rule->match($path);
			if( $result !== null ) $lastResult = $result;
		}
		return $lastResult;
	}

	public static function loadFromString($str) {
		$lines = explode("\n", $str);
		$rs = new self;
		foreach( $lines as $line ) {
			$rs->addRule($line);
		}
		return $rs;
	}
	
	public static function loadFromFile($filename) {
		$rs = new self;
		$fh = fopen($filename);
		while( ($line = fgets($fh)) ) {
			$rs->addRule($line);
		}
		fclose($fh);
		return $rs;
	}
}

class TOGoS_GitIgnore_RulesetTest extends TOGoS_SimplerTest_TestCase
{
	protected function parseTestCases($content) {
		$lines = explode("\n", $content);
		$cases = array();
		foreach($lines as $line) {
			if( preg_match('/^# should match: (.*)$/', $line, $bif) ) {
				$cases[] = array('expectedOutput' => true, 'input' => $bif[1]);
			} else if( preg_match('/^# should not match: (.*)$/', $line, $bif) ) {
				$cases[] = array('expectedOutput' => false, 'input' => $bif[1]);
			}
		}
		return $cases;
	}
	
	public function testIt() {
		$rules1Content = file_get_contents(__DIR__.'/test1.ruleset');
		$ruleset = TOGoS_GitIgnore_Ruleset::loadFromString($rules1Content);
		$testCases = $this->parseTestCases($rules1Content);
		foreach( $testCases as $case ) {
			$shouldMatch = $case['expectedOutput'];
			$doesMatch = $ruleset->match($case['input']);
			if( $doesMatch === null ) $doesMatch = false;
			$this->assertEquals($shouldMatch, $doesMatch, "Expected '{$case['input']}' to ".($shouldMatch ? "match" : "not match"));
		}
	}
}
