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

  protected $conn;  ///< PDO instance created elsewhere.
  protected $dbName;  ///< Database name.
  protected $driverName;  ///< Driver name.
  protected $log;  ///< Log messages buffer.

  protected $opts = array();

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
  * Set option.
  * @param $name Option name.
  * @param $value New option value.
  * @return Old option value.
  */
  public function setOpt($name, $value) {
    $ret = $this->getOpt($name);
    $this->opts[$name] = $value;
    return $ret;
  }  // eo set opt

  /**
  * Get option.
  * @param $name Option name.
  * @return Old option value.
  */
  public function getOpt($name) {
    return $this->opts[$name];
  }  // eo get opt


  /**
  * Add text to log buffer if log level fits.
  * @param $msgLogLevel Log level for this log message.
  * @param $text Text added to the log buffer.
  */
  public function log($msgLogLevel, $text) {
    if ($msgLogLevel <= $this->getOpt("loglevel")) {
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
  * Add missing tables and columns to the database.
  * @param $newStruct Array with the new database structure.
  */
  public function addStruct($newStruct) {

    $this->checkDriverCompat($newStruct["__DRIVER_NAME__"], true);

    $oldStruct = $this->getDbStruct();

    foreach ($newStruct["__TABLES__"] as $newTableKey => $newTableDef) {

      if (!$oldStruct["__TABLES__"][$newTableKey]) {
        $this->addTable($newTableDef);
      }
      else {
        foreach ($newTableDef["__COLUMNS__"] as $newColumnKey => $newColumnDef) {
          if (!$oldStruct["__TABLES__"][$newTableKey]["__COLUMNS__"][$newColumnKey]) {
            $this->addColumn($newColumnDef);
          }
        }  // eo column loop
      }

    }  // eo table loop

  }  // eo add struc


  /**
  * Add a table to the database structure.
  * @param $tableDef Array with the table definition.
  */
  public function addTable($tableDef) {
    $stmt = $this->tableDefCreateStmt($tableDef);
    $this->log(static::LOG_DEBUG, $stmt);
    if (!$this->getOpt("dryrun")) {
      $pstmt = $this->conn->prepare($stmt);
      $pstmt->execute();
    }
  }  // eo add table


  /**
  * Add a column to a table structure.
  * @param $columnDef Array with the table definition.
  */
  public function addColumn($columnDef) {
    $stmt = $this->columnDefAddStmt($columnDef);
    $this->log(static::LOG_DEBUG, $stmt);
    if (!$this->getOpt("dryrun")) {
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
  * Create an add column statement.
  * @param $columnDef Array with the column definition.
  * @return The SQL statement for adding a column.
  */
  abstract public function columnDefAddStmt($columnDef);






}  // eo class

?>
