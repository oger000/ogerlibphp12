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
  * @param $obj The array with the object values.
  * @param $success Boolean flag for the success property. Defaults to true.
  *        Null supresses the setting if the object already has a success property.
  *        If no success property is present then it is set to true.
  * @return Json encoded object.
  */
  public static function enc($obj = array(), $success = true) {
    if ($success === null) {
      if (!array_key_exists("success", $obj)) {
        $obj["success"] = true;
      }
    }
    else {  // explicit setting
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
  public static function encData($obj = array(), $root = "data") {
    $data = array($root => $obj);
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
