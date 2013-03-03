<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Some methods to simplify Extjs responses.
*/
class OgerExtjs {


  /**
  * Encode a php array into json.
  * @param $array The array with values.
  * @param $success Boolean flag for the success property. Defaults to true.<br>
  *        - Null: Do not set a new and do not change an existing success property.
  * @return Json encoded array.
  */
  public static function enc($array = array(), $success = true) {
    if ($success !== null) {
      $array["success"] = (boolean)$success;
    }
    return json_encode($array);
  }  // eo json encoded array


  /**
  * Encode data from a php array into json.
  * @param $array The array with the data values.
  * @param $dataRoot Name of the data root property. Defaults to "data".
  * @return Json encoded array.
  */
  public static function encData($array = array(), $more = array(), $dataRoot = null, $countName = null) {

    if (!$dataRoot) {
      $dataRoot = "data";
    }

    if (!$countName) {
      $countName = "count";
    }

    if (!is_array($more)) {
      // numeric primitive-type is reserved for total count in paging grids
      if (is_numeric($more)) {
        $more = array($countName => intval($more));
      }
      else {  // otherwise we ignore the more param if not an array
        $more = array();
      }
    }

    $all = array($dataRoot => $array);
    $all = array_merge($more, $all);

    return static::enc($all, true);
  }  // eo json encoded data array


  /**
  * Encode an error message.
  * @param $msg The error message.
  * @return Json encoded array.
  */
  public static function errorMsg($msg) {
    return static::enc(array("msg" => $msg), false);
  }  // eo errorMsg



}  // end of class
?>
