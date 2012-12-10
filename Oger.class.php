<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/




/**
* Collection of utility methods.
*/
class Oger {


  /**
  * L10N.
  * Has to be implemented. For now only marks text for L10N.
  * @param $text  Text to be localized.
  * @return Localized string.
  */
  public static function _($text) {
    return $text;
  }  // eo l10n



  /**
  * Check if array is associative.
  * An array is assiciative if it has non numeric keys.
  * ATTENTION: Associative arrays with only numeric keys are
  * treated as NOT associative!!!! This is a general problem
  * also in PHP internal-functions like array_merge, etc.
  * @param $array  Array to be checked.
  * @return True if it is an associative array. False otherwise.
  */
  public static function isAssocArray($array) {
    if (!is_array($array)) {
      return false;
    }
    foreach ($array as $key => $value) {
      if (!is_numeric($key)) {
        return true;
      }
    }
    return false;
  }  // eo assoc check








  // TODO document or remove methods below




  /*
  * untabify string with tabstop width
  */
  public static function untabify($string, $tabWidth = 8) {

    $parts = explode("\t", $string);

    $result = array_shift($parts);
    while (count($parts)) {
      // the previous part is followed by at least one blank
      $result .= ' ';
      // insert blanks till the next tabstop
      while (strlen($result) % $tabWidth) {
        $result .= ' ';
      }
      // append next string part
      $result .= array_shift($parts);
    }  // loop over parts

    return $result;

  }  // end of untabify string




  /*
  * Insert all key/value pairs of an associative array into another associative array after specified key.
  * Numeric arrays are NOT handled properly!
  * WE DONT CHECK ANYTHING! Values are overwritten and other unexpected results may happen if input is not correct.
  * Values from array2 may overwrites values of array1 if the same key is present in both arrays.
  * TODO: Check: Maybe this is slow. There is another solution spliting the original
  * array into keys and values, insert the new keys and values via array_splice and
  * create the result array via array_combine. See: <http://www.php.net/manual/en/function.array-splice.php>
  * @array1: Associative array.
  * @searchKey: Key after which array 2 is inserted.
  * @array2: Associative array.
  */
  public static function arrayInsertAfterKey(&$array1, $searchKey, $array2) {
    $array = array();
    $insertDone = false;
    foreach ($array1 as $key1 => $value1) {
      $array[$key1] = $value1;
      if ($key1 == $searchKey) {
        foreach ($array2 as $key2 => $value2) {
          $array[$key2] = $value2;
        }
        $insertDone = true;
      }
    }
    // if we did not find the key then append the inserts here
    if (!$insertDone) {
      foreach ($array2 as $key2 => $value2) {
        $array[$key2] = $value2;
      }
    }

    $array1 = $array;
    return $array;
  }  // eo insert after key


  /*
  * Insert all key/value pairs of an associative array into another associative array before specified key.
  * Numeric arrays are NOT handled properly!
  * WE DONT CHECK ANYTHING! Values are overwritten and other unexpected results may happen if input is not correct.
  * Values from array2 may overwrites values of array1 if the same key is present in both arrays.
  * TODO: Check: Maybe this is slow. There is another solution spliting the original
  * array into keys and values, insert the new keys and values via array_splice and
  * create the result array via array_combine. See: <http://www.php.net/manual/en/function.array-splice.php>
  * @array1: Associative array.
  * @searchKey: Key after which array 2 is inserted.
  * @array2: Associative array.
  */
  public static function arrayInsertBeforeKey(&$array1, $searchKey, $array2) {
    $array = array();
    $insertDone = false;
    foreach ($array1 as $key1 => $value1) {
      if ($key1 == $searchKey) {
        foreach ($array2 as $key2 => $value2) {
          $array[$key2] = $value2;
        }
        $insertDone = true;
      }
      $array[$key1] = $value1;
    }
    // if we did not find the key then append the inserts here
    if (!$insertDone) {
      foreach ($array2 as $key2 => $value2) {
        $array[$key2] = $value2;
      }
    }

    $array1 = $array;
    return $array;
  }  // eo insert before key


  /*
  * Evaluate an arithmetic expressoin from string.
  * Only basic arithmetic works because of security reasons.
  */
  public static function evalMath($str) {
    $str = preg_replace('/[^0-9\. \+\-\*\/\(\)]+/', '', $str);
    return eval("return $str;");
  }


  /**
   * Indents a flat JSON string to make it more human-readable.
   * from: <http://recursive-design.com/blog/2008/03/11/format-json-with-php/>
   * @param string $json The original JSON string to process.
   * @return string Indented version of the original JSON string.
   */
  public static function beautifyJson($json) {

    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = '  ';
    $newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i=0; $i<=$strLen; $i++) {

      // Grab the next character in the string.
      $char = substr($json, $i, 1);

      // Are we inside a quoted string?
      if ($char == '"' && $prevChar != '\\') {
        $outOfQuotes = !$outOfQuotes;

      // If this character is the end of an element,
      // output a new line and indent the next line.
      } else if(($char == '}' || $char == ']') && $outOfQuotes) {
        $result .= $newLine;
        $pos --;
        for ($j=0; $j<$pos; $j++) {
          $result .= $indentStr;
        }
      }

      // Add the character to the result string.
      $result .= $char;

      // If the last character was the beginning of an element,
      // output a new line and indent the next line.
      if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
        $result .= $newLine;
        if ($char == '{' || $char == '[') {
          $pos ++;
        }

        for ($j = 0; $j < $pos; $j++) {
          $result .= $indentStr;
        }
      }

      $prevChar = $char;
    }

    return $result;
  }  // eo beautify json



  /**
   * Merge two or more arrays and preserve numeric keys
   */
  public static function arrayMergeAssoc() {
    $result = array();
    $arrays = func_get_args();
    foreach($arrays as $array) {
      if ($array == null) {
        continue;
      }
      foreach($array as $key => $value) {
        $result[$key] = $value;
      }
    }
    return $result;
  }  // eo array merge assoc




}  // eo class

?>
