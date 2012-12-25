<?php
/*
#LICENSE BEGIN
#LICENSE END
*/

// TODO cleanup public interface
// TODO TEST TEST TEST

/**
* Handle database structure.
* Supported database systems are: Only MySql by now.<br>
* No renaming is provided by design.<br>
* For all option arrays there are additional options possible in the
* driver dependent implementation - so see there too.

*/
abstract class OgerDbStruct {

  const LOG_LOG = 1;
  const LOG_DEBUG = 5;
  const LOG_NOTICE = 7;

  protected $conn;  ///< PDO instance created elsewhere.
  protected $dbName;  ///< Database name.
  protected $driverName;  ///< Driver name.
  protected $log;  ///< Log messages buffer.

  protected $params = array();

  protected $quoteNamBegin = '"';
  protected $quoteNamEnd = '"';


  /**
   * Construct with a PDO instance and database name.
   * @param $conn  A PDO instance that represents a valid database connection.
   * @param $dbName  Database name - because this cannot be detected from the PDO connection.
   */
  public function __construct($conn, $dbName) {
    $this->conn = $conn;
    $this->dbName = $dbName;
    $this->driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
  }  // eo constructor


  /**
   * Get driver dependend instance.
   * For params see @see __construct().
   * @throw Throws an exception if the driver for given PDO object is not supported.S
   */
  static function getInstance($conn, $dbName) {

    // check for supported driver
    $driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    switch ($driverName) {
    case "mysql":
      $className = "OgerDbStruct" . ucfirst($driverName);
      $dwc = new $className($conn, $dbName);
      return ($dwc);
      break;
    default:
      throw new Exception("PDO driver {$this->driverName} not supported.");
    }
  }  // eo construct


  /**
  * Get the current database structure.
  * @param $opts Optional options array where the key is the option name.<br>
  *        Valid options are:<br>
  *        - whereTables: A where condition to restrict the included tables.
  *          If empty all tables are included.
  * @return Array with database structure.
  */
  public public function getDbStruct($opts = array());


  /**
  * Create head info for struct array.
  * @return Header for struct array.
  */
  public function createStructHead() {

    // preapre db struct array
    $startTime = time();

    $struct = array();
    $struct["__DBSTRUCT_META__"] = array(
      "__DRIVER_NAME__" => $this->driverName,
      "__DRIVER_INDEPENDENT__" => false,
      "__SERIAL__" => $startTime,
      "__TIME__" => date("c", $startTime),
    );

    $struct["__SCHEMA_META__"] = array();
    $struct["__TABLES__"] = array();

    return $struct;
  }  // eo create struct head


  /**
  * Add missing tables and columns to the database.
  * @param $refStruct Array with the reference database structure.
  * @param $opts Optional options array. Keys are options.<br>
  *        Valid optios are:<br>
  *        - noForeignKeys<br>
  */
  abstract public function addDbStruct($refDbStruct, $opts = array());


  /**
  * Update existing tables and columns and add missing one.
  * @param $refStruct Array with the reference database structure.
  * @param $curStruct Optional array with the current database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function updateDbStruct($refStruct, $curStruct = null, $opts = array()) {

    $this->checkDriverCompat($refStruct);

    // get current structure before adding missing parts
    // because we dont have to refresh that
    if ($curStruct === null) {
      $curStruct = $this->getDbStruct();
    }

    // add mising tables, columns, indices - and foreign keys
    $this->addDbStruct($refStruct, $curStruct);

    // refresh existing tables and columns
    $this->refreshDbStruct($refStruct, $curStruct);

  }  // eo update struc














  /**
  * Create a table index definition statement.
  * @param $indexStruct  Array with index definition.
  * @return The SQL statement for the index definition.
  */
  abstract public function indexDefStmt($indexStruct);


  /**
  * Force order of index columns.
  * @param columns Array with the column definitions.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered array with the column definitions.
  */
  abstract public function orderIndexColumns(&$columns);


  /**
  * Create an add column statement.
  * @param $columnStruct Array with the column definition.
  * @param $opts Optional options array.
  * @return The SQL statement for adding a column.
  */
  abstract public function columnDefAddStmt($columnStruct, $opts);




  /**
  * Refresh an existing table column.
  * @param $refColumnStruct Array with the reference column structure.
  * @param $curColumnStruct Array with the current column structure.
  */
  public function refreshTableColumn($refColumnStruct, $curColumnStruct) {
    $stmt = $this->columnDefUpdateStmt($refColumnStruct, $curColumnStruct);
    $this->executeStmt($stmt);
  }  // eo update column


  /**
  * Create an alter table statement to alter a column.
  * @param $refColumnStruct Array with the reference column structure.
  * @param $curColumnStruct Array with the current column structure.
  */
  abstract public function columnDefUpdateStmt($refColumnStruct, $curColumnStruct);


  /**
  * Refresh only existing tables and columns.
  * @param $refStruct Array with the reference database structure.
  * @param $curStruct Optional array with the current database structure.
  *        If not present it is located from the associated database.
  */
  public function refreshDbStruct($refStruct, $curStruct = null, $opts = array()) {

    $this->checkDriverCompat($refStruct);

    // get current structure before adding missing parts
    // because we dont have to update that
    if ($curStruct === null) {
      $curStruct = $this->getDbStruct();
    }

    // refresh current table if exits
    foreach ($refStruct["__TABLES__"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $curStruct["__TABLES__"][$refTableKey];
      if ($curTableStruct) {
        $this->refreshTable($refTableStruct, $curTableStruct);
      }
    }  // eo table loop

  }  // eo refresh struc


  /**
  * Order columns of tables.
  * Order only columns of tables because the order of
  * columns in indices and foreign keys is treated significant
  * and therefore handled by refreshing.
  * Tables do not have a specific order inside the database.
  * @param $refStruct Array with the reference database structure.
  * @param $curStruct Optional array with the current database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function reorderDbStruct($refStruct, $curStruct = null, $opts = array()) {

    $this->checkDriverCompat($refStruct);

    if ($curStruct === null) {
      $curStruct = $this->getDbStruct();
    }

    foreach ($refStruct["__TABLES__"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $curStruct["__TABLES__"][$refTableKey];
      if ($curTableStruct) {
        $this->reorderTableColumns($refTableStruct, $curTableStruct);
      }
    }  // eo table loop

  }  // eo order db struct


  /**
  * Cleanup surpluss tables, columns, indices and foreign keys.
  * Despite the first impression not the given database struct is cleaned up
  * but everything that is above.
  * @param $refStruct Array with the reference database structure.
  * @param $curStruct Optional array with the current database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function cleanupDbStruct($refStruct, $curStruct = null, $opts = array()) {

    $this->checkDriverCompat($refStruct);

    if ($curStruct === null) {
      $curStruct = $this->getDbStruct();
    }

    // first cleanup foreign keys before we remove tables or columns
    foreach ($curStruct["__TABLES__"] as $curTableKey => $curTableStruct) {
      $refTableStruct = $refStruct["__TABLES__"][$curTableKey];
      if (!$refTableStruct) {
        continue;
      }
      $tableName = $this->quoteName($curTableStruct["__TABLE_META__"]["TABLE_NAME"]);
      foreach ($curTableStruct["__FOREIGN_KEYS__"] as $fkKey => $fkStruct) {
        if (!$refTableStruct["__FOREIGN_KEYS__"][$fkKey]) {
          $fkName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);
          $stmt = "ALTER TABLE {$tableName} DROP CONSTRAINT {$fkName}";
          $this->executeStmt($stmt);
        }
      }
    }  // table loop for foreign keys

    // cleanup tables
    foreach ($curStruct["__TABLES__"] as $curTableKey => $curTableStruct) {
      $refTableStruct = $refStruct["__TABLES__"][$curTableKey];
      $tableName = $this->quoteName($curTableStruct["__TABLE_META__"]["TABLE_NAME"]);
      if (!$refTableStruct) {
        $stmt = "DOP TABLE {$tableName}";
        $this->executeStmt($stmt);
      }
      else {
        // cleanup indices
        foreach ($curTableStruct["__INDICES__"] as $curIndexKey => $curIndexStruct) {
          if (!$refTableStruct["__INDICES__"][$curIndexKey]) {
            $indexName = $this->quoteName($curIndexStruct["__INDEX_META__"]["INDEX_NAME"]);
            $stmt = "ALTER TABLE {$tableName} DROP INDEX {$indexName}";
            $this->executeStmt($stmt);
          }
        }
        // cleanup columns
        foreach ($curTableStruct["__COLUMNS__"] as $curColumnKey => $curColumnStruct) {
          if (!$refTableStruct["__COLUMNS__"][$curColumnKey]) {
            $columnName = $this->quoteName($curColumnStruct["COLUMN_NAME"]);
            $stmt = "ALTER TABLE {$tableName} DROP COLUMN {$columnName}";
            $this->executeStmt($stmt);
          }
        }
      }  // eo existing table
    }  // eo table loop

  }  // eo order db struct


  /**
  * Force database structure.
  * Forces the given database structure by adding, updating and deleting divergent structure.
  * @param $refStruct Array with the reference database structure.
  * @param $curStruct Optional array with the current database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function forceDbStruct($refStruct, $curStruct = null, $opts = array()) {

    $this->checkDriverCompat($refStruct);

    if ($curStruct === null) {
      $curStruct = $this->getDbStruct();
    }

    $this->updateDbStruct($refStruct, $curStruct, $opts);

    // do not hand over current struct because maybe heavily changed by updateDbStruct
    $this->cleanupDbStruct($refStruct);

  }  // eo order db struct


  /**
  * Create a table foreign key definition statement.
  * @param $fkStruct  Array with foreign key definition.
  * @return The SQL statement for the foreign key sql statement.
  */
  abstract public function foreignKeyDefStmt($fkStruct);


  /**
  * Add a index to a table.
  * @param $indexStruct Array with the index definition.
  * @param $opts Optional option array. Key is option.
  */
  public function addTableIndex($indexStruct, $opts = array()) {
    $tableName = $this->quoteName($indexStruct["__INDEX_META__"]["TABLE_NAME"]);
    $stmt = "ALTER TABLE $tableName ADD " . $this->indexDefStmt($indexStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add index


  /**
  * Add a foreign key to a table.
  * @param $fkStruct Array with the foreign key definition.
  * @param $opts Optional option array. Key is option.
  */
  public function addTableForeignKey($fkStruct, $opts = array()) {
    $tableName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
    $stmt = "ALTER TABLE $tableName ADD " . $this->foreignKeyDefStmt($fkStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add foreign key


  /**
  * Refresh an existing table index.
  * @param $refIndexStruct Array with the reference index structure.
  * @param $curIndexStruct Array with the current index structure.
  */
  public function refreshTableIndex($refIndexStruct, $curIndexStruct) {
    $stmt = $this->indexDefUpdateStmt($refIndexStruct, $curIndexStruct);
    $this->executeStmt($stmt);
  }  // eo update index


  /**
  * Refresh an existing foreign key.
  * @param $refFkStruct Array with the reference foreign key structure.
  * @param $curFkStruct Array with the current foreign key structure.
  */
  public function refreshTableForeignKey($refFkStruct, $curFkStruct) {
    $stmt = $this->foreignKeyDefUpdateStmt($refFkStruct, $curFkStruct);
    $this->executeStmt($stmt);
  }  // eo update foreign key


  /**
  * Format the database struct array into a string.
  * This should result in a more diff friendly output.
  * @param $struct Array with the database structure.
  * @param $opts Optional options array.
  * TODO not ready
  */
  public function formatDbStruct($struct, $opts = array()) {

    return var_export($struct, true);

    $str = "array (\n";

    $delim = "  ";
    foreach ($struct as $key => $value) {
      if ($key != "__TABLES__") {
        $valueStr = var_export($value, true);
        $valueStr = preg_replace("/\n\s*/s", " ", $valueStr);
        $str .= "$delim'$key' => $valueStr";
        $delim = ",\n  ";
      }
    };

    $tables = $struct["__TABLES__"];
    if (is_array($tables)) {
      $str .= "{$delim}'__TABLES__' => ";
      $valueStr = var_export($tables, true);
      //$valueStr = preg_replace("/\n\s*/s", " ", $valueStr);
      $str .= $valueStr;
    }  // eo have tables


    $str .= "\n)\n";

    return $str;
  }  // eo format db struct



  // ############################################
  // some helper methods and setter/getter


  /**
  * Set parameter.
  * @param $name Parameter name.
  * Valid parameter names are: dryrun, loglevel.
  * @param $value New parameter value.
  * @return Old parameter value.
  */
  public function setParam($name, $value) {
    $ret = $this->getParam($name);
    $this->params[$name] = $value;
    return $ret;
  }  // eo set param

  /**
  * Get parameter.
  * @param $name Parameter name.
  * @return Old parameter value.
  */
  public function getParam($name) {
    return $this->params[$name];
  }  // eo get param

  /**
  * Get all parameters.
  * @return All parameter names and values.
  */
  public function getParams() {
    return $this->params;
  }  // eo get params


  /**
  * Add text to the log buffer if log level fits.
  * @param $msgLogLevel Log level for this log message.
  * @param $text Text added to the log buffer.
  */
  public function log($msgLogLevel, $text) {
    if ($msgLogLevel <= $this->getParam("log-level")) {
      if ($this->getParam("echo-log")) {
        echo $text;
      }
      $this->addLog($text);
    }
  }  // eo add text for log level

  /**
  * Add text to log buffer.
  * @param $text Text added to the log buffer.
  */
  public function addLog($text) {
    $this->log .= $text;
  }  // eo add log

  /**
  * Get log text.
  * @return Log content.
  */
  public function getLog() {
    return $this->log;
  }  // eo get log

  /**
  * Flush log buffer.
  * @return Log content.
  */
  public function flushLog() {
    $ret = $this->log;
    $this->log = "";
    return $ret;
  }  // eo flush log


  /**
  * Check for driver compatibility.
  * @param $struct Database structure array.
  * @param $throw Throw an exception if not compatible.
  */
  abstract public function checkDriverCompat($dbStruct);


  /**
  * Quote a table or column name.
  * @param $name Name to be quoted.
  */
  public function quoteName($name) {
    return "{$this->quoteNamBegin}$name{$this->quoteNamEnd}";
  }  // eo quote name


  /**
  * Prepares and executes an sql statement.
  * Respects dry-run and logging parameters.
  * @param $stmt SQL statement.
  * @param $values Associative array with parameter => value pairs.
  */
  public function executeStmt($stmt, $values = array()) {
    $stmts = explode(";", $stmt);
    foreach ($stmts as $stmt) {
      if ($stmt) {
        $this->log(static::LOG_DEBUG, "$stmt;\n");
        if (!$this->getParam("dry-run")) {
          $pstmt = $this->conn->prepare($stmt);
          $pstmt->execute();
        }
      }
    }
  }  // eo execute stmt


  // oe helper methods
  // ###############################

}  // eo class

?>
