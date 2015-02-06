<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Fake database records from static array.
*
* $records = array("recordKey" => array("field1" => value1, "field2" => value2, ...))
*/
class DbRecFake {

	public static $records = array();


	/**
	* Create record list
	* A dummy for a kind of abstract method
	*/
	public static function createRecords() {
		echo "DbRecFake::createRecords() has to be implemented in child class.";
	}


	/**
	* Get record array with record keys
	* Allow access via record key outside the class
	*/
	public static function getRecArray() {
		static::createRecords();
		return static::$records;
	}


	/**
	* Get record list without record keys
	* Otherwise json encoding will result in one object instead of an array
	*/
	public static function getRecords() {
		static::createRecords();
		return array_values(static::$records);
	}


	/**
	* Get record by record keys
	*/
	public static function getRecByKey($key) {
		static::createRecords();
		return static::$records[$key];
	}


	/**
	* Get field value for record with given key
	*/
	public static function getValue($key, $field) {
		static::createRecords();
		return static::$records[$key][$field];
	}



	/**
	* Get record by record key.
	*/
	public static function getRec1Where($whereValues = array()) {
		static::createRecords();

		foreach (static::$records as $record) {
			$found = true;
			foreach ($whereValues as $whereKey => $whereValue) {
				if ($record[$whereKey] != $whereValue) {
					$found = false;
					break;
				}
			}
			if ($found) {
				return $record;
			}
		}  // eo record loop

		return null;
	}  // eo get rec1 where




}  // end of class



?>
