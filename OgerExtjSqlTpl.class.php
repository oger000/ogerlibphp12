<?PHP
/*
#LICENSE BEGIN
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
	* Prepare select statement with data from extjs request.
	* WORK IN PROGRESS
	* @params $tpl: The template containing special sql
	*         Variables are detectec by the question mark ("?") prefix.
	*/
	public function prepare($tpl, $req = null, $tplOpts = null) {
static::$devDebug = true;
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

		if (!$tplOpts['skip-php-sql-oger-prepare']) {
			$this->prepared = $this->prepQuery($this->parsed);
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
if (static::$devDebug2) {
	Oger::debugFile(var_export($this->sql, true));
	//exit;
}

		return $this->sql;
	}  // eo prep sql with extjs request



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
	* Prepare token sequence.
	* @params $sequences: A token sequence.
	*/
	public function prepSequence($sequence) {

		$sequenceOut = array();

		foreach ((array)$sequence as $key => $token) {
			if ($token['sub_tree']) {
				$token = $this->prepSubtree($token);
				// if token subtree is empty, then we ignore
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

		switch ($token['expr_type']) {
		case "aggregate_function":
			// TODO
			break;
		case "bracket_expression":
			if ($whereMode) {
				$token['sub_tree'] = $this->prepWhere($token['sub_tree']);
			}
			else {
				//throw new Exception("Found bracket_expression in prepSubtree mode without whereMode.");
				// do nothing
			}
			break;
		case "subquery":
			$token['sub_tree'] = $this->prepQuery($token['sub_tree']);
			break;
		default:
			throw new Exception("Unknown prepSubtree mode: {$token['expr_type']}.");
		}

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
		$extFilter = $this->getStoreFilter();

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

			$tmpAndOrSeq = array();
			foreach ($andOrSeq as $token) {

				// we are only interested in named sql params "`?:xxx`"
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
				elseif (array_key_exists($pnam, $extFilter)) {
					$value = $extFilter[$pnam];
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

				// final test if part is used
				if (!$usePart) {
					break;
				}

				if ($doRemovePnam) {
					continue;  // next token - use part, but not pnam
				}

				// polish pnam
				if ($addColonPrefix) {
					$pnamOut = ":{$pnam}";
				}
				else {
					$pnamOut = $pnam;
				}

				$token['base_expr'] = $pnamOut;
				$token['no_quotes'] = $pnamOut;

				$tmpAndOrSeq[] = $token;

				// end prep named sql params

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
					// if token subtree is empty, then we ignore
					if (!$token['sub_tree']) {
						continue;
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

		// extract all template items
		$orderByToken = array();
		foreach ($sequence as $token) {

			if ($token['expr_type'] == "colref" &&
					$this->isTplExpr($token['base_expr'])) {

				$aExpr = $this->unpackOrderByExpr($token['base_expr']);
				$orderByToken[$aExpr['key']] = $aExpr['cols'];

			}  // eo template token
		}  // eo read template

		// if there are no template tokens return the sequence unchanged
		if (!$orderByToken) {
			return $sequence;
		}

		// postprocess template token (handle default sort)
		// if sort key has no sort expression then use default sort
		// if no default sort exists then remove key completly
		$defaultCols = $orderByToken[''];
		unset($orderByToken['']);
		foreach($orderByToken as $key => $cols) {
			if (!$cols) {
				if ($defaultCols) {
					$orderByToken[$key] = $defaultCols;
				}
				else {
					unset($orderByToken[$key]);
				}
			}
		} // eo post prep tokens


		// get store sorter and do sanity check
		$extSort = $this->getStoreSort();
		foreach ($extSort as $colName => &$direct) {
			$direct = trim(strtoupper($direct));
			if (!$direct) {
				$direct = "ASC";
			}
			if (!($direct == "ASC" || $direct == "DESC")) {
				throw new Exception("Invalid direction '{$direct}' for column name '{$colName}' in ExtJS sort.");
			}
		}  // eo sanity check


		// replace / remove template token with sql expression
		$sequenceOut = array();
		foreach ($sequence as $token) {

			if ($token['expr_type'] == "colref" &&
					$this->isTplExpr($token['base_expr'])) {

				$aExpr = $this->unpackOrderByExpr($token['base_expr']);
				$key = $aExpr['key'];

				// if there is no extjs sort info for this token then we skip
				if (!$extSort[$key]) {
					continue;
				}

				// replace template with prepared values
				foreach ($aExpr['cols'] as $colName) {
					$token['base_expr'] = $colName;
					//$token['no_quotes']['parts'] = array($colName);
					$token['direction'] = $extSort[$key];
					$sequenceOut[] = $token;
				}  // eo column loop

			}  // template expr
			else {
				// add unchanged token to out sequence
				$sequenceOut[] = $token;
			}

		}  // eo prep loop

		// if there is no order by token but we have a default sort
		// then we use the default cols to create order by tokens
		// TODO delegate token creation to a createOrderByToken function?
		if (!$sequenceOut && $defaultCols) {
			foreach ($defaultCols as $colName) {
				$sequenceOut[] = array(
					'expr_type' => 'colref',
					'base_expr' => $colName,
					'no_quotes' => array (
						'delim' => false,
						'parts' => array (
							0 => $colName,
						),
					),
					'sub_tree' => false,
					'direction' => 'ASC',
				);
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

		// if there is no store limit, then remove the limit token at all
		if (!$extLimit['limit']) {
			return array();
		}

		if ($this->unEnc($token['offset']) == "?start") {
			$token['offset'] = $extLimit['start'];
		}

		if ($this->unEnc($token['rowcount']) == "?limit") {
			$token['rowcount'] = $extLimit['limit'];
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

		$cols = explode(",", $expr);

		return array("key" => $key, "cols" => $cols);
	}  // eo unpack order expr






}  // end of class
?>
