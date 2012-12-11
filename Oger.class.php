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
  * An array is assiciative if it has non numeric keys.<BR>
  * <em>ATTENTION:</em> Associative arrays with only numeric keys are
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





}  // eo class

?>
