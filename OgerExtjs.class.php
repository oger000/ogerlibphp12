<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Some methods to simplify Extjs responses.
*/
class OgerExtjs {

  /// Debug flag.
  //public static $debug = true;
  public static $debug = false;



  /**
  * Encode a php array into json.
  * @param $arr The array with values.
  * @param $success Boolean flag for the success property. Defaults to true.<br>
  *        - Null: Do not set a new and do not change an existing success property.
  * @return Json encoded array.
  * For content type see <http://www.ietf.org/rfc/rfc4627.txt>
  * and <http://stackoverflow.com/questions/477816/what-is-the-correct-json-content-type>
  * and <http://stackoverflow.com/questions/267546/correct-content-type-http-header-for-json>
  *    For JSON: header('Content-Type: application/json');
  *    For JSON-P: header('Content-Type: application/javascript');
  */
  public static function enc($arr = array(), $success = true) {
    if ($success !== null) {
      $arr["success"] = (boolean)$success;
    }
    header("Content-Type: application/json");
    return json_encode($arr);
  }  // eo json encoded array


  /**
  * Encode data from a php array into json.
  * @param $arr The array with the data values.
  * @param $dataRoot Name of the data root property. Defaults to "data".
  * @return Json encoded array.
  */
  public static function encData($data = array(), $other = array(), $dataRoot = null, $totalName = null) {

    if (!$dataRoot) {
      $dataRoot = "data";
    }

    if (!$totalName) {
      $totalName = "total";
    }

    if (!is_array($other)) {
      // numeric primitive-type is reserved for total count in paging grids
      if (is_numeric($other)) {
        $other = array($totalName => intval($other));
      }
      else {  // otherwise we ignore the more param if not an array
        $other = array();
      }
    }

    $all = array($dataRoot => $data);
    $all = array_merge($other, $all);

    return static::enc($all, true);
  }  // eo json encoded data array


  /**
  * Encode a message.
  * @param $msg The error message.
  * @param $usccess True for success messages otherwise false.
  * @return Json encoded array.
  */
  public static function msg($msg, $success = true) {
    return static::enc(array("msg" => $msg), $success);
  }  // eo msg


  /**
  * Encode an error message.
  * @param $msg The error message.
  * @return Json encoded array.
  */
  public static function errorMsg($msg) {
    return static::msg($msg, false);
  }  // eo errorMsg



  /**
  * Get filter data from extjs request.
  * @params $filterName: Name of the filter.
  *         $req: Request array.
  */
  public static function getStoreFilter($filterName = null, $req = null) {

    if ($filterName === null) {
      $filterName = "filter";
    }
    if ($req === null) {
      $req = $_REQUEST;
    }

    // no filter or filter is empty
    if (!$req[$filterName]) {
      return array();
    }

    // filter is already an array
    if (is_array($req[$filterName])) {
      return $req[$filterName];
    }

    // prepare filter
    $filter = array();
    $items = json_decode($req[$filterName], true);
    foreach ((array)$items as $item) {
      $filter[$item['property']] = $item['value'];
    }

    return $filter;
  }  // eo get ext filter


  /**
  * Get sort data from extjs request.
  * @params $sortName: Name of the sort variable.
  *         $req: Request array.
  */
  public static function getStoreSort($sortName = null, $req = null) {

    if ($sortName === null) {
      $sortName = "sort";
    }
    if ($req === null) {
      $req = $_REQUEST;
    }

    // no sort or sort is empty
    if (!$req[$sortName]) {
      return array();
    }

    // sort is already an array
    if (is_array($req[$sortName])) {
      return $req[$sortName];
    }

    // prepare sort
    $sort = array();
    $items = json_decode($req[$sortName], true);
    foreach ((array)$items as $item) {
      $sort[$item['property']] = $item['direction'];
    }

    return $sort;
  }  // eo get ext sort


  /**
  * Get sql limit.
  * @params $limitName: Name of the limit variable.
  *         $req: Request array.
  */
  public static function getStoreLimit($limitName = null, $startName = null, $req = null) {

    if ($limitName === null) {
      $limitName = "limit";
    }
    if ($startName === null) {
      $startName = "start";
    }
    if ($req === null) {
      $req = $_REQUEST;
    }

   // no limit or limit is empty or non-numeric
    if (!array_key_exists($limitName, $req) || !is_numeric($req[$limitName])) {
      return "";
    }
    $limit = "" . intval($req[$limitName]);

    // start only makes sense if limit is in prep
    if (array_key_exists($startName, $req) && is_numeric($req[$startName])) {
      $limit = "" . intval($req[$startName]) . ",$limit";
    }

    return $limit;
  }  // eo get limit



  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST - PREG / STRREPL MODE



  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSql($tpl, &$seleVals = array(), $req = null) {

    if ($seleVals === null) {
      $seleVals = array();
    }
    if ($req === null) {
      $req = $_REQUEST;
    }


    // SELECT - obsoleted by now, only removes brackets
    if (preg_match("/\{\s*SELECT\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo select


    // WHERE
    if (preg_match_all("/\{\s*WHERE\s.*?\}/is", $tpl, $matches)) {
      foreach ($matches[0] as $ori) {
        $prep = static::extjSqlWhere(substr($ori, 1, -1), $seleVals, $req);
        $tpl = str_replace($ori, $prep, $tpl);
      }
    }  // eo where


    // GROUP BY
    // ATTENTION: extjSqlGroupBy() is totaly untested, so disable by now !!!
    /*
    if (preg_match("/\{\s*GROUP\s+BY\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = static::extjSqlGroupBy(substr($ori, 1, -1));
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo group by
    */


    // HAVING
    if (preg_match("/\{\s*HAVING\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = static::extjSqlWhere(substr($ori, 1, -1), $seleVals, $req);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo having


    // ORDER BY
    if (preg_match("/\{\s*ORDER\s+BY\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = static::extjSqlOrderBy(substr($ori, 1, -1), $req);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo order by


    // LIMIT
    $ori = "__EXTJS_LIMIT__";
    if (strpos($tpl, $ori) !== false) {
      $prep = static::extjSqlLimit($req);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo limit


    return $tpl;
  }  // eo select with ext



  /**
  * Prepare WHERE (or HAVING) clause with data from extjs request.
  * WORK IN PROGRESS
  * Whitespaces are collapsed sometimes, so formating of sql will be lost.
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlWhere($tpl, &$whereVals = array(), $req = null, $opts = array()) {
//Oger::debugFile("tpl=$tpl");


    $tplOri = $tpl;

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // get extjs filter from request
    $req['filter'] = self::getStoreFilter(null, $req);
    $extFilter = array();
    foreach ((array)$req['filter'] as $colName => $value) {
      $extFilter[$colName] = $value;
    }  // eo filter item loop


    // detect and remove enclosing {}
    $tpl = self::extjSqlStrip($tpl);

    // detect, save and remove leading where keyword
    if (preg_match("/^\s*(WHERE|HAVING)\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }  // keyword

    // split at and/or boundery (TODO may be problems without leading whitespace)
    $parts = preg_split("/(\s+OR\s+|\s+AND\s+)/i", $tpl, null, PREG_SPLIT_DELIM_CAPTURE);
    while (count($parts)) {  // we play with shift/unshift, so do not use foreach
      $part = array_shift($parts);

      // detect and/or glue and remember (and reset NOT keyword - detected later)
      $tmp = strtoupper(trim($part));
      if ($tmp == "OR" || $tmp == "AND") {
        $andOrGlue = $part;
        $kwNOT = "";
        continue;
      }

      // detect leading NOT and remember
      if (preg_match("/^\s*NOT\s+/i", $part, $matches)) {
        $kwNOT = strtoupper(trim($matches[0]));
        $part = implode("", explode($kwNOT, $part, 2));
      }


      $part = trim($part);

      // handle grouping of conditions by parenthesis
      // check for first opening parenthesis
      // if the skipParenthesis parameter is given we have not a "full enclosed"
      // WHERE clause but another term that starts with "("
      // e.g. a subquery like "(SELECT * FROM ..." or something like that
      if (substr(ltrim($part), 0, 1) == "(" && !$opts['skipParenthesis']) {

        $parenthTpl = "";

        // put current part back because it is called again
        array_unshift($parts, $part);

        // loop till closing parenthesis
        while (count($parts)) {

          $tmpPart = trim(array_shift($parts));

          // add full part
          $parenthTpl .= ($parenthTpl ? " " : "") . $tmpPart;

          // check parenthesis balance
          $parenthCount = 0;
          $strDelimChar = "";
          $litStr = "";

          for ($i=0; $i < strlen($parenthTpl); $i++) {

            $ch = $parenthTpl[$i];

            // skip literal strings
            if ($strDelimChar) {  // we are inside a literal string
              $litStr .= $ch;  // collect string for possible error message
              if ($ch == "\\") {  // handle backslash escaping
                $litStr .= $parenthTpl[$i + 1];
                $i++;  // skip next char - never mind which
                continue;
              }
              if ($ch == $strDelimChar) {  // end of string char detected
                // handle escaping by duplicate delimiter
                if ($parenthTpl[$i + 1] == $strDelimChar) {
                  $litStr .= $parenthTpl[$i + 1];
                  $i++;  // skip duplicate-escaped delimiter
                  continue;
                }
                $strDelimChar = "";
                $litStr = "";
              }  // eo final string char
              continue;
            }  // eo inside literal string

            if ($ch == '"' || $ch == "'") {  // begin of string detected
              $strDelimChar = $ch;
              $litStr = $ch;
              continue;
            }

            // count parenthesis +/-
            if ($ch == "(") { $parenthCount++; }
            if ($ch == ")") { $parenthCount--; }
          }  // eo parenthesis sum

          // sanity check that all literal strings are closed
          // or better say: that detection of litral strings worked fine
          if ($strDelimChar) {
            throw new Exception("Unterminated string '{$litStr}' in: $tplOri.");
          }

          // final closing parenthesis reached
          if ($parenthCount <= 0) {
            break;
          }
        }  // eo loop till closing parenthesis

        // sanity check that all parenthesis are closed
        // or better say: that detection of parenthesis worked fine
        if ($parenthCount != 0) {
          throw new Exception("Missbalanced parenthesis ({$parenthCount}) part={$parenthTpl} in: $tplOri.");
        }

        // if we have a "full enclosed" part then we remove leading and trailing
        // parenthesis (because of endless loop) and recurse
        $hasInnerParenthesis = true;
        $parenthTpl = trim($parenthTpl);
        if (substr($parenthTpl, 0, 1) == "(" &&
            substr($parenthTpl, -1) == ")") {
          $parenthTpl = substr($parenthTpl, 1, -1);
          $hasInnerParenthesis = false;
        }

        // process parenthesis template as separate template run
        $part = trim(static::extjSqlWhere($parenthTpl, $whereVals, $req, array("skipParenthesis" => $hasInnerParenthesis)));


        // if parentesis template processing is not empty
        // then reassign parenthesis and add to sql
        if ($part) {
          if ( ! $hasInnerParenthesis) {
            $part = "($part)";  // we removed enclosing () above, so we have to add back here
          }
          $sql .= ($sql ? " {$andOrGlue}" : "") . ($kwNOT ? " {$kwNOT}" : "") . " {$part}";
        }
        // handling of current parenthesis part finished
        // continue with parts after closing parenthesis
        continue;
      }  // parenthesis


if (static::$debug) { echo "part=$part<br>\n"; };
      // check if all parameter names for this part are present
      $usePart = false;
      preg_match_all("/(:[\-\?\+!>@]*?[a-z_][a-z0-9_]+)/i", $part, $matches);
      $pnams = $matches[1];

      // if no pnames are present we use that part in any case
      if (count($pnams) == 0) {
        $usePart = true;
      }

      foreach ($pnams as $key => $pnamOri) {

        // remove leading colon
        $pnam = substr($pnamOri, 1);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;
        $doForceAddParam = false;
        $isZombieParam = false;
        $valueRequired = false;
        //$trimmedValueRequired = false;

        // loop over internal command prefix
        $intCmdLoop = true;
        while ($intCmdLoop) {

          // remove colon in final where clause
          if (substr($pnam, 0, 1) == "-") {  // alternate: "="
            $doRemoveColon = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // test if pnam exists and remove pnam afterwards
          if (substr($pnam, 0, 1) == "?") {  // alternate: "-"
            $doRemovePnam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // throw exption if pnam does not exist
          if (substr($pnam, 0, 1) == "!") {  // alternate: ":" ????
            $isRequiredParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // add pnam even if not exits in value arra
          if (substr($pnam, 0, 1) == "+") {
            $doForceAddParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // use only if not empty (untrimmed)
          // support ">" for backward compability
          if (substr($pnam, 0, 1) == ">" ||
              substr($pnam, 0, 1) == "@") {  // alternate: "?"
            $valueRequired = true;
            $pnam = substr($pnam, 1);
            continue;
          }

          $intCmdLoop = false;
        }  // eo internal cmd check
if (static::$debug) { echo "search for $pnam<br>\n"; };


        // check if key exists and get value
        //
        // if pnam already in where vals, then this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
          $usePart = true;
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
          $usePart = true;
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
          $usePart = true;
        }
if (static::$debug) { echo "use $pnam<br>\n"; };
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
          throw new Exception("Required parameter '$pnam' not in value array for {$tpl}.");
        }

        // otherwise if value is required but not present
        // then exlude if value is not present
        if ($valueRequired && !$value) {
          $usePart = false;
          break;
        }


        // final test if part is used
        if (!$usePart) {
          break;
        }


        // write polished pnam out back to where part
        if ($doRemoveColon) {
          $pnamOut = $pnam;
        }
        elseif ($doRemovePnam) {
          $pnamOut = "";
        }
        else {
          $pnamOut = ":$pnam";  // reasign colon
        }
        $part = str_replace($pnamOri, $pnamOut, $part);

        // remember pnam and value only if placeholder (:var) remains
        // do not add zombie parameters - they come from elsewhere
        if (substr($pnamOut, 0, 1) == ":" && !$isZombieParam) {
          $whereVals[$pnam] = $value;
        }
      }  // eo pnam loop

      // do not use empty parts
      if (!trim($part)) {
        $usePart = false;
      }
if (static::$debug) { echo "use=$usePart, usedPart=$part<br>\n"; };

      // use part
      if ($usePart) {
        $sql .= ($sql ? " {$andOrGlue}" : "") . ($kwNOT ? " {$kwNOT}" : "") . " {$part}";
      }
    }  // eo part loop

    // if sql collected then prefix with keyword (and extrablanks to avoid collisions)
    if ($sql) {
      // replace escaped AND / OR part-delimiter
      $sql = preg_replace("/\b\\\\AND\b/", "AND", $sql);
      $sql = preg_replace("/\b\\\\OR\b/", "OR", $sql);
      $sql = " {$kw} {$sql} ";
    }

    return $sql;
  }  // eo WHERE with ext



  /**
  * Prepare ORDER BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlOrderBy($tpl, $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*ORDER\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }


    // extract extra sort field info from template
    $parts = explode(";", $tpl);
//@file_put_contents("debug.localonly", "tpl=$tpl => " . var_export($parts, true) . "\n\n", FILE_APPEND);
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
      else {  // for stand alone colnames sort expression is same as colname
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // if no sort expression is present then fill with default sort
    // if no default sort exists remove sort key
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
    $req['sort'] = self::getStoreSort(null, $req);

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

      $sql .= ($sql ? "," : "") . $tmpSql;

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
  }  // eo ORDER BY with ext



  /**
  * Prepare GROUP BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlGroupBy($tpl, $req = null) {

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
    $req['sort'] = self::getStoreSort(null, $req);

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

      $sql .= ($sql ? "," : "") . $tmpSql;

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
  * Prepare LIMIT clause with data from extjs request.
  * Convenience method to complete the exjSql family.
  */
  public static function extjSqlLimit($req = null) {

    if ($req === null) {
      $req = $_REQUEST;
    }

    $sql = self::getStoreLimit(null, null, $req);
    if (strlen($sql) > 0) {
      $sql = "LIMIT $sql";
    }

    return $sql;
  }  // eo



  /**
  * Strip opening and closing curly brackets.
  * Preserve leading and trailing spaces.
  */
  public static function extjSqlStrip($sql) {

    if (substr(ltrim($sql), 0, 1) == "{") {
      $pos = strpos($sql, "{");
      $sql = substr($sql, 0, $pos) . substr($sql, $pos + 1);
    }

    if (substr(rtrim($sql), -1) == "}") {
      $pos = strrpos($sql, "}");
      $sql = substr($sql, 0, $pos) . substr($sql, $pos + 1);
    }

    return $sql;
  }  // eo


  // END OF PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST - PREG / STR-REPL MODE
  // #######################################################







































  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST - php-sql-parser mode


  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlPspPre($tpl, &$seleVals = array(), $req = null) {

    if ($seleVals === null) {
      $seleVals = array();
    }
    if ($req === null) {
      $req = $_REQUEST;
    }

    // removes brackets from preg / str-repl mode templates
    // SELECT
    if (preg_match("/\{\s*SELECT\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo select


    // WHERE
    if (preg_match_all("/\{\s*WHERE\s.*?\}/is", $tpl, $matches)) {
      foreach ($matches[0] as $ori) {
        $prep = substr($ori, 1, -1);
        $tpl = str_replace($ori, $prep, $tpl);
      }
    }  // eo where


    // GROUP BY
    // ATTENTION: extjSqlGroupBy() is totaly untested, so disable by now !!!
    /*
    if (preg_match("/\{\s*GROUP\s+BY\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = static::extjSqlGroupBy(substr($ori, 1, -1));
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo group by
    */


    // HAVING
    if (preg_match("/\{\s*HAVING\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo having


    // ORDER BY
    if (preg_match("/\{\s*ORDER\s+BY\s.*?\}/is", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo order by

    // real parsing
    return static::extjSqlPsp($tpl, $seleVals, $req);

  }  // eo prep sql template for parser



  /**
  * Prepare sql statement with data from extjs request.
  * Use external php-sql-parser library from code.google.com ...
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlPsp($tpl, &$seleVals = array(), $req = null) {

    if ($seleVals === null) {
      $seleVals = array();
    }
    if ($req === null) {
      $req = $_REQUEST;
    }


    // LIMIT
    $ori = "__EXTJS_LIMIT__";
    if (strpos($tpl, $ori) !== false) {
      $prep = static::extjSqlLimit($req);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo limit


    // parse and tee-ify
    $parser = new PHPSQLParser\PHPSQLParser();
    $sqlTree = $parser->parse($tpl);
//Oger::debugFile(var_export($sqlTree, true));exit;

    static::extjSqlPspProcessTree($sqlTree);

    // create sql from prepared parser tree
    $creator = new PHPSQLParser\PHPSQLCreator();
    $sql = $creator->create($sqlTree);

    return $sql;
  }  // eo prep sql with extjs request



  /**
  * Prepare full parsed tree
  * WORK IN PROGRESS
  * @params &$tpl: The parsed and tokenized sql template tree
  */
  public static function extjSqlPspProcessTree(&$tree) {

    static::extjSqlPspSelect($tree['SELECT']);


  }  // eo process full tree


  /**
  * Prepare SELECT segment from parsed tree
  * WORK IN PROGRESS
  * @params &$segment: The SELECT segment tree.
  */
  public static function extjSqlPspSelect(&$parts) {

    foreach ($parts as &$token) {
      if ($token['sub_tree']) {
        static::extjSqlPspProcessSubTree($token);
      }
    }
  }  // eo process SELECT segment


  /**
  * Prepare subtree from parsed results
  * WORK IN PROGRESS
  * @params &$segment: The subtree token.
  */
  public static function extjSqlPspSubtree(&$token) {

    switch ($token['expr_type']) {
    case "subquery":
      static::extjSqlPspProcessTree($token['sub_tree']);
    default:
      // do nothing
    }
  }  // eo process subtree



  /**
  * Prepare WHERE (or HAVING) clause with data from extjs request.
  * WORK IN PROGRESS
  * Whitespaces are collapsed sometimes, so formating of sql will be lost.
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlWherePsp($tpl, &$whereVals = array(), $req = null, $opts = array()) {
//Oger::debugFile("tpl=$tpl");


    $tplOri = $tpl;

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // get extjs filter from request
    $req['filter'] = self::getStoreFilter(null, $req);
    $extFilter = array();
    foreach ((array)$req['filter'] as $colName => $value) {
      $extFilter[$colName] = $value;
    }  // eo filter item loop


    // detect and remove enclosing {}
    $tpl = self::extjSqlStrip($tpl);

    // detect, save and remove leading where keyword
    if (preg_match("/^\s*(WHERE|HAVING)\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }  // keyword

    // split at and/or boundery (TODO may be problems without leading whitespace)
    $parts = preg_split("/(\s+OR\s+|\s+AND\s+)/i", $tpl, null, PREG_SPLIT_DELIM_CAPTURE);
    while (count($parts)) {  // we play with shift/unshift, so do not use foreach
      $part = array_shift($parts);

      // detect and/or glue and remember (and reset NOT keyword - detected later)
      $tmp = strtoupper(trim($part));
      if ($tmp == "OR" || $tmp == "AND") {
        $andOrGlue = $part;
        $kwNOT = "";
        continue;
      }

      // detect leading NOT and remember
      if (preg_match("/^\s*NOT\s+/i", $part, $matches)) {
        $kwNOT = strtoupper(trim($matches[0]));
        $part = implode("", explode($kwNOT, $part, 2));
      }


      $part = trim($part);

      // handle grouping of conditions by parenthesis
      // check for first opening parenthesis
      // if the skipParenthesis parameter is given we have not a "full enclosed"
      // WHERE clause but another term that starts with "("
      // e.g. a subquery like "(SELECT * FROM ..." or something like that
      if (substr(ltrim($part), 0, 1) == "(" && !$opts['skipParenthesis']) {

        $parenthTpl = "";

        // put current part back because it is called again
        array_unshift($parts, $part);

        // loop till closing parenthesis
        while (count($parts)) {

          $tmpPart = trim(array_shift($parts));

          // add full part
          $parenthTpl .= ($parenthTpl ? " " : "") . $tmpPart;

          // check parenthesis balance
          $parenthCount = 0;
          $strDelimChar = "";
          $litStr = "";

          for ($i=0; $i < strlen($parenthTpl); $i++) {

            $ch = $parenthTpl[$i];

            // skip literal strings
            if ($strDelimChar) {  // we are inside a literal string
              $litStr .= $ch;  // collect string for possible error message
              if ($ch == "\\") {  // handle backslash escaping
                $litStr .= $parenthTpl[$i + 1];
                $i++;  // skip next char - never mind which
                continue;
              }
              if ($ch == $strDelimChar) {  // end of string char detected
                // handle escaping by duplicate delimiter
                if ($parenthTpl[$i + 1] == $strDelimChar) {
                  $litStr .= $parenthTpl[$i + 1];
                  $i++;  // skip duplicate-escaped delimiter
                  continue;
                }
                $strDelimChar = "";
                $litStr = "";
              }  // eo final string char
              continue;
            }  // eo inside literal string

            if ($ch == '"' || $ch == "'") {  // begin of string detected
              $strDelimChar = $ch;
              $litStr = $ch;
              continue;
            }

            // count parenthesis +/-
            if ($ch == "(") { $parenthCount++; }
            if ($ch == ")") { $parenthCount--; }
          }  // eo parenthesis sum

          // sanity check that all literal strings are closed
          // or better say: that detection of litral strings worked fine
          if ($strDelimChar) {
            throw new Exception("Unterminated string '{$litStr}' in: $tplOri.");
          }

          // final closing parenthesis reached
          if ($parenthCount <= 0) {
            break;
          }
        }  // eo loop till closing parenthesis

        // sanity check that all parenthesis are closed
        // or better say: that detection of parenthesis worked fine
        if ($parenthCount != 0) {
          throw new Exception("Missbalanced parenthesis ({$parenthCount}) part={$parenthTpl} in: $tplOri.");
        }

        // if we have a "full enclosed" part then we remove leading and trailing
        // parenthesis (because of endless loop) and recurse
        $hasInnerParenthesis = true;
        $parenthTpl = trim($parenthTpl);
        if (substr($parenthTpl, 0, 1) == "(" &&
            substr($parenthTpl, -1) == ")") {
          $parenthTpl = substr($parenthTpl, 1, -1);
          $hasInnerParenthesis = false;
        }

        // process parenthesis template as separate template run
        $part = trim(static::extjSqlWhere($parenthTpl, $whereVals, $req, array("skipParenthesis" => $hasInnerParenthesis)));


        // if parentesis template processing is not empty
        // then reassign parenthesis and add to sql
        if ($part) {
          if ( ! $hasInnerParenthesis) {
            $part = "($part)";  // we removed enclosing () above, so we have to add back here
          }
          $sql .= ($sql ? " {$andOrGlue}" : "") . ($kwNOT ? " {$kwNOT}" : "") . " {$part}";
        }
        // handling of current parenthesis part finished
        // continue with parts after closing parenthesis
        continue;
      }  // parenthesis


if (static::$debug) { echo "part=$part<br>\n"; };
      // check if all parameter names for this part are present
      $usePart = false;
      preg_match_all("/(:[\-\?\+!>@]*?[a-z_][a-z0-9_]+)/i", $part, $matches);
      $pnams = $matches[1];

      // if no pnames are present we use that part in any case
      if (count($pnams) == 0) {
        $usePart = true;
      }

      foreach ($pnams as $key => $pnamOri) {

        // remove leading colon
        $pnam = substr($pnamOri, 1);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;
        $doForceAddParam = false;
        $isZombieParam = false;
        $valueRequired = false;
        //$trimmedValueRequired = false;

        // loop over internal command prefix
        $intCmdLoop = true;
        while ($intCmdLoop) {

          // remove colon in final where clause
          if (substr($pnam, 0, 1) == "-") {  // alternate: "="
            $doRemoveColon = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // test if pnam exists and remove pnam afterwards
          if (substr($pnam, 0, 1) == "?") {  // alternate: "-"
            $doRemovePnam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // throw exption if pnam does not exist
          if (substr($pnam, 0, 1) == "!") {  // alternate: ":" ????
            $isRequiredParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // add pnam even if not exits in value arra
          if (substr($pnam, 0, 1) == "+") {
            $doForceAddParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // use only if not empty (untrimmed)
          // support ">" for backward compability
          if (substr($pnam, 0, 1) == ">" ||
              substr($pnam, 0, 1) == "@") {  // alternate: "?"
            $valueRequired = true;
            $pnam = substr($pnam, 1);
            continue;
          }

          $intCmdLoop = false;
        }  // eo internal cmd check
if (static::$debug) { echo "search for $pnam<br>\n"; };


        // check if key exists and get value
        //
        // if pnam already in where vals, then this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
          $usePart = true;
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
          $usePart = true;
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
          $usePart = true;
        }
if (static::$debug) { echo "use $pnam<br>\n"; };
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
          throw new Exception("Required parameter '$pnam' not in value array for {$tpl}.");
        }

        // otherwise if value is required but not present
        // then exlude if value is not present
        if ($valueRequired && !$value) {
          $usePart = false;
          break;
        }


        // final test if part is used
        if (!$usePart) {
          break;
        }


        // write polished pnam out back to where part
        if ($doRemoveColon) {
          $pnamOut = $pnam;
        }
        elseif ($doRemovePnam) {
          $pnamOut = "";
        }
        else {
          $pnamOut = ":$pnam";  // reasign colon
        }
        $part = str_replace($pnamOri, $pnamOut, $part);

        // remember pnam and value only if placeholder (:var) remains
        // do not add zombie parameters - they come from elsewhere
        if (substr($pnamOut, 0, 1) == ":" && !$isZombieParam) {
          $whereVals[$pnam] = $value;
        }
      }  // eo pnam loop

      // do not use empty parts
      if (!trim($part)) {
        $usePart = false;
      }
if (static::$debug) { echo "use=$usePart, usedPart=$part<br>\n"; };

      // use part
      if ($usePart) {
        $sql .= ($sql ? " {$andOrGlue}" : "") . ($kwNOT ? " {$kwNOT}" : "") . " {$part}";
      }
    }  // eo part loop

    // if sql collected then prefix with keyword (and extrablanks to avoid collisions)
    if ($sql) {
      // replace escaped AND / OR part-delimiter
      $sql = preg_replace("/\b\\\\AND\b/", "AND", $sql);
      $sql = preg_replace("/\b\\\\OR\b/", "OR", $sql);
      $sql = " {$kw} {$sql} ";
    }

    return $sql;
  }  // eo WHERE with ext



  /**
  * Prepare ORDER BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlOrderByPsp($tpl, $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*ORDER\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }


    // extract extra sort field info from template
    $parts = explode(";", $tpl);
//@file_put_contents("debug.localonly", "tpl=$tpl => " . var_export($parts, true) . "\n\n", FILE_APPEND);
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
      else {  // for stand alone colnames sort expression is same as colname
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // if no sort expression is present then fill with default sort
    // if no default sort exists remove sort key
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
    $req['sort'] = self::getStoreSort(null, $req);

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

      $sql .= ($sql ? "," : "") . $tmpSql;

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
  }  // eo ORDER BY with ext



  /**
  * Prepare GROUP BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlGroupByPsp($tpl, $req = null) {

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
    $req['sort'] = self::getStoreSort(null, $req);

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

      $sql .= ($sql ? "," : "") . $tmpSql;

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


  // END OF PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST - php-sql-parser mode
  // #######################################################










}  // end of class
?>
