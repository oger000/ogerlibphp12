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
    if (!$req[$limitName] || !is_numeric($req[$limitName])) {
      return "";
    }
    $limit = "" . intval($req[$limitName]);

    // start only makes sense if limit is in prep
    if (array_key_exists($startName, $req) && is_numeric($req[$startName])) {
      $limit = "" . intval($req[$startName]) . ",$limit";
    }

    return $limit;
  }  // eo get limit


}  // end of class
?>
