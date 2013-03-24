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
  * @param $arr The array with values.
  * @param $success Boolean flag for the success property. Defaults to true.<br>
  *        - Null: Do not set a new and do not change an existing success property.
  * @return Json encoded array.
  */
  public static function enc($arr = array(), $success = true) {
    if ($success !== null) {
      $arr["success"] = (boolean)$success;
    }
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



}  // end of class
?>
