<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


// THIS FILE ONLY EXISTS FOR BACKWARD COMPABILITY WITH ogerArch
// DO NOT USE IN NEW PROJECTS !!!!


/**
* some helpfull functions for csv
*/
class OgerCsv {

	// configure via static variables and hope this will not change within one app
	public static $fieldSeparator = ';';
	public static $addNewLineToRow = true;
	public static $delimitEmptyFields = false;


	/**
	* Preapre one field for export
	* Helper function: Format OgerCsv::prepField
	* Fields are enclosed (delimited) by double apostroph
		// change:
		// - double apostroph to singel apostroph
		// - \n and \rto text representation
	*/
	public static function prepFieldOut($value) {

		// do not prepare empty fields
		if (!$value && !static::$delimitEmptyFields) {
			return '';
		}

		// double apostroph to a double apostroph to make reverse converting "relatively" easy
		$value = str_replace('"', "''", $value);

		// change "\n" to text representation
		$value = str_replace("\n", '\n', $value);
		$value = str_replace("\r", '\n', $value);

		return '"' . $value . '"';

	}  // eo prepare field


	/**
	* Prepare full row from value array
	* Add delimiter also to last field.
	*/
	public static function prepRowOut($values) {

		$row = '';

		if ($values === null) {
			$values = array();
		}

		// for primitives (string, int, etc)
		if (!is_array($values)) {
			$values = (array)$values;
		}

		foreach ($values as $value) {
			$row .= static::prepFieldOut($value) . static::$fieldSeparator;
		}

		if (static::$addNewLineToRow) {
			$row .= "\n";
		}

		return $row;
	}  // eo repare row



}  // eo class

?>
