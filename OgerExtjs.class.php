<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Some methods to simplify Extjs responses.
*/
class OgerExtjs {

  const EXIT_RETURN = -1;
  const EXIT_ECHO = 0;
  const EXIT_ABORT = 1;
  const EXIT_THROW = 2;


  /**
  * Encode an object from a php array into json.
  * @param $obj The array with the object values.
  * @param $success Boolean flag for the success property. Defaults to true.
  *        Null supresses the setting if the object already has a success property.
  *        If no success proptery is present then it is set to true.
  * @return Json encoded object.
  */
  public static function enc($obj = array(), $success = true) {
    if ($success === null) {
      if (!array_key_exists('success', $obj)) {
        $obj['success'] = true;
      }
    }
    else {  // explicit setting
      $obj['success'] = (boolean)$success;
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
  * Depending on the exitFlag echo the json object and
  * exit the php script with odr without throwing an exception.
  * @param $msg The error message.
  * @param $exitFlag Valid values are<br>
  *  -1 = do not abort, do not echo json - only return it<br>
  *  0 = do not abort, but echo and return json<br>
  *  1 = exit php (default)<br>
  *  2 = throw exception with given messagse<br>
  * @return Extjs unsuccess json.
  */
  public static function errorMsg($msg, $exitFlag = 1) {

    $json = static::encFail(array('msg' => $msg));

    if ($exitFlag >= static::EXIT_ECHO) {
      echo $json;
    }

    switch ($exitFlag) {
    case static::EXIT_RETURN:
      break;
    case static::EXIT_ECHO:
      break;
    case static::EXIT_ABORT:
      exit;
    case static::EXIT_THROW:
      throw new Exception($msg);
    }

    return $json;
  }  // eo errorMsg



}  // end of class
?>
