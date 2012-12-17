<?php
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Handle database structure.
* Supported databases are: Only MySql by now.
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
      $this->addLog($text);
    }
  }  // eo add log for log level

  /**
  * Add text to log buffer.
  * @param $text Text added to the log buffer.
  */
  public function addLog($text) {
    $this->log .= $text . "\n\n";
  }  // eo add log

  /**
  * Get log text.
  * @return Log content.
  */
  public function getLog() {
    return $this->log;
  }  // eo get log

  /**
  * Clear log buffer.
  * @return Log content.
  */
  public function clearLog() {
    $ret = $this->log;
    $this->log = "";
    return $ret;
  }  // eo clear log




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
  * @param $oldStruct Optional array with the new database structure.
  *        If not present it is located from the associated database.
  */
  /*
   * TODO check if needed table structure infos are realy driver independent
   */
  public function addDbStruct($newStruct, $oldStruct = null) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableDef) {

      $newTableName = $newTableDef["__TABLE_META__"]["TABLE_NAME"];

      if (!$oldStruct["__TABLES__"][$newTableKey]) {
        $this->addTable($newTableDef, $array("noForeignKeys" => true));
      }
      else {

        $this->orderTableColumns($newTableDef["__COLUMNS__"]);
        $afterColumnName = "";
        foreach ($newTableDef["__COLUMNS__"] as $newColumnKey => $newColumnDef) {
          if (!$oldStruct["__TABLES__"][$newTableKey]["__COLUMNS__"][$newColumnKey]) {
            $this->addTableColumn($newColumnDef, array("afterColumnName" => $afterColumnName);
          }
          // this column exists (old or new created) so the next missing column will be added after this
          $afterColumnName = $newColumnDef["COLUMN_NAME"];
        }  // eo column loop

        foreach ($newTableDef["__INDICES__"] as $newIndexKey => $newIndexDef) {
          if (!$oldStruct["__TABLES__"][$newTableKey]["__INDICES__"][$newIndexKey]) {
            $this->addTableIndex($newIndexDef);
          }
        }  // eo index loop

      }  // eo existing table

    }  // eo table loop


    // add foreign keys after all tables, columns and indices has been created
    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableDef) {

      $newTableName = $newTableDef["__TABLE_META__"]["TABLE_NAME"];

      foreach ($newTableDef["__FOREIGN_KEYS__"] as $newForeignKey => $newForeignDef) {
        if (!$oldStruct["__TABLES__"][$newTableKey]["__FOREIGN_KEYS__"][$newForeignKey]) {
          // FIXME $this->addTableForeignKey($newForeignDef);
        }
      }  // eo foreign key loop

    }  // eo table loop for foreign keys

  }  // eo add db struc


  /**
  * Add a table to the database structure.
  * @param $tableDef Array with the table definition.
  * @param $opts Optional option array. Key is option.
  *        Valid options: noIndices, noForeignKeys.
  */
  public function addTable($tableDef, $opts) {
    $stmt = $this->tableDefCreateStmt($tableDef, $opts);
    $this->log(static::LOG_DEBUG, $stmt);
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add table


  /**
  * Add a column to a table structure.
  * @param $columnDef Array with the table definition.
  */
  public function addTableColumn($columnDef, $opts) {
    $stmt = $this->columnDefAddStmt($columnDef, $opts);
    $this->log(static::LOG_DEBUG, $stmt);
    if (!$this->getParam("dry-run")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add column to table


  /**
  * Create an add table statement.
  * @param $tableDef Array with the table definition.
  * @return The SQL statement for table definition.
  */
  abstract public function tableDefCreateStmt($tableDef);


  /**
  * Force order of table columns.
  * @param columns Array with the column definitions.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered array with the column definitions.
  */
  abstract public function orderTableColumns(&$columns);


  /**
  * Create a column definition statement.
  * @param $columnDef  Array with column definition.
  * @return The SQL statement part for a column definition.
  */
  abstract public function columnDefStmt($columnDef);


  /**
  * Create a table index definition statement.
  * @param $indexDef  Array with index definition.
  * @return The SQL statement for the index definition.
  */
  abstract public function indexDefStmt($indexDef);


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
  * @param $columnDef Array with the column definition.
  * @return The SQL statement for adding a column.
  */
  abstract public function columnDefAddStmt($columnDef);


  /**
  * Update existing tables and columns and add missing one.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the new database structure.
  *        If not present it is located from the associated database.
  */
  public function updateDbStruct($newStruct, $oldStruct = null) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    // get old structure before adding missing parts
    // because we dont have to refresh that
    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    // add mising tables and columns
    $this->addDbStruct($newStruct, $oldStruct);

    // refresh existing tables and columns
    $this->refreshDbStruct($newStruct, $oldStruct);

  }  // eo update struc


  /**
  * Update an existing table and add missing columns.
  * @param $newTableDef Array with the new table structure.
  * @param $oldTableDef Array with the new table structure.
  */
  public function updateTable($newTableDef, $oldTableDef) {

    $this->refreshTable($newTableDef, $oldTableDef);

    // add missing columns
    foreach ($newTableDef["__COLUMNS__"] as $newColumnKey => $newColumnDef) {
      if (!$oldTableDef["__COLUMNS__"][$newColumnKey]) {
        $this->addTableColumn($newColumnDef);
      }
    }

    // FIXME indices

  }  // eo update table


  /**
  * Create an alter table statement for table defaults.
  * @param $newTableDef Array with the new table structure.
  * @param $oldTableDef Array with the new table structure.
  */
  abstract public function tableDefUpdateStmt($newTableDef, $oldTableDef);


  /**
  * Refresh an existing column.
  * @param $newColumnDef Array with the new column structure.
  * @param $oldColumnDef Array with the new column structure.
  */
  public function refreshColumn($newColumnDef, $oldColumnDef) {
    $stmt = $this->columnDefUpdateStmt($newColumnDef, $oldColumnDef);
    if ($stmt) {
      $this->log(static::LOG_DEBUG, $stmt);
      if (!$this->getParam("dry-run")) {
        $pstmt = $this->conn->prepare($stmt);
        $pstmt->execute();
      }
    }
  }  // eo update column


  /**
  * Create an alter table statement to alter a column.
  * @param $newColumnDef Array with the new column structure.
  * @param $oldColumnDef Array with the new column structure.
  */
  abstract public function columnDefUpdateStmt($newColumnDef, $oldColumnDef);


  /**
  * Refresh only existing tables and columns.
  * @param $newStruct Array with the new database structure.
  * @param $oldStruct Optional array with the new database structure.
  *        If not present it is located from the associated database.
  */
  public function refreshDbStruct($newStruct, $oldStruct = null) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    // get old structure before adding missing parts
    // because we dont have to update that
    if (!$oldStruct) {
      $oldStruct = $this->getDbStruct();
    }

    // update old table if exits
    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableDef) {
      $oldTableDef = $oldStruct["__TABLES__"][$newTableKey];
      if ($oldTableDef) {
        $this->refreshTable($newTableDef, $oldTableDef);
      }
    }  // eo table loop

  }  // eo refresh struc


  /**
  * Refresh an existing table and refresh existing columns.
  * @param $newTableDef Array with the new table structure.
  * @param $oldTableDef Array with the new table structure.
  */
  public function refreshTable($newTableDef, $oldTableDef, $opts) {

    // update table defaults
    $stmt = $this->tableDefUpdateStmt($newTableDef, $oldTableDef);
    if ($stmt) {
      $this->log(static::LOG_DEBUG, $stmt);
      if (!$this->getParam("dry-run")) {
        $pstmt = $this->conn->prepare($stmt);
        $pstmt->execute();
      }
    }

    // update existing columns
    foreach ($newTableDef["__COLUMNS__"] as $newColumnKey => $newColumnDef) {
      $oldColumnDef = $oldTableDef["__COLUMNS__"][$newColumnKey];
      if ($oldColumnDef) {
        $this->refreshColumn($newColumnDef, $oldColumnDef);
      }
    }

    // FIXME indices

    // FIXME foreign keys

  }  // eo refresh table







}  // eo class

?>
