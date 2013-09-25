<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Prepare sql template wit extjs request.
* This class is not intended for direct use but as backup
*/
class OgerExtjSqlTpl {

  /// Enclose char at the begin.
  /// Enclose char at the begin of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  public static $encNamBegin = '"';

  /// Enclose char at the end.
  /// Enclose char at the end of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  public static $encNamEnd = '"';


  /// Connection resource.
  /// Mainly intended for setting and using in inheriting classes.
  public static $conn;


  /**
  * Enclose a name with database dependend encape chars.
  * @param $name Name to enclose.
  * @return Enclosed name.
  */
  public static function encName($name) {
    return static::$encNamBegin . $name . static::$encNamEnd;
  }





  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST



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


    // SELECT
    if (preg_match("/\{\s*SELECT\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo select


    // WHERE
    if (preg_match("/\{\s*WHERE\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = self::extjSqlWhere(substr($ori, 1, -1), $seleVals);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo where


    // ORDER BY
    if (preg_match("/\{\s*ORDER\s+BY\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = self::extjSqlOrderBy(substr($ori, 1, -1));
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo order by


    // LIMIT
    $ori = "__EXTJS_LIMIT__";
    if (strpos($tpl, $ori) !== false) {
      $prep = "";
      if (array_key_exists("limit", $req) && is_numeric($req['limit'])) {
        $prep .= intval($req['limit']);
      }
      // start only makes sense if limit is in prep
      if ($prep && array_key_exists("start", $req) && is_numeric($req['start'])) {
        $prep = "" . intval($req['start']) . ",$prep";
      }
      if ($prep) {
        $prep = "LIMIT $prep";
      }
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo limit


    return $tpl;
  }  // eo select with ext



  /**
  * Prepare WHERE clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlWhere($tpl, &$whereVals = array(), $req = null) {

    $tplOri = $tpl;

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // get extjs filter from request
    $req['filter'] = OgerExtjs::getFilter(null, $req);
    $extFilter = array();
    foreach ((array)$req['filter'] as $colName => $value) {
      if (!static::columnCharsValid($colName)) {
        throw new Exception("Invalid character in filter key (column name) '$colName' in ExtJS filter.");
      }
      $extFilter[$colName] = $value;
    }  // eo filter item loop


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*WHERE\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = str_replace($kw, "", $tpl);
    }  // keyword

    // split at and/or boundery
    // TODO detect parenthesis
    $parts = preg_split("/(\s+OR\s+|\s+AND\s+)/i", $tpl, null, PREG_SPLIT_DELIM_CAPTURE);
    while (count($parts)) {
      $part = array_shift($parts);

      // detect and/or glue and remember (and reset NOT keyword)
      $tmp = strtoupper(trim($part));
      if ($tmp == "OR" || $tmp == "AND") {
        $andOrGlue = $part;
        $notKw = "";
        continue;
      }

      // detect leading NOT and remember
      if (preg_match("/^\s*NOT\s+/i", $part, $matches)) {
        $notKw = $matches[0];
        $part = str_replace($notKw, "", $part);
      }


      // handle grouping of conditions by parenthesis
      // check for first opening parenthesis
      if (substr(ltrim($part), 0, 1) == "(") {

        $parenthCount = 0;
        $parenthTpl = "";

        // put current part back because it is called again
        array_unshift($parts, $part);

        // loop till closing parenthesis
        while (count($parts)) {
          $tmpPart = trim(array_shift($parts));

          // check for opening parenthesis
          // can be hidden after a leading NOT
          $tmpPart2 = $tmpPart;
          if (preg_match("/^\s*NOT\s+/i", $tmpPart2, $matches)) {
            $tmpPart2 = str_replace($matches[0], "", $tmpPart2);
          }

          // increment by leading opening parenthesis - maybe there are more than one
          $cTmp = ltrim($tmpPart2);
          while (substr($cTmp, 0, 1) == "(") {
            $parenthCount++;
            $cTmp = ltrim(substr($cTmp, 1));
//echo "c=$parenthCount; $tmpPart2<br>";
          }

          // decrement by trailing closing parenthesis - maybe there are more than one
          $cTmp = rtrim($tmpPart2);
          while (substr($cTmp, -1) == ")") {
            $parenthCount--;
            $cTmp = rtrim(substr($cTmp, 0, -1));
//echo "c=$parenthCount; $tmpPart2<br>";
          }

          // add full part
          $parenthTpl .= " $tmpPart";

          // final closing parenthesis reached
          if ($parenthCount <= 0) {
            break;
          }
        }  // eo loop till closing parenthesis

        // sanity check that all parenthesis are closed
        // or better say: that detection of parenthesis worked fine
        if ($parenthCount != 0) {
          throw new Exception("Closing parenthesis $parenthCount required in: $tplOri.");
        }

        // remove leading and trailing parenthesis, otherwise endless loop
        $parenthTpl = trim($parenthTpl);
        $parenthTpl = substr($parenthTpl, 1);
        if (substr($parenthTpl, -1) == ")") {
          $parenthTpl = substr($parenthTpl, 0, -1);
        }
//echo "subTpl=$parenthTpl<br>\n";
        $part = trim(static::extjSqlWhere($parenthTpl, $whereVals, $req));
//echo "subPart=$part<br>\n";
        // if not empty reassign parenthesis and add to sql
        if ($part) {
          $part = "($part)";
          $sql .= ($sql ? $andOrGlue : "") . $notKw . $part;
        }
        // handling of current parenthesis part finished
        // continue with parts after closing parenthesis
        continue;
      }  // parenthesis


//echo "part=$part<br>\n";
      // check if all parameter names for this part are present
      $usePart = true;
      preg_match_all("/(:[\-\?\+!]?[%]?[a-z_][a-z0-9_]*[%]?)/i", $part, $matches);
      $pnams = $matches[1];
//echo "pnams=";var_export($pnams); echo "<br>";
      foreach ($pnams as $key => $pnamOri) {

        // remove leading colon
        $pnam = substr($pnamOri, 1);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;
        $doForceAddParam = false;
        $isZombieParam = false;

        if (substr($pnam, 0, 1) == "-") {
          $doRemoveColon = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "?") {
          $doRemovePnam = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "!") {
          $isRequiredParam = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "+") {
          $doForceAddParam = true;
          $pnam = substr($pnam, 1);
        }

        // separate special sql prefix and postfix (e.g. %var%)
        $valPre = "";
        $valPost = "";
        if (substr($pnam, 0, 1) == "%") {
          $valPre = "%";
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, -1) == "%") {
          $valPost = "%";
          $pnam = substr($pnam, 0, -1);
        }

        // if pnam already in where vals, then this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
        }
        // otherwise if param is forced then add part even if pnam not present
        // the user is responsible to provide the key and the value elsewhere
        elseif ($doForceAddParam) {
          $isZombieParam = true;
        }
        // otherwise check if it is a required param
        // if not present till now throw an exeption
        elseif ($isRequiredParam) {
          throw new Exception("Required parameter '$pnam' not in value array.");
        }
        // as last resort we do not use this part
        else {
          $usePart = false;
          break;
        }  // check if pnames present

        // apply prefix and postfix to value
        if (substr($value, 0, 1) != $valPre) {
          $value = $valPre . $value;
        }
        if (substr($value, -1) != $valPost) {
          $value = $value . $valPost;
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

      if ($usePart) {
        $sql .= ($sql ? $andOrGlue : "") . $notKw . $part;
      }
    }  // eo part loop

    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = $kw . $sql;
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
      $tpl = str_replace($kw, "", $tpl);
    }


    // extract extra sort field info from template
    $parts = explode(";", $tpl);
    $tplSorter = array();
    foreach ((array)$parts as $value) {
      $value = trim($value);
      if (strpos($value, "=") !== false) {
        list($key, $value) = explode("=", $value, 2);
        $key = trim($key);
        $value = trim($value);
      }
      else {
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // convert sort info from json to array
    $req['sort'] = OgerExtjs::getSort(null, $req);

    // loop over sort info from ext
    foreach ((array)$req['sort'] as $colName => $direct) {
      if (!static::columnCharsValid($colName)) {
        throw new Exception("Invalid character in sort key (column name) '$colName' in ExtJS sort.");
      }
      if ($direct &&  $direct != "ASC" && $direct != "DESC" && $direct != "") {
        throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
      }

      // column name from ext must be present in template sorter
      // or template sorter wildcard (*) must be set, otherwise stop
      if (!($tplSorter[$colName] || $tplSorter['*'])) {
        continue;
      }

      // encode plain column names
      $colNameOut = static::$encNamBegin . $colName . static::$encNamEnd;

      // if template sorter info is present overwrite plain name
      if (array_key_exists($colName, $tplSorter)) {
        $colNameOut = $tplSorter[$colName];
      }

      if ($colNameOut) {
        $sql .= ($sql ? ", " : "") . $colNameOut .
                ($direct ? " " : "") . $direct;
      }
    }  // eo ext sort item loop

    // if no ext sorters are given but a default template sorter is present
    // and no sql composed, then use the template sorter default
    if (!count($req['sort']) && $tplSorter['@'] && !$sql) {
      $sql .= ($sql ? ", " : "") . $tplSorter['@'];
    }


    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = $kw . $sql;
    }


    return $sql;
  }  // eo ORDER BY with ext





}  // eo class





?>
