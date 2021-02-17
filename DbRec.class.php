<?PHP
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/


/*
 * This class is not intended as part of the ogerlibphp12 and
 * should go into the application specific class directory later.
 * For now it is guest here to share basic development between
 * apps in early stage and at the same time being tested in
 * mature apps for later inclusion.
 */

/**
 * WORK IN PROGRESS
 * This class has core function on one side and at the same time
 * is the playground for other database related functions.
 * Including ones that may go into other classes when final.
 * So be careful when using across multiple projects.
 */



/**
* Base class for handling one record of a database table.
* Mainly a collection of static methods.
*/
class DbRec {

	public static $tableName;


	/**
	* Filter out values from an array where the keys of the values array
	* have to match a column name of the assiciated table.
	* @values: associative array with fieldname (key) value pairs.
	*/
	public static function filterColValues($values = array()) {

		$newVals = array();

		foreach ((array)$values as $key => $value) {
			if (Dbw::isColumn(static::$tableName, $key)) {
				$newVals[$key] = $value;
			}
		}

		return $newVals;
	}  // eo filter



	/**
	* Get sql template for given target.
	*/
	public static function getSqlTpl($target, &$opts = array()) {
		return $target;
	}  // eo get sql tpl




	/**
	* Get prepared sql string and fill sele vals
	*/
	public static function getSql($target, &$seleVals = array(), $req = null, &$tplOpts = array()) {

	  $tpl = static::getSqlTpl($target, $tplOpts);

		switch ($tplOpts['parser']) {
		case "php-sql":
			$tpl = OgerExtjs::extjSqlClearCurlyTags($tpl);
			$sqlTpl = new OgerExtjSqlTpl($req);
			$sql = $sqlTpl->prepare($tpl, null, $tplOpts);
			$seleVals = $sqlTpl->getParamValues();
			break;
		default:
			$sql = OgerExtjs::extjSql($tpl, $seleVals, $req);
		}

		// postprocess template
		if ($tplOpts['str_replace']) {
			foreach ((array)$tplOpts['str_replace'] as $search => $replace) {
				if (!$search) {
					continue;
				}
				$sql = str_replace($search, $replace, $sql);
			}
		}

		// format / beautify sql
		if (!$tplOpts['skip-format']) {
			$sql = SqlFormatter::format($sql);
		}

		return $sql;
	}  // eo get sql



	/**
	* Write record values to db.
	* Accepts old style WHERE values (as array) too.
	*/
	public static function store($storeAction, $values, $where = null, $opts = array()) {

		$values = static::filterColValues($values);

		// sanity check - do not update without WHERE clause
		if ($storeAction == "UPDATE") {
			if (!$where) {
				throw new Exception("Update without WHERE clause refused.");
			}
		}  // eo update WHERE check

		$stmt = Dbw::getStoreStmt($storeAction, static::$tableName, $values, $where);
		$pstmt = Dbw::$conn->prepare($stmt);


		// cleanup where array if containes nested info
		if (is_array($where)) {
			foreach ($where as $key => $val) {
				if (is_array($val)) {
					unset($where[$key]);
					reset($val);
					$key = key($val);
					$where[$key] = $val[$key];
				}
			}
		}

		// on update merge the where-values in, but preserve
		// data-values if already present
		if ($storeAction == "UPDATE" && is_array($where)) {
			$values = array_merge($where, $values);
		}

		Dbw::checkStmtParams($stmt, $values);

    // return raw sql and values data for debugging
    if ($opts['getDebugInfo']) {
      return array("stmt" => $stmt, "values" => $values);
    }

    // execute now
		$result = $pstmt->execute($values);
		$pstmt->closeCursor();

		// return pstmt for using outside (e.g. counting affected rows)
		// ATTENTION: mysql returns 0 if records are found but nothing changed!!!
		return $pstmt;
	}  // eo store to db



	/**
	* Prepare date fields for output.
	* Only used for php internals, because extjs converts on the fly
	*/
	public static function prepDateOut($values, $dateFieldNames) {

		foreach ($dateFieldNames as $fieldName) {
			if (substr($values[$fieldName], 0, 10) == '0000-00-00') {
				$values[$fieldName] = '';
			}
		}

		return $values;
	}  // eo order by template




}  // end of class
