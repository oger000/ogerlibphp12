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
 * Database worker class.
* Handle db related things (mainly with static methods)
* Create a separate class for each used database
*/
class Dbw extends OgerDb {

	public static $dbDefAliasId;
	public static $dbDef;
	public static $conn;

	public static $struct = null;


	/**
	* Open dbo handle for given dbAliasId
	* @param $opts Option array. Posslibe values are:
	*        - compat Compatibility mode for pre12 config
	*/
	public static function openDbAliasId($dbDefAliasId, $opts = array()) {

		static::$dbDefAliasId = $dbDefAliasId;
		static::$dbDef = Config::$dbDefs[$dbDefAliasId];

		if (!static::$dbDef) {
			echo OgerExtjs::errorMsg("Invalid dbDefAliasId {$dbDefAliasId}.");
			exit;
		}

		// convert pre12 dbdef into config12 format
		// designed only for mysql stanzas
		if ($opts['compat']) {
			// split pre12 db name into parts and extract pure db name
			list(static::$dbDef['dsnDriver'], static::$dbDef['dsnConnect']) = explode(":", static::$dbDef['dbName'], 2);
			preg_match("/.*dbname=(.*?);/", static::$dbDef['dsnConnect'], $matches);
			static::$dbDef['dbName'] = $matches[1];

			static::$dbDef['user'] = static::$dbDef['dbUser'];
			static::$dbDef['pass'] = static::$dbDef['dbPass'];
			if (static::$dbDef['displayName']) {
				static::$dbDef['visible'] = true;
			}
			static::$dbDef['autoLogonUser'] = static::$dbDef['autoLogonUserId'];
			static::$dbDef['driverOpts'] = static::$dbDef['dbAttributes'];
			static::$dbDef['extraOpts'] = static::$dbDef['dbAttributes'];
		}  // eo compat


		// if a connection is already given via options then use that
		if ($opts['conn']) {
			$conn = $opts['conn'];
		}
		else {  // otherwise open now
			try {
				$conn = new PDO(static::$dbDef["dsnDriver"] . ":" . static::$dbDef["dsnConnect"],
												static::$dbDef["user"],
												static::$dbDef["pass"],
												static::$dbDef["driverOpts"]);
			}
			catch (Exception $ex) {
				$connEx = $ex;
				$conn = false;
			}
		}
		if ($conn === false) {
			if ($connEx) {
				$exMsg = $connEx->getMessage();
			}
			echo Extjs::errorMsg("Cannot connect to database " . static::$dbDef["dbName"] . " ($exMsg).");
			exit;
		}
		static::$conn = $conn;


		// mysql specific extra options
		if (static::$dbDef["dsnDriver"] == "mysql") {

			static::$encNamBegin = '`';
			static::$encNamEnd = '`';

			if (static::$dbDef["extraOpts"]["connectionCharset"]) {
				static::$conn->exec("SET CHARACTER SET " . static::$dbDef["extraOpts"]["connectionCharset"]);
			}
			// we rely on utf8 so we force it here and overwrite config settings (if given)
			static::$conn->exec("SET CHARACTER SET UTF8");

			if (static::$dbDef["extraOpts"]["connectionTimeZone"]) {
				static::$conn->exec("SET time_zone = " . static::$dbDef["extraOpts"]["connectionTimeZone"]);
			}

			// looks like driver option error mode is ignored, so we (re)set it here
			if (static::$dbDef["driverOpts"][PDO::ATTR_ERRMODE]) {
				static::$conn->setAttribute(PDO::ATTR_ERRMODE, static::$dbDef["driverOpts"][PDO::ATTR_ERRMODE]);
			}
			// we rely on exceptions for database errors, so force this here and overwrite config settings (if given)
			static::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		}  // eo mysql extra options


		// read struct file, even if struct check is skipped, because we need the column info for creating sql statements
    // if (!static::$dbDef['skipDbStructCheck']) {
		$dbStructFile = "dbstruct/dbStruct.inc.php";
		if (file_exists($dbStructFile)) {
			static::$struct = include($dbStructFile);
			if (!static::$struct) {
				throw new Exception("No structure found in db structure file $dbStructFile.");
				}
		}
		else {
			throw new Exception("Cannot find db structure file $dbStructFile.");
		}
    // }  // eo not skip struct check

		return static::$conn;
	}  // eo open


	/**
	* Check database structure and change if necessary
	* Log happens only if structure log table is present
	*        Valid keys are:
  *           - ignoreCollate: Ignore collate attribure in table-def and column-def.
	*/
	public static function checkStruct($params = array()) {

		// @file_put_contents("debug.localonly", "\n-----\ndbDef=" . var_export(static::$dbDef, true) . "\n\n", FILE_APPEND);
    if (static::$dbDef['skipDbStructCheck']) {
      return;
    }

		// autobackup - OgerArch specific only
    // intended scipt for unattended backup, but can contain any php code
    /*
		$incFile = "exportSql.php";
		if (file_exists($incFile)) {
			$structChecker = new OgerDbStructMysql(static::$conn, static::$dbDef["dbName"]);
			$structChecker->setParam("dry-run", true);
			$structChecker->updateDbStruct(static::$struct);
			$structChecker->reorderDbStruct();
			if ($structChecker->changeCount) {
				$_REQUEST['__OGER_AUTOBACKUP__'] = "dbstruct";
				$_REQUEST['__OGER_AUTOBACKUP_DBDEFALIASID__'] = static::$dbDefAliasId;
				include($incFile);
			}
		}  // eo ogerarch specific
    */


		$structTableName = "dbStructLog";
		try {
			$oldStructSerial = static::fetchValue1("SELECT MAX(structSerial) FROM {$structTableName}");
		}
		catch (Exception $ex) {
			// on bootstrap the dbstruct log table does not exist. nevertheless
			// report the error because there could be another reason for the exception
			$bootstrapError = $ex->getMessage();
			$oldStructSerial = -1;
		}
		$newStructSerial = static::$struct['DBSTRUCT_META']['SERIAL'];

		// preprocess script before updating dbstruct
		$preProcessFile = "dbstruct/preprocess.inc.php";
		if (file_exists($preProcessFile)) {
			include($preProcessFile);
		}

    // get old structure
    // convert pre-mysql-8 column types in template file if needed
		$structChecker = new OgerDbStructMysql(static::$conn, static::$dbDef["dbName"]);
		$structChecker->setparams($params);
    $oldDbStruct = $structChecker->getDbStruct();
    list($curMyVersionMajor) = explode(".", $oldDbStruct['SCHEMA_META']['version']);
    list($tplMyVersionMajor) = explode(".", static::$struct['SCHEMA_META']['version']);
    if ($curMyVersionMajor < 8 && $tplMyVersionMajor >= 8) {
      foreach (static::$struct['TABLES'] as $tableKey => $tableValues) {
        foreach ($tableValues['COLUMNS'] as $columnKey => $columnValues) {
          if ($columnValues['COLUMN_TYPE'] == "int") {
            static::$struct['TABLES'][$tableKey]['COLUMNS'][$columnKey]['COLUMN_TYPE'] = "int(11)";
          }
          if ($columnValues['COLUMN_TYPE'] == "tinyint") {
            static::$struct['TABLES'][$tableKey]['COLUMNS'][$columnKey]['COLUMN_TYPE'] = "tinyint(4)";
          }
        }
      }
    }
    if ($curMyVersionMajor >= 8 && $tplMyVersionMajor < 8) {
      foreach (static::$struct['TABLES'] as $tableKey => $tableValues) {
        foreach ($tableValues['COLUMNS'] as $columnKey => $columnValues) {
          if ($columnValues['COLUMN_TYPE'] == "int(11)") {
            static::$struct['TABLES'][$tableKey]['COLUMNS'][$columnKey]['COLUMN_TYPE'] = "int";
          }
          if ($columnValues['COLUMN_TYPE'] == "tinyint(4)") {
            static::$struct['TABLES'][$tableKey]['COLUMNS'][$columnKey]['COLUMN_TYPE'] = "tinyint";
          }
        }
      }
    }

    // check struct and update
		$beginTime = date("c");
		$structChecker->setParam("log-level", OgerDbStruct::LOG_DEBUG);
		try {
			$structChecker->updateDbStruct(static::$struct);
			$structChecker->reorderDbStruct();
		}
		catch (Exception $ex) {
			$error = trim($ex->getMessage());
		}
		$log = trim($structChecker->flushLog());

		// report log and errors if there is anything to report
		$structTableKey = strtolower($structTableName);
		$oldDbStruct = $structChecker->getDbStruct();
		if ((trim($log) || trim($error))) {

			// use another struct checker and do post check by reapply
			$structChecker = new OgerDbStructMysql(static::$conn, static::$dbDef["dbName"]);
			$structChecker->setparams($params);
			$structChecker->setParam("log-level", OgerDbStruct::LOG_DEBUG);
			$structChecker->setParam("dry-run", true);
			$structChecker->updateDbStruct(static::$struct);
			$structChecker->reorderDbStruct();
			$postLog = trim($structChecker->flushLog());

			// use another struct checker and do surplus check
			$structChecker = new OgerDbStructMysql(static::$conn, static::$dbDef["dbName"], $opts);
			$structChecker->setParam("log-level", OgerDbStruct::LOG_DEBUG);
			$structChecker->setParam("dry-run", true);
			//$structChecker->forceDbStruct(static::$struct);
			$structChecker->cleanupDbStruct(static::$struct);
			$surplusLog = trim($structChecker->flushLog());

			// if log table exists write add full report there otherwise
			// trigger warnings for log, postlog and surpluslog
			// Errors are handled later
			if ($oldDbStruct['TABLES'][$structTableKey]) {
				$values = array(
					"beginTime" => $beginTime,
					"structSerial" => 0 + static::$struct['DBSTRUCT_META']['SERIAL'],
					"structTime" => "" . static::$struct['DBSTRUCT_META']['TIME'],
					"log" => "$log",
					"error" => ($bootstrapError ? "{$bootstrapError} " : "") . "$error",
					"postCheck" => "$postLog",
					"surplus" => "$surplusLog",
					"endTime" => date("c"),
				);
				$stmt = static::getStoreStmt("INSERT", $structTableName, $values);
				$pstmt = static::$conn->prepare($stmt);
				$pstmt->execute($values);
				unset($pstmt);
			}
			else {  // no struct log table
				trigger_error($log, E_USER_WARNING);
				if (trim($postLog)) {
					trigger_error($postLog, E_USER_WARNING);
				}
			}  // no struct log table

		}  // eo write log and do postcheck

		// rethrow catched errors
		if (trim($error)) {
			throw new Exception($error);
		}

		// preprocess script before updating dbstruct
		$postProcessFile = "dbstruct/postprocess.inc.php";
		if (file_exists($postProcessFile)) {
			include($postProcessFile);
		}

	}  // eo check and update struct




	/**
	* Check if name is a column name from given table.
	* Case insensitive check.
	*/
	public static function isColumn($tableName, $columnName) {
		// @file_put_contents("debug.localonly", "\n-----\ntableName=$tableName\n\n" . "columnName=$columnName\n\n", FILE_APPEND);
		// @file_put_contents("debug.localonly", "\n-----\n" . var_export(static::$struct['TABLES'][strtolower($tableName)]['COLUMNS'], true) . "\n\n", FILE_APPEND); exit;
		if (static::$struct['TABLES'][strtolower($tableName)]['COLUMNS'][strtolower($columnName)]) {
			return true;
		}
		return false;
	}  // eo is column name





}   // end of class

?>
