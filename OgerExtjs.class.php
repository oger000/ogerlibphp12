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
  * Encode an object from a php array into json.
  * @param $array The array with the object values.
  * @param $success Boolean flag for the success property. Defaults to true.<br>
  *        - Null: Do not set a new and do not change an existing success property.
  * @return Json encoded object.
  */
  public static function enc($obj = array(), $success = true) {
    if ($success !== null) {
      $obj["success"] = (boolean)$success;
    }
    return json_encode($obj);
  }  // eo json encoded object


  /**
  * Encode data from a php array into json.
  * @param $obj The array with the data values.
  * @param $root Name of the data root property. Defaults to "data".
  * @return Json encoded object.
  */
  public static function encData($obj = array(), $root = null, $more = array()) {
    if (!$root) {
      $root = "data";
    }
    $all = $more;
    $all[$root] = $obj;
    // data objects always are success respones
    return static::enc($data, true);
  }  // eo json encoded data object


  /**
  * Encode an error message.
  * @param $msg The error message.
  * @return Json encoded object.
  */
  public static function errorMsg($msg) {
    return static::enc(array("msg" => $msg), false);
  }  // eo errorMsg



}  // end of class
?>
