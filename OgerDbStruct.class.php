<?php
/*
#LICENSE BEGIN
#LICENSE END
*/

// TODO case insensitive -> case sensitive change for table names
// TODO removeDbStruct, forceDbStruct
// TODO TEST TEST TEST

/**
* Handle database structure.
* Supported databases are: Only MySql by now.
* Main top level methods are: getDbStruct(), addDbStruct(),
* refreshDbStruct(), updateDbStruct(), reorderDbStruct(), removeDbStruct() and forceDbStruct().<br>
* <em>ATTENTION:</em> Do not use the $opts parameter without reading the source
* because it is implementet only in some parts.<br>
* No renaming is provided.<br>
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
  * Add text to log buffer if log level fits.
  * @param $msgLogLevel Log level for this log message.
  * @param $text Text added to the log buffer.
  */
  public function log($msgLogLevel, $text) {
    if ($msgLogLevel <= $this->getParam("log-level")) {
      if ($this->getParam("echo-log")) {
        echo $text;
      }
      $this->addLog($text);
      $this->addLog($text);
    }
  }  // eo add log for log level

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
  * Create head info for struct array.
  * @return Header for struct array.
  */
  public function createStructHead() {

    // preapre db struct array
    $struct = array();

    $struct["__DRIVER_NAME__"] = $this->driverName;
    $struct["__DRIVER_INDEPENDENT__"] = false;
    $struct["__SERIAL__"] = time();
    $struct["__TIME__"] = date("c", $struct["__SERIAL__"]);

    $struct["__SCHEMA_META__"] = array();
    $struct["__TABLES__"] = array();

    return $struct;
  }  // eo create struct head


  /**
  * Quote a table or column name.
  * @param $name Name to be quoted.
  */
  public function quoteName($name) {
    return "{$this->quoteNamBegin}$name{$this->quoteNamEnd}";
  }  // eo quote name


  /**
  * Check for driver compatibility.
  * @param $driverName PDO driver name.
  * @param $thow If set to true an exception is thrown if not compatible, otherwise false is returned.
  */
  abstract public function checkDriverCompat($driverName, $trow = false);


  /**
  * Get the database structure.
  * Keys for table and column names are in lowercase, so identifier cannot
  * be used with different case. The structure does not contain privileges.
  * @return Array with database structure.
  */
  abstract public function getDbStruct();


  /**
  * Get the database structure for one table.
  * @param $tableName Name of the table for which we want to get the structure.
  * @return Array with table structure.
  */
  abstract public function getTableStruct($tablename);


  /**
  * Add missing tables and columns to the database.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  /*
   * TODO check if needed table structure infos are realy driver independent
   */
  public function addDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableStruct) {

      $newTableName = $newTableStruct["__TABLE_META__"]["TABLE_NAME"];
      $oldTableStruct = $oldStruct["__TABLES__"][$newTableKey];
      if (!$oldTableStruct) {
        $this->addTable($newTableStruct, array("noForeignKeys" => true));
      }
      else {
        $this->updateTable($newTableStruct, $oldTableStruct, array("noRefresh" => true, "noForeignKeys" => true));
      }
    }  // eo table loop


    // add foreign keys after all tables, columns and indices has been created
    if (!$opts["noForeignKeys"]) {
      foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableStruct) {

        $newTableName = $newTableStruct["__TABLE_META__"]["TABLE_NAME"];

        // foreign keys
        if ($newTableStruct["__FOREIGN_KEYS__"]) {
          foreach ($newTableStruct["__FOREIGN_KEYS__"] as $newFkKey => $newFkStruct) {
            if (!$oldStruct["__TABLES__"][$newTableKey]["__FOREIGN_KEYS__"][$newFkKey]) {
              $this->addTableForeignKey($newFkStruct);
            }
          }
        }  // eo constraint loop
      }  // eo table loop for foreign keys
    }  // eo include foreign keys

  }  // eo add db struc


  /**
  * Add a table to the database structure.
  * @param $tableStruct Array with the table definition.
  * @param $opts Optional option array. Key is option.
  *        Valid options: noIndices, noForeignKeys.
  */
  public function addTable($tableStruct, $opts) {
    $stmt = $this->tableDefCreateStmt($tableStruct, $opts);
    $this->log(static::LOG_DEBUG, "$stmt\n");
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add table


  /**
  * Add a column to a table structure.
  * @param $columnStruct Array with the table definition.
  */
  public function addTableColumn($columnStruct, $opts) {
    $stmt = $this->columnDefAddStmt($columnStruct, $opts);
    $this->log(static::LOG_DEBUG, "$stmt\n");
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add column to table


  /**
  * Create an add table statement.
  * @param $tableStruct Array with the table definition.
  * @param $opts Optional options array.
  * @return The SQL statement for table definition.
  */
  abstract public function tableDefCreateStmt($tableStruct, $opts);


  /**
  * Force order of table columns.
  * @param columns Array with the column definitions.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered array with the column definitions.
  */
  abstract public function orderTableStructColumns(&$columns);


  /**
  * Create a column definition statement.
  * @param $columnStruct  Array with column definition.
  * @param $opts Optional options array.
  * @return The SQL statement part for a column definition.
  */
  abstract public function columnDefStmt($columnStruct, $opts = array());


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
  * Update existing tables and columns and add missing one.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function updateDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    // get old structure before adding missing parts
    // because we dont have to refresh that
    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    // add mising tables, columns, indices - and foreign keys
    $this->addDbStruct($newStruct, $oldStruct);

    // refresh existing tables and columns
    $this->refreshDbStruct($newStruct, $oldStruct);

  }  // eo update struc


  /**
  * Update an existing table and add missing columns.
  * @param $newTableStruct Array with the new table structure.
  * @param $oldTableStruct Array with the old table structure.
  */
  public function updateTable($newTableStruct, $oldTableStruct, $opts = array()) {

    if ($oldTableStruct === null) {
      $oldTableStruct = $this->getTableStruct($newTableStruct["__TABLE_META__"]["TABLE_NAME"]);
    }

    if (!$opts["noRefresh"]) {
      $this->refreshTable($newTableStruct, $oldTableStruct);
    }

    // add columns
    $this->orderTableStructColumns($newTableStruct["__COLUMNS__"]);
    $afterColumnName = -1;
    foreach ($newTableStruct["__COLUMNS__"] as $newColumnKey => $newColumnStruct) {
      if (!$oldTableStruct["__COLUMNS__"][$newColumnKey]) {
        $this->addTableColumn($newColumnStruct, array("afterColumnName" => $afterColumnName));
      }
      // this column exists (old or new created) so the next missing column will be added after this
      $afterColumnName = $newColumnStruct["COLUMN_NAME"];
    }  // eo column loop

    if (!$opts["noIndices"]) {
      foreach ($newTableStruct["__INDICES__"] as $newIndexKey => $newIndexStruct) {
        if (!$oldTableStruct["__INDICES__"][$newIndexKey]) {
          $this->addTableIndex($newIndexStruct);
        }
      }
    }  // eo index

    if (!$opts["noForeignKeys"]) {
      foreach ($newTableStruct["__FOREIGN_KEYS__"] as $newFkKey => $newFkStruct) {
        if (!$oldTableStruct["__FOREIGN_KEYS__"][$newFkKey]) {
          $this->addTableForeignKey($newFkStruct);
        }
      }
    }  // eo foreign keys

  }  // eo update existing table


  /**
  * Create an alter table statement for table defaults.
  * @param $newTableStruct Array with the new table structure.
  * @param $oldTableStruct Array with the old table structure.
  */
  abstract public function tableDefUpdateStmt($newTableStruct, $oldTableStruct);


  /**
  * Refresh an existing table column.
  * @param $newColumnStruct Array with the new column structure.
  * @param $oldColumnStruct Array with the old column structure.
  */
  public function refreshTableColumn($newColumnStruct, $oldColumnStruct) {
    $stmt = $this->columnDefUpdateStmt($newColumnStruct, $oldColumnStruct);
    if ($stmt) {
      $this->log(static::LOG_DEBUG, "$stmt\n");
      if (!$this->getParam("dry-run")) {
        $pstmt = $this->conn->prepare($stmt);
        $pstmt->execute();
      }
    }
  }  // eo update column


  /**
  * Create an alter table statement to alter a column.
  * @param $newColumnStruct Array with the new column structure.
  * @param $oldColumnStruct Array with the old column structure.
  */
  abstract public function columnDefUpdateStmt($newColumnStruct, $oldColumnStruct);


  /**
  * Refresh only existing tables and columns.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  */
  public function refreshDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    // get old structure before adding missing parts
    // because we dont have to update that
    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    // refresh old table if exits
    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableStruct) {
      $oldTableStruct = $oldStruct["__TABLES__"][$newTableKey];
      if ($oldTableStruct) {
        $this->refreshTable($newTableStruct, $oldTableStruct);
      }
    }  // eo table loop

  }  // eo refresh struc


  /**
  * Refresh an existing table and refresh existing columns.
  * @param $newTableStruct Array with the new table structure.
  * @param $oldTableStruct Array with the old table structure.
  */
  public function refreshTable($newTableStruct, $oldTableStruct, $opts) {

    // refresh table defaults
    $stmt = $this->tableDefUpdateStmt($newTableStruct, $oldTableStruct);
    if ($stmt) {
      $this->log(static::LOG_DEBUG, "$stmt\n");
      if (!$this->getParam("dry-run")) {
        $pstmt = $this->conn->prepare($stmt);
        $pstmt->execute();
      }
    }

    // refresh existing columns
    foreach ($newTableStruct["__COLUMNS__"] as $newColumnKey => $newColumnStruct) {
      $oldColumnStruct = $oldTableStruct["__COLUMNS__"][$newColumnKey];
      if ($oldColumnStruct) {
        $this->refreshTableColumn($newColumnStruct, $oldColumnStruct);
      }
    }

    // refresh existing indices
    foreach ($newTableStruct["__INDICES__"] as $newIndexKey => $newIndexStruct) {
      $oldIndexStruct = $oldTableStruct["__INDICES__"][$newIndexKey];
      if ($oldIndexStruct) {
        $this->refreshTableIndex($newIndexStruct, $oldIndexStruct);
      }
    }

    // refresh existing foreign keys
    foreach ($newTableStruct["__FOREIGN_KEYS__"] as $newFkKey => $newFkStruct) {
      $oldFkStruct = $oldTableStruct["__FOREIGN_KEYS__"][$newFkKey];
      if ($oldFkStruct) {
        $this->refreshTableForeignKey($newFkStruct, $oldFkStruct);
      }
    }

  }  // eo refresh table


  /**
  * Order columns of tables.
  * Order only columns of tables because the order of
  * columns in indices and foreign keys is treated significant
  * and therefore handled by refreshing.
  * Tables do not have a specific order inside the database.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function reorderDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableStruct) {
      $oldTableStruct = $oldStruct["__TABLES__"][$newTableKey];
      if ($oldTableStruct) {
        $this->reorderTableColumns($newTableStruct, $oldTableStruct);
      }
    }  // eo table loop

  }  // eo order db struct


  /**
  * Remove surpluss tables, columns, indices and foreign keys.
  * Despite the first impression not the given database struct is removed
  * but everything that is above.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function removeDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    foreach ($oldStruct["__TABLES__"] as $oldTableKey => $oldTableStruct) {


    }  // eo table loop

  }  // eo order db struct


  /**
  * Force database structure.
  * Forces the given database structure by adding, updating and deleting divergent structure.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the old database structure.
  *        If not present it is located from the associated database.
  * @param $opts Optional options array.
  */
  public function forceDbStruct($newStruct, $oldStruct = null, $opts = array()) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    $this->updateDbStruct($newStruct, $oldStruct, $opts);

    // do not hand over old struct because maybe heavily changed by updateDbStruct
    $this->removeDbStruct($newStruct);

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
    $this->log(static::LOG_DEBUG, "$stmt\n");
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add index


  /**
  * Add a foreign key to a table.
  * @param $fkStruct Array with the foreign key definition.
  * @param $opts Optional option array. Key is option.
  */
  public function addTableForeignKey($fkStruct, $opts = array()) {
    $tableName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
    $stmt = "ALTER TABLE $tableName ADD " . $this->foreignKeyDefStmt($fkStruct, $opts);
    $this->log(static::LOG_DEBUG, "$stmt\n");
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add foreign key


  /**
  * Refresh an existing table index.
  * @param $newIndexStruct Array with the new index structure.
  * @param $oldIndexStruct Array with the old index structure.
  */
  public function refreshTableIndex($newIndexStruct, $oldIndexStruct) {
    $stmt = $this->indexDefUpdateStmt($newIndexStruct, $oldIndexStruct);
    if ($stmt) {
      $stmts = explode(";", $stmt);
      foreach ($stmts as $stmt) {
        $this->log(static::LOG_DEBUG, "$stmt\n");
        if (!$this->getParam("dry-run")) {
          $pstmt = $this->conn->prepare($stmt);
          $pstmt->execute();
        }
      }  // eo stmt loop
    }
  }  // eo update index


  /**
  * Refresh an existing foreign key.
  * @param $newFkStruct Array with the new foreign key structure.
  * @param $oldFkStruct Array with the old foreign key structure.
  */
  public function refreshTableForeignKey($newFkStruct, $oldFkStruct) {
    $stmt = $this->foreignKeyDefUpdateStmt($newFkStruct, $oldFkStruct);
    if ($stmt) {
      $stmts = explode(";", $stmt);
      foreach ($stmts as $stmt) {
        $this->log(static::LOG_DEBUG, "$stmt\n");
        if (!$this->getParam("dry-run")) {
          $pstmt = $this->conn->prepare($stmt);
          $pstmt->execute();
        }
      }  // eo stmt loop
    }
  }  // eo update foreign key



}  // eo class

?>