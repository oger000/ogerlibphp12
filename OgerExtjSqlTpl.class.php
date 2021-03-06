<?PHP
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/


/*
 * ATTENTION:
 * This class is highly EXPERIMANTAL and INCOMPLETE.
 * There is only implemented what is used currently and
 * new features are added when needed.
 * So use with care.
 */




/**
* Prepare sql templates with exjs response data
*/
class OgerExtjSqlTpl {

	/// Debug flag.
	//public static $debug = true;
	public static $debug = false;
	public static $devDebug = false;
	public static $devDebug2 = false;

	public $request;
	public $template;
	public $parsed;
	public $prepared;
	public $sql;
	public $paramValues = array();

	public static $sqlEncBegin = "`";
	public static $sqlEncEnd = "`";

	/*
	 * Constructor.
	 */
	public function __construct($req = null) {
		$this->setRequest($req);
	}  // eo constructor


	/*
	 * Set request.
	 * defaults to supervariable $_REQUEST
	 */
	public function setRequest($req = null) {
		$this->request = ($req ?: $_REQUEST);
	}  // eo constructor


	/**
	* Get filter data from extjs request.
	*/
	public function getStoreFilter() {

		$prop = $this->request['filter'];

		// not present or empty
		if (!$prop) {
			return array();
		}

		// is already an array
		if (is_array($prop)) {
			return $prop;
		}

		// extract from json request
		$result = array();
		$items = json_decode($prop, true);
		foreach ((array)$items as $item) {
			$result[$item['property']] = $item['value'];
		}

		return $result;
	}  // eo get ext filter


	/**
	* Get sort data from extjs request.
	*/
	public function getStoreSort() {

		$prop = $this->request['sort'];

		// not present or empty
		if (!$prop) {
			return array();
		}

		// is already an array
		if (is_array($prop)) {
			return $prop;
		}

		// extract from json request
		$result = array();
		$items = json_decode($prop, true);
		foreach ((array)$items as $item) {
			$result[$item['property']] = $item['direction'];
		}

		return $result;
	}  // eo get ext sort


	/**
	* Get sql limit.
	*/
	public function getStoreLimit() {

	 // only numeric limit params are treated valid
	 $limit = 0;
		if (array_key_exists("limit", $this->request) && is_numeric($this->request['limit'])) {
			$limit = "" . intval($this->request['limit']);
		}

	 // only numeric start params are treated valid
		$start = 0;
		if ($limit && array_key_exists("start", $this->request) && is_numeric($this->request['start'])) {
			$start = intval($this->request['start']);
		}

		return array("start" => $start, "limit" => $limit);
	}  // eo get limit


	/**
	* Get values for named sql params
	*/
	public function getParamValues($stmt = null, $values = null) {
		$stmt = ($stmt ?: $this->sql);
		$values = ($values ?: $this->paramValues);

		$paramNames = OgerDb::getStmtParamNames($stmt);

		// remove unwanted param (this should not happen on internal
		// processing - but better stay on the safe side)
		foreach ($values as $paramName => $value) {
			if (!in_array($paramName, $paramNames)) {
				unset($values[$paramName]);
			}
		}  // eo remove loop

		// add missing parameter values
		foreach ($paramNames as $paramName) {

			if (!array_key_exists($paramName, $values)) {

				// try extjs filter
				$tmpVals = static::getStoreFilter();
				if (array_key_exists($paramName, $tmpVals)) {
					$values[$paramName] = $tmpVals[$paramName];
					continue;
				}

				// try given request from prep call or constructor
				$tmpVals = $this->request;
				if (array_key_exists($paramName, $tmpVals)) {
					$values[$paramName] = $tmpVals[$paramName];
					continue;
				}

				// last resort is the html request at all
				$tmpVals = $_REQUEST;
				if (array_key_exists($paramName, $tmpVals)) {
					$values[$paramName] = $tmpVals[$paramName];
					continue;
				}

			}
		}  // eo add loop

		return $values;
	}  // eo get param values


	// #######################################################
	// PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST


	/**
	* Prepare sql template and fill sele vals
	* ATTENTION: only accepts php-sql parser templates
	*/
	public static function _prepare($tpl, &$seleVals = array(), $req = null, $tplOpts = array()) {

		$extjSqlTpl = new static();
		$sql = $extjSqlTpl->prepare($tpl, $req, $tplOpts);
		$seleVals = $extjSqlTpl->getParamValues();

		// format / beautify sql
		if (!$tplOpts['skip-format']) {
			$sql = SqlFormatter::format($sql);
		}

		return $sql;
	}  // eo _prep



	// #######################################################
	// PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST



	/**
	* Prepare select statement with data from extjs request.
	* WORK IN PROGRESS
	* @params $tpl: The template containing special sql
	*         Variables are detectec by the question mark ("?") prefix.
	*/
	public function prepare($tpl, $req = null, $tplOpts = null) {
//static::$devDebug = true;
//static::$devDebug2 = true;
if (static::$devDebug) {
	Oger::debugFile("template = {$tpl}");
}

		$this->template = $tpl;

		if ($req !== null) {
			$this->setRequest($req);
		}
		// sanity check / set default
		if ($this->request === null) {
			$this->setRequest();
		}

		$this->paramValues = array();

		// parse and tree-ify
		$parser = new PHPSQLParser\PHPSQLParser();
		$this->parsed = $parser->parse($tpl);
if (static::$devDebug) {
	Oger::debugFile("parsed=\n" . var_export($this->parsed, true));
	//exit;
}

//$tplOpts['skip-php-sql-oger-prepare'] = true;
		if (!$tplOpts['skip-php-sql-oger-prepare']) {
			$this->prepared = $this->prepParserTree($this->parsed);
if (static::$devDebug) {
	Oger::debugFile("prepared=\n" . var_export($this->prepared, true));
	//exit;
}
		}
		else {
			$this->prepared = $this->parsed;
		}  // eo prepare

		// create sql from prepared parser tree
		$creator = new PHPSQLParser\PHPSQLCreator();
		$this->sql = $creator->create($this->prepared);
if (static::$devDebug) {
	Oger::debugFile("sql={$this->sql}");
	//exit;
}

		return $this->sql;
	}  // eo prep sql with extjs request



	/**
	* Prepare parser tree
	* @params $tree: Parsed and tokenized sql template tree
	*/
	public function prepParserTree($tree) {

		if ($tree['SELECT']) {
			$tree = $this->prepQuery($tree);
		}

		elseif ($tree['UNION'] || $tree['UNION ALL']) {
			$tree = $this->prepUnion($tree);
		}

		return $tree;
	}  // eo process parser tree


	/**
	* Prepare union tree
	* A union tree consists of an non-assoziative array of two (or more?) full queries.
	* @params $tree: Parsed and tokenized sql template tree
	*/
	public function prepUnion($tree) {

		foreach ($tree as $unionType => &$unionTree) {
			foreach ($unionTree as &$subTree) {
				$subTree = $this->prepQuery($subTree);
			}
		}

		return $tree;
	}  // eo process union



	/**
	* Prepare full parsed query tree
	* @params $tree: Parsed and tokenized sql template tree
	*/
	public function prepQuery($tree) {

		if ($tree['SELECT']) {
			$tree['SELECT'] = $this->prepSequence($tree['SELECT']);
		}

		if ($tree['FROM']) {
			$tree['FROM'] = $this->prepSequence($tree['FROM']);
		}

		if ($tree['WHERE']) {
			$tree['WHERE'] = $this->prepWhere($tree['WHERE']);
			if (!$tree['WHERE']) {
				unset($tree['WHERE']);
			}
		}

		if ($tree['GROUP']) {
			$tree['GROUP'] = $this->prepGroupBy($tree['GROUP']);
		}

		if ($tree['HAVING']) {
			$tree['HAVING'] = $this->prepWhere($tree['HAVING']);
			if (!$tree['HAVING']) {
				unset($tree['HAVING']);
			}
		}

		if ($tree['ORDER']) {
			$tree['ORDER'] = $this->prepOrderBy($tree['ORDER']);
			if (!$tree['ORDER']) {
				unset($tree['ORDER']);
			}
		}

		if ($tree['LIMIT']) {
			$tree['LIMIT'] = $this->prepLimit($tree['LIMIT']);
			if (!$tree['LIMIT']) {
				unset($tree['LIMIT']);
			}
		}

		return $tree;
	}  // eo process full query


	/**
	* Prepare token sequence. Non-WHERE sequences to be explicit.
	* @params $sequences: A token sequence.
	*/
	public function prepSequence($sequence) {

		$sequenceOut = array();

		foreach ((array)$sequence as $key => $token) {
			if ($token['sub_tree']) {
				$token = $this->prepSubtree($token);
				// if token subtree is empty after preparation, then we ignore
				if (!$token['sub_tree']) {
					continue;
				}
			}
			$sequenceOut[] = $token;
		}

		return $sequenceOut;
	}  // eo process SELECT segment


	/**
	* Prepare single subtree token
	* @params $token: The subtree token.
	*/
	public function prepSubtree($token, $whereMode = false) {


		// resolve special cases

		// handle subqueries
		if ($token['expr_type'] ==  "subquery") {
			$token['sub_tree'] = $this->prepQuery($token['sub_tree']);
			return $token;
		}

		// handle bracket expression (maybe incomplete)
		// primary used with subqueries but also for embraced calculations
		// like "(col1 * col2) AS newCol"
		if ($token['expr_type'] == "bracket_expression") {
			if ($whereMode) {
				$token['sub_tree'] = $this->prepWhere($token['sub_tree']);
			}
			else {
				// Maybe bracket_expression only exists in where clauses, but we dont know -
				// so throw an exeption to detect it.
				//throw new Exception("Found bracket_expression in prepSubtree mode without whereMode.");
			}
			return $token;
		}


		// handle in-list of IN clause
		if ($token['expr_type'] == "in-list") {
			if ($whereMode) {
				$token['sub_tree'] = $this->prepWhere($token['sub_tree']);
			}
			else {
				// in-list only exists in where clause
				throw new Exception("Found in-list in prepSubtree mode without whereMode.");
			}
			return $token;
		}


		// otherwise the subtree is expected to be another sql sequence
		$token['sub_tree'] = $this->prepSequence($token['sub_tree']);


		return $token;
	}  // eo prep subtree


	/**
	* Check if token is AND / OR token of a WHERE clause
	* @params $token:
	*/
	public function isAndOrToken($token) {

		if ($token['expr_type'] == "operator") {
			$uTok = strtoupper($token['base_expr']);
			if ($uTok == "AND" || $uTok == "OR") {
				return true;
			}
		}
		return false;
	}  // eo is and/or token


	/**
	* Prepare WHERE (or HAVING) tree with data from extjs request.
	* @params $sequcence
	*/
	public function prepWhere($sequence) {

		// get extjs filter from request
		$extjsFilter = $this->getStoreFilter();

		$parts = array();
		$queue = array();

		// split into AND/OR parts
		foreach ((array)$sequence as $token) {

			if ($this->isAndOrToken($token)) {
				$parts[] = $queue;
				$queue = array();
			}
			$queue[] = $token;
		}
		if ($queue) {
			$parts[] = $queue;
		}


		$sequenceOut = array();
		foreach ($parts as $andOrSeq) {

			$usePart = true;
			$tmpParamValues = array();

			// check one and/or sequence
			// ALL template expressions have to match,
			// otherwise the FULL and/or sequence is discarded
			$tmpAndOrSeq = array();
			foreach ($andOrSeq as $token) {

				// we are only interested in named sql params "`?xxx`"
				if (!($token['expr_type'] == "colref" &&
							$this->isTplExpr($token['base_expr']))
					 ) {
					$tmpAndOrSeq[] = $token;
					continue;
				}

				// begin prep named sql params
				$usePart = false;
				$pnamOri = $token['base_expr'];
				$pnam = $this->untagTplExpr($pnamOri);

				// detect internal commands in first char
				$addColonPrefix = false;
				$doRemovePnam = false;
				$isRequiredParam = false;
				$onlyIfHasValue = false;
				//$onlyIfHasTrimmedValue = false;
				$isMultiParam = false;

				// loop over internal command chars
				$cmdCharLoop = true;
				while ($cmdCharLoop) {

					// add colon in final where clause
					if (substr($pnam, 0, 1) == ":") {
						$addColonPrefix = true;
						$pnam = substr($pnam, 1);
						continue;
					}
					// test if pnam exists and use part, butremove pnam afterwards
					if (substr($pnam, 0, 1) == "-") {
						$doRemovePnam = true;
						$pnam = substr($pnam, 1);
						continue;
					}
					// throw exption if pnam does not exist
					if (substr($pnam, 0, 1) == "^") {
						$isRequiredParam = true;
						$pnam = substr($pnam, 1);
						continue;
					}
					// use only if not empty (untrimmed) (but may be trimmed when doing global request preparing)
					// TODO extra cmd-char for: not-empty (trimmed) ???
					if (substr($pnam, 0, 1) == "+") {
						$onlyIfHasValue = true;
						$pnam = substr($pnam, 1);
						continue;
					}
					// create a list of numbered params (e.g. for IN clause)
					// for now only "," is a valid delimiter (in and out)
					// TODO allow other in-delimiter (syntax e.g. `?#;#:foo`) ???
					if (substr($pnam, 0, 1) == "#") {
						$isMultiParam = true;
						$multiDelimIn = ",";
						$pnam = substr($pnam, 1);
						continue;
					}

					$cmdCharLoop = false;
				}  // eo internal cmd check


				// ---
				// check if key exists and get value

				// if pnam already in param vals, then we use this
				if (array_key_exists($pnam, $this->paramValues)) {
					$value = $this->paramValues[$pnam];
					$usePart = true;
				}
				// otherwise if pnam exists in extjs filter vals then we take this
				elseif (array_key_exists($pnam, $extjsFilter)) {
					$value = $extjsFilter[$pnam];
					$usePart = true;
				}
				// otherwise if pnam elsewhere in values (request) then we take this
				elseif (array_key_exists($pnam, $this->request)) {
					$value = $this->request[$pnam];
					$usePart = true;
				}

				// ---
				// handle special internal commands and special cases

				// check if it is a required param
				// if pnam not present till now then throw an exeption
				if ($isRequiredParam && !$usePart) {
					throw new Exception("Required parameter name '$pnam' not in value array (request) for {$this->template}.");
				}

				// if value-content is required but not present
				// then exlude if value is not present
				if ($onlyIfHasValue && !$value) {
					$usePart = false;
					break;
				}

				// final test, if part is used at all
				if (!$usePart) {
					break;
				}

				if ($doRemovePnam) {
					continue;  // next token - use part, but remove pnam
				}

				// polish pnam
				if ($addColonPrefix) {
					$pnamOut = ":{$pnam}";
				}
				else {
					$pnamOut = $pnam;
				}

				// for mulit param we repeat for each item of the value list
				if ($isMultiParam) {

					$multiVals = (is_string($value) ? explode($multiDelimIn, $value) : $value);

					$tmpCnt = 0;
					foreach ((array)$multiVals as $tmpVal) {

						$tmpCnt++;
						$multiPnam = $pnam . $tmpCnt;
						$multiPnamOut = $pnamOut . $tmpCnt;

						$tmpParamValues[$multiPnam] = $tmpVal;

						$token['base_expr'] = $multiPnamOut;
						$token['no_quotes'] = $multiPnamOut;
						$tmpAndOrSeq[] = $token;
					}

					continue;
				}  // eo multiparam


				// add value and standard token to sequence
				$tmpParamValues[$pnam] = $value;

				$token['base_expr'] = $pnamOut;
				$token['no_quotes'] = $pnamOut;
				$tmpAndOrSeq[] = $token;

				// end of prep named sql params

			}  // eo loop over all tokens of one part
			$andOrSeq = $tmpAndOrSeq;

			if (!$usePart) {
				continue;
			}

			// remove and/or glue if first part of sequence
			$andOrGlueToken = null;
			if ($this->isAndOrToken($andOrSeq[0])) {
				$andOrGlueToken = array_shift($andOrSeq);
			}

			// prep subtrees
			$tmpAndOrSeq = array();
			foreach ($andOrSeq as $key => $token) {
				if ($token['sub_tree']) {
					$token = $this->prepSubtree($token, true);
					// if token subtree is empty, then we ignore the full andOrSequence,
					// because otherwise the expression is incomplete in most cases !!!
					// Maybe we ingnore too much this way, but we have a valid sql syntax
					if (!$token['sub_tree']) {
						$tmpAndOrSeq = array();
						break;
					}
				}
				$tmpAndOrSeq[] = $token;
			}  // eo prep subtoken
			$andOrSeq = $tmpAndOrSeq;

			// do not use empty parts
			if (!count($andOrSeq)) {
				continue;
			}

			// all tests passed - use part and remember param values
			if (count($sequenceOut) > 0) {
				$sequenceOut[] = $andOrGlueToken;
			}
			$sequenceOut = array_merge($sequenceOut, $andOrSeq);
			$this->paramValues = array_merge($this->paramValues, $tmpParamValues);

		}  // eo loop over all parts

		return $sequenceOut;
	}  // eo WHERE with ext



	/**
	* Prepare ORDER BY clause with data from extjs request.
	* @params $tpl: The template containing special sql
	* Info: The key id for the default sort is the empty string ("")
	*/
	public function prepOrderBy($sequence) {

		// get store sorter and do sanity check
		$extjsSorters = $this->getStoreSort();
		foreach ($extjsSorters as $colName => &$direct) {
			$direct = trim(strtoupper($direct));
			if (!$direct) {
				$direct = "ASC";
			}
			if (!($direct == "ASC" || $direct == "DESC")) {
				throw new Exception("Invalid direction '{$direct}' for column name '{$colName}' in ExtJS sort.");
			}
		}  // eo sanity check


		// replace / remove template token with sql expression
		$defaultSortSeq = array();
		$sequenceOut = array();
		foreach ($sequence as $token) {

			if ($token['expr_type'] == "colref" &&
					$this->isTplExpr($token['base_expr'])) {

				$aExpr = $this->unpackOrderByExpr($token['base_expr']);
				$key = $aExpr['key'];
				$orderSeq = $aExpr['seq'];

				// catch default sort
				if (!$key) {
					$defaultSortSeq = $orderSeq;
					continue;
				}

				// if there is no extjs sort info for this token then we skip
				if (!$extjsSorters[$key]) {
					continue;
				}

				// if order seq is empty, then we replace with default order
				// if there is no default order we skip
				// ATTENTION: default order must be BEFORE using reference to it
				if (!$orderSeq) {
					if (!$defaultSortSeq) {
						continue;
					}
					$orderSeq = $defaultSortSeq;
				}

				// replace template with order sequence
				foreach ((array)$orderSeq as $newToken) {
					$extDirect = $extjsSorters[$key];
					// if the original template direction is DESC
					// we reverse the direction of extjs sorts
					if ($newToken['direction'] == "DESC") {
						$extDirect = ($extDirect == "ASC" ? "DESC" : "ASC");
					}
					if ($newToken['forceDirection']) {
						$extDirect = $newToken['forceDirection'];
						unset($newToken['forceDirection']);
					}
					$newToken['direction'] = $extDirect;
					$sequenceOut[] = $newToken;
				}  // eo column loop

			}  // template expr
			else {
				// add unchanged token to out sequence
				$sequenceOut[] = $token;
			}

		}  // eo prep loop


		// if there is no order by token, but there is a extjs sorter
		// this means that the extjs sorter doesnt match any template sorter.
		// In this case we throw an exeption. The default sort is only
		// used if no extjs sorter is present
		if (!$sequenceOut && $extjsSorters) {
			echo Extjs::errorMsg(sprintf("Invalid Sort '%s'.", current(array_keys($extjsSorters))));
			exit;
		}

		// if there is no order by token, but we have a default sort
		// then we use the default cols to create order by tokens
		// TODO delegate token creation to a createOrderByToken function?
		if (!$sequenceOut && $defaultSortSeq) {
			foreach ($defaultSortSeq as $newToken) {
				$sequenceOut[] = $newToken;
			}  // eo col loop
		}  // eo default sort

		return $sequenceOut;
	}  // eo ORDER BY with ext



	/**
	* Prepare GROUP BY clause with data from extjs request.
	* @params $tpl: The template containing special sql
	*/
	public function prepGroupBy($sequence) {

		// extjs 5.1 has no remote group config any more
		return $sequence;
	}  // eo GROUP BY with ext


	/**
	* Prepare limit
	* @params $token: The LIMIT token.
	* TODO: handle subqueries and function calls in limit params
	*/
	public function prepLimit($token) {

		$extLimit = $this->getStoreLimit();

		if ($this->unEnc($token['offset']) == "?start") {
			$token['offset'] = $extLimit['start'];
		}

		if ($this->unEnc($token['rowcount']) == "?limit") {
			$token['rowcount'] = $extLimit['limit'];
		}

		if (!($token['offset'] || $token['rowcount'])) {
			return array();
		}

		return $token;
	}  // eo prep limit



	/*
	 * Check if field is enclosed
	 */
	public function isEnc($field) {
		return (substr($field, 0, 1) == static::$sqlEncBegin &&
						substr($field, -1) == static::$sqlEncEnd);
	}  // eo is enc


	/*
	 * Remove enclosing chars
	 */
	public function unEnc($field) {
		return ($this->isEnc($field) ? substr($field, 1, -1) : $field);
	}  // eo remove enc


	/*
	 * Check if value is template expression
	 */
	public function isTplExpr($expr) {
		return (substr(trim($this->unEnc($expr)), 0, 1) == "?");
	}  // eo is tpl expr


	/*
	 * Remove template marker
	 */
	public function untagTplExpr($expr) {
		return ($this->isTplExpr($expr) ? trim(substr(trim($this->unEnc($expr)), 1)) : $expr);
	}  // eo remove tpl marker


	/*
	 * Unpack / eval template where expressions
	 */
	public function unpackWhereExpr($expr) {
		// TODO - handled directly in prepWhere for now
	}  // eo unpack where expr


	/*
	 * Unpack / eval template orderby expressions
	 * The key id for the default sort is the empty string ("")
	 * Allow multiple columns per key - separated by commas (",")
	 */
	public function unpackOrderByExpr($expr) {

		$expr = $this->untagTplExpr($expr);

		if (strpos($expr, "=") !== false) {
			list($key, $expr) = explode("=", $expr, 2);
			$key = trim($key);
			$expr = trim($expr);
		}
		else {
			// if only colname is given then sort expression equals to colname
			$key = $expr;
		}

		// if a key, but no expression is given, then the
		// seq remain empty and is replaced with the default sort later
		if ($expr) {
			$parser = new PHPSQLParser\PHPSQLParser();
			$parsed = $parser->parse("SELECT * FROM dummy ORDER BY {$expr}");
			$seq = $parsed['ORDER'];
			unset($parser);
		}

		return array("key" => $key, "seq" => $seq);
	}  // eo unpack order expr






}  // end of class
?>
