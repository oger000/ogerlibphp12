<?PHP
/*
#LICENSE BEGIN
#LICENSE END
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

    if ($req === null) {
      $req = $_REQUEST;
    }
    $this->request = $req;
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
  public function getParamValues() {
    return $this->paramValues;
  }  // eo get param values



  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST



  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public function prepare($tpl, $req = null) {
static::$devDebug = true;
static::$devDebug2 = true;
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
}

    $this->prepared = $this->prepQuery($this->parsed);
if (static::$devDebug) {
  Oger::debugFile("prepared=\n" . var_export($this->prepared, true));
//  exit;
}

    // create sql from prepared parser tree
    $creator = new PHPSQLParser\PHPSQLCreator();
    $this->sql = $creator->create($this->prepared);
if (static::$devDebug2) {
  Oger::debugFile(var_export($this->sql, true));
  exit;
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
  * @params $sequcence:
  *         Variables are detectec by the colon (:) prefix.
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

        // we are only interested in named sql params ":?xxx" or "`:?xxx`"
        if (!($token['expr_type'] == "colref" &&
              substr($this->unEnc($token['base_expr']), 0, 2) == ":?")
           ) {
          $tmpAndOrSeq[] = $token;
          continue;
        }

        // begin prep named sql params
        $usePart = false;
        $pnamOri = $token['base_expr'];
        $pnamOriUnenc = $pnamOri;
        $isEnc = false;

        if ($this->isEnc($pnamOri)) {
          $pnamOriUnenc = $this->unEnc($pnamOri);
          $isEnc = true;
        }

        // remove leading ":?"
        $pnam = substr($pnamOriUnenc, 2);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;  // obsoleted for now
        $doForceAddParam = false;  // obsoleted
        $isZombieParam = false;  // obsoleted (followup to doForceAddParam)
        $onlyIfHasValue = false;
        //$trimmedValueRequired = false;

        // loop over internal command chars
        $cmdCharLoop = true;
        while ($cmdCharLoop) {

          // remove colon in final where clause
          if (substr($pnam, 0, 1) == "=") {  // was: "-"
            $doRemoveColon = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // test if pnam exists and remove pnam afterwards
          if (substr($pnam, 0, 1) == "-") {  // was: "?"
            $doRemovePnam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // throw exption if pnam does not exist -> delegated to execute?
          /*
          if (substr($pnam, 0, 1) == "^") {  // was: "!"
            $isRequiredParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          */
          // add pnam even if not exits in value arra
          // obsolete, because of the ":?" syntax
          /*
          if (substr($pnam, 0, 1) == "?") {
            $doForceAddParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          */
          // use only if not empty (untrimmed) (but may be trimmed when doing global request preparing)
          // TODO extra cmd-char for: not-empty (trimmed) ???
          if (substr($pnam, 0, 1) == "+") {  // alternate: "~" or "#"
            $onlyIfHasValue = true;
            $pnam = substr($pnam, 1);
            continue;
          }

          $cmdCharLoop = false;
        }  // eo internal cmd check


        // check if key exists and get value
        //
        // if pnam already in where vals, then we do nothing
        if (array_key_exists($pnam, $this->paramValues)) {
          $value = $this->paramValues[$pnam];
          // already done
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
        // handle special internal commands and special cases
        //
        // if param is forced then add part even if pnam not present
        // the user is responsible to provide the key and the value elsewhere
        if ($doForceAddParam) {
          $isZombieParam = true;
          $usePart = true;
        }

        // otherwise check if it is a required param
        // if not present till now throw an exeption
        if ($isRequiredParam && !$usePart) {
          throw new Exception("Required parameter '$pnam' not in value array for {$this->template}.");
        }

        // otherwise if value is required but not present
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
          continue;  // next token
        }

        // polish pnam
        if ($doRemoveColon) {
          $pnamOut = $pnam;
        }
        else {
          // reasign colon and remember value
          $pnamOut = ":{$pnam}";
          // do not add zombie parameters - they come from elsewhere
          if (!$isZombieParam) {
            $tmpParamValues[$pnam] = $value;
          }
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
  * Info: The key id for the default sort is "="
  */
  public function prepOrderBy($sequence) {


    // extract all template items
    $tplToken = array();
    foreach ($sequence as $token) {

      if ($token['expr_type'] == "colref" &&
          substr($this->unEnc($token['base_expr']), 0, 1) == "?") {

        $expr = substr($this->unEnc($token['base_expr']), 1);
        if (strpos($expr, "=") !== false) {
          list($key, $expr) = explode("=", $expr, 2);
          $key = trim($key);
          $expr = trim($expr);
          // if no key is given then we use the default sort key
          if (!$key) {
            $key = "=";
          }
        }
        else {
          // if only colname is given then sort expression is equal to colname
          $key = $expr;
        }
        $tplToken[$key] = $expr;

      }  // eo template token
    }  // eo read template

    // if there are no template tokens return the sequence unchanged
    if (!$tplToken) {
      return $sequence;
    }

    // postprocess template token
    // if sort key has no sort expression then use default sort
    // if no default sort exists remove key
    $defaultSort = $tplSorter['='];
    foreach($tplToken as $key => $expr) {
      if (!$expr) {
        if ($defaultSort) {
          $tplToken[$key] = $defaultSort;
        }
        else {
          unset($tplToken[$key]);
        }
      }
    } // eo post prep tokens
Oger::debugFile("tplToken=\n" . var_export($tplToken, true));


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

Oger::debugFile("extSort=\n" . var_export($extSort, true));

    // replace / remove template token with sql expression
    $sequenceOut = array();
    foreach ($sequence as $token) {

      if ($token['expr_type'] == "colref" &&
          substr($this->unEnc($token['base_expr']), 0, 1) == "?") {

        $key = trim(substr($this->unEnc($token['base_expr']), 1));
        if (strpos($expr, "=") !== false) {
          list($key, $expr) = explode("=", $key, 2);
          $key = trim($key);
          // if no key is given then we use the default sort key
          if (!$key) {
            $key = "=";
          }
        }

        // if there is no extjs sort info for this token then we skip
        if (!$extSort[$key]) {
Oger::debugFile("dont find key {$key}.\n");
          continue;
        }

        // replace template with prepared value
        $token['base_expr'] = $tplToken[$key];
        //$token['no_quotes']['parts'] = array($tplToken[$key]);
        $token['direction'] = $extSort[$key];
      }  // template expr

      // add token to out sequence
      $sequenceOut[] = $token;

    }  // eo prep loop


Oger::debugFile("orderbyOut=\n" . var_export($sequenceOut, true));
    return $sequenceOut;
  }  // eo ORDER BY with ext



  /**
  * Prepare GROUP BY clause with data from extjs request.
  * @params $tpl: The template containing special sql
  */
  public function prepGroupBy($tpl, $req = null) {

    throw new Exception("Function 'prepGroupBy' not implemented.");


    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*GROUP\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }


    // extract extra group field info from template
    $parts = explode(";", $tpl);
    $tplSorter = array();
    foreach ((array)$parts as $value) {
      $value = trim($value);
      if (!$value) {
        continue;
      }
      if (strpos($value, "=") !== false) {
        list($key, $value) = explode("=", $value, 2);
        $key = trim($key);
        $value = trim($value);
      }
      else {  // for stand alone colnames group expression is same as colname
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // if no group expression is present then fill with default group
    // if no default group exists remove group key
    $defaultSort = $tplSorter[''];
    foreach($tplSorter as $key => $value) {
      if (!$value) {
        if ($defaultSort) {
          $tplSorter[$key] = $defaultSort;
        }
        else {
          unset($tplSorter[$key]);
        }
      }
    }

    // convert sort info from json to array
    $req['sort'] = $this->getStoreSort(null, $req);

    // loop over sort info from ext
    foreach ((array)$req['sort'] as $colName => $direct) {

      if ($direct &&  $direct != "ASC" && $direct != "DESC" && trim($direct) != "") {
        throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
      }

      // apply sort expression from template when exists,
      // otherwise ignore sort request
      $sortExpr = trim($tplSorter[$colName]);
      if (!$sortExpr) {
        continue;
      }

      // compose sql
      // if sort direction placeholder exists replace ALL placeholder
      // otherwise append direction if given
      $tmpSql = $sortExpr;
      if (strpos($tmpSql, "__EXTJS_DIRECTION__") !== false) {
        $tmpSql = str_replace("__EXTJS_DIRECTION__", $direct, $tmpSql);
      }
      elseif($direct) {
        $tmpSql .= " $direct";
      }

    }  // eo ext sort item loop

    $sql = trim($sql);

    // if no order-by sql is composed, then use the template default sort
    // but remove direction placeholder fist
    if (!$sql && $defaultSort) {
      $sql = str_replace("__EXTJS_DIRECTION__", "", $defaultSort);
    }


    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = " {$kw} {$sql} ";
    }


    return $sql;
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

    if (substr($field, 0, 1) == static::$sqlEncBegin &&
        substr($field, -1) == static::$sqlEncEnd) {

      return true;
    }

    return false;
  }  // eo is enc


  /*
   * Remove enclosing chars
   */
  public function unEnc($field) {

    if ($this->isEnc($field)) {
      $field = substr($field, 1, -1);
    }

    return $field;
  }  // eo is enc





}  // end of class
?>
