<?php
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Handle database structure for mysql databases.
* @see class OgerDbStruct.
* We handle Collations (which in turn modifies charset).
*/
class OgerDbStructMysql extends OgerDbStruct {


  protected $quoteNamBegin = '`';
  protected $quoteNamEnd = '`';


  /**
   * @see OgerDbStruct::__construct().
   * @throw Throws an exception if the driver name does not fit.
   */
  public function __construct($conn, $dbName) {
    parent::__construct($conn, $dbName);
    if ($this->driverName != "mysql") {
      throw new Exception("Invalid driver {$this->driverName} for " . __CLASS__);
    }
  }  // eo constructor


  /**
  * Check for driver compatibility.
  * @see OgerDbStruct::checkDriverCompat().
  */
  public function checkDriverCompat($driverName, $trow = false) {
    if ($driverName != "mysql") {
      if ($throw) {
        throw new Exception ("Driver '$driverName' not compatible. 'mysql' expected.");
      }
      return false;
    }
    return true;
  }


  /**
  * Get information schema for a mysql database.
  * @see OgerDbStruct::getDbStruct().
  * @throw Throws an exception if more than one schema is found for the database name from the PDO object.
  */
  public function getDbStruct() {

    // preapre db struct array
    $struct = $this->createStructHead();

    // ------------------
    // get schema info

    $defCatalogName = "def";

    $pstmt = $this->conn->prepare("
        SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
          FROM INFORMATION_SCHEMA.SCHEMATA
          WHERE INFORMATION_SCHEMA.SCHEMATA.CATALOG_NAME=:catalogName AND
                INFORMATION_SCHEMA.SCHEMATA.SCHEMA_NAME=:dbName
        ");
    $pstmt->execute(array("catalogName" => $defCatalogName, "dbName" => $this->dbName));
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    if (count($schemaRecords) > 1) {
      throw new Exception("More than one schema found with name :{$this->dbName}");
    }

    // return silently if no schema found
    if (count($schemaRecords) < 1) {
      return $struct;
    }

    $struct["__SCHEMA_META__"] = reset($schemaRecords);


    // -----------------------
    // get tables info

    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME, TABLE_COLLATION
          FROM INFORMATION_SCHEMA.TABLES
          WHERE INFORMATION_SCHEMA.TABLES.TABLE_CATALOG=:catalogName AND
                INFORMATION_SCHEMA.TABLES.TABLE_SCHEMA=:dbName
        ");
    $pstmt->execute(array("catalogName" => $defCatalogName, "dbName" => $this->dbName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ($tableRecords as $tableRecord) {

      $tableName = $tableRecord["TABLE_NAME"];
      $tableKey = strtolower($tableName);

      $struct["__TABLE_NAMES__"][$tableKey] = $tableName;
      $struct["__TABLES__"][$tableKey]["__TABLE_META__"] = $tableRecord;


      // -------------------
      // get columns info
      // COLUMN_KEY is short index info (PRI, MUL, ...)
      // most important is COLUMN_TYPE - e.g. int(11)

      $pstmt = $this->conn->prepare("
          SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
                 COLUMN_TYPE, DATA_TYPE,
                 COLUMN_DEFAULT, IS_NULLABLE,
                 CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME,
                 NUMERIC_PRECISION, NUMERIC_SCALE,
                 COLUMN_KEY, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE INFORMATION_SCHEMA.COLUMNS.TABLE_CATALOG=:catalogName AND
                  INFORMATION_SCHEMA.COLUMNS.TABLE_SCHEMA=:dbName AND
                  INFORMATION_SCHEMA.COLUMNS.TABLE_NAME=:tableName
            ORDER BY ORDINAL_POSITION
          ");

      $pstmt->execute(array("catalogName" => $defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
      $columnRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
      $pstmt->closeCursor();

      foreach ($columnRecords as $columnRecord) {

        $columnName = $columnRecord["COLUMN_NAME"];
        $columnKey = strtolower($columnName);

        $struct["__TABLES__"][$tableKey]["__COLUMN_NAMES__"][$columnKey] = $columnName;
        $struct["__TABLES__"][$tableKey]["__COLUMNS__"][$columnKey] = $columnRecord;

      }  // eo column loop


      // ---------------
      // get key info

      // the KEY_COLUMN_USAGE misses info like unique, nullable, etc so wie use STATISTICS for now
      $pstmt = $this->conn->prepare("
          SELECT INDEX_NAME, SEQ_IN_INDEX AS ORDINAL_POSITION, COLUMN_NAME,	NON_UNIQUE
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE INFORMATION_SCHEMA.STATISTICS.TABLE_CATALOG=:catalogName AND
                  INFORMATION_SCHEMA.STATISTICS.TABLE_SCHEMA=:dbName AND
                  INFORMATION_SCHEMA.STATISTICS.TABLE_NAME=:tableName
            ORDER BY INDEX_NAME, ORDINAL_POSITION
          ");
      $pstmt->execute(array("catalogName" => $defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
      $indexRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
      $pstmt->closeCursor();

      foreach ($indexRecords as $indexRecord) {

        $indexName = $indexRecord["INDEX_NAME"];
        $indexKey = strtolower($indexName);

        $indexRecord["UNIQUE"] = ($indexRecord["NON_UNIQUE"] ? "0" : "1");

        // detect index type
        $indexType = "";
        if ($indexName == "PRIMARY") {
          $indexType = "PRIMARY";
        }
        elseif (!$indexRecord["NON_UNIQUE"]) {
          $indexType = "UNIQUE";
        }

        $struct["__TABLES__"][$tableKey]["__INDEX_NAMES__"][$indexKey] = $indexName;

        // the meta info is taken from the last column info which overwrites the prevous meta info
        $struct["__TABLES__"][$tableKey]["__INDICES__"][$indexKey]["__INDEX_META__"]["INDEX_NAME"] = $indexName;
        $struct["__TABLES__"][$tableKey]["__INDICES__"][$indexKey]["__INDEX_META__"]["INDEX_KEY_TYPE"] = $indexType;

        // index columns
        $indexColumnKey = strtolower($indexRecord["COLUMN_NAME"]);
        $struct["__TABLES__"][$tableKey]["__INDICES__"][$indexKey]["__INDEX_COLUMNS__"][$indexColumnKey] = $indexRecord;

      }  // eo index loop

    }  // eo table loop

    return $struct;
  }  // eo get db structure


  /**
  * Create an add table statement.
  * @see OgerDbStruct::tableDefCreateStmt().
  */
  public function tableDefCreateStmt($tableDef) {

    $tableMeta = $tableDef["__TABLE_META__"];
    $tableName = $this->quoteName($tableMeta["TABLE_NAME"]);
    $stmt = "CREATE TABLE $tableName (\n  ";

    // force column order
    $columns = array();
    foreach ($tableDef["__COLUMNS__"] as $columnKey => $columnDef) {
      $columns[$columnDef["ORDINAL_POSITION"] * 1] = $columnDef;
    }
    ksort($columns);


    $delim = "";
    foreach ($columns as $columnDef) {
      $stmt .= $delim . $this->columnDefStmt($columnDef);
      $delim = ",\n  ";
    }  // eo column loop

    // indices
    if ($tableDef["__INDICES__"]) {
      foreach ($tableDef["__INDICES__"] as $indexKey => $indexDef) {
        $stmt .= $delim . $this->indexDefStmt($indexDef);
      }

    }  // eo index loop

    $stmt .= "\n)";

    // table defaults
    // Note on charset:
    // Looks like mysql derives the charset from the collation
    // via the INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY table
    // and does this internally automatically if a collation is given.
    // So we depend on this - provide the collation and omit the charset.
    $stmt .= " DEFAULT" .
          // " CHARSET={$tableMeta['']}" .  // see note above
          " COLLATE={$tableMeta['TABLE_COLLATION']}";

    return $stmt;
  }  // eo add table


  /**
  * Create a column definition statement.
  * @see OgerDbStruct::columnDefStmt().
  */
  public function columnDefStmt($columnDef) {

    $stmt = $this->quoteName($columnDef["COLUMN_NAME"]) .
            " " . $columnDef["COLUMN_TYPE"] .
            ($columnDef["COLLATION_NAME"] ? " COLLATE {$columnDef["COLLATION_NAME"]}" : "") .
            ($columnDef["IS_NULLABLE"] == "NO" ? " NOT NULL" : "") .
            ($columnDef["EXTRA"] ? " {$columnDef["EXTRA"]}" : "");

    // create column default
    if (!is_null($columnDef["COLUMN_DEFAULT"]) || $columnDef["IS_NULLABLE"] == "YES") {

      $default = $columnDef['COLUMN_DEFAULT'];
      if (is_null($default)) {
        $default = "NULL";
      }
      elseif ($default == "CURRENT_TIMESTAMP") {
        // nothing to do
      }
      else {
        // quote default value
        $default = "'$default'";
      }

      $stmt .= " DEFAULT $default";
    }  // eo default

    return $stmt;
  }  // eo column def stmt


  /**
  * Create a table index statement.
  * @see OgerDbStruct::indexDefStmt().
  */
  public function indexDefStmt($indexDef) {

    $indexName = " " . $this->quoteName($indexDef["__INDEX_META__"]["INDEX_NAME"]);

    // the primary key has no separate name
    if ($indexDef["__INDEX_META__"]["INDEX_KEY_TYPE"] == "PRIMARY") {
      $indexName = "";
    }

    $indexKeyType = $indexDef["__INDEX_META__"]["INDEX_KEY_TYPE"];
    if ($indexKeyType) {
      $indexKeyType .= " ";
    }

    $stmt .= "{$indexKeyType}KEY{$indexName}";

    // force order of column names
    $colNames = array();
    foreach ($indexDef["__INDEX_COLUMNS__"] as $indexColumnDef) {
      $colNames[$indexColumnDef["ORDINAL_POSITION"] * 1] = $this->quoteName($indexColumnDef["COLUMN_NAME"]);
    }
    ksort($colNames);

    // put fields to statement
    $stmt .= " (" . implode(", ", $colNames) . ")";

    return $stmt;
  }  // eo index def


  /**
  * Create an add column statement.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function columnDefAddStmt($columnDef) {

    $stmt = "ALTER TABLE " .
            $this->quoteName($columnDef["TABLE_NAME"]) .
            " ADD COLUMN " .
            $this->columnDefStmt($columnDef);

    return $stmt;
  }  // eo add column


  /**
  * Create an alter table statement for table defaults.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function tableDefUpdateStmt($oldTableDef, $newTableDef) {

    $oldTableMeta = $oldTableDef["__TABLE_META__"];
    $newTableMeta = $newTableDef["__TABLE_META__"];

    if ($newTableMeta["TABLE_COLLATION"] && $newTableMeta["TABLE_COLLATION"] != $oldTableMeta["TABLE_COLLATION"]) {
      $stmt .= $this->quoteName($oldTableMeta["TABLE_NAME"]) .
               " COLLATE " . $newTableMeta["TABLE_COLLATION"];
    }

    // complete if anything changed
    if ($stmt) {
      $stmt = "ALTER TABLE $stmt";
    }

    return $stmt;
  }  // eo update table


  /**
  * Create an alter table statement to alter a column.
  * @see OgerDbStruct::columnDefUpdateStmt().
  */
  public function columnDefUpdateStmt($oldColumnDef, $newColumnDef) {

    if ($newColumnDef["COLUMN_TYPE"] != $oldColumnDef["COLUMN_TYPE"]) {
      $changed = true;
    }
    if ($newColumnDef["COLLATION_NAME"] && $newColumnDef["COLLATION_NAME"] != $oldColumnDef["COLLATION_NAME"]) {
      $changed = true;
    }
    if ($newColumnDef["IS_NULLABLE"] != $oldColumnDef["IS_NULLABLE"]) {
      $changed = true;
    }
    if ($newColumnDef["COLUMN_DEFAULT"] != $oldColumnDef["COLUMN_DEFAULT"]) {
      $changed = true;
    }
    if ($newColumnDef["EXTRA"] != $oldColumnDef["EXTRA"]) {
      $changed = true;
    }

    // create change statement
    if ($changed) {
      $stmt = "ALTER TABLE " . $this->quoteName($oldColumnDef["TABLE_NAME"]) .
              " CHANGE " . $this->quoteName($oldColumnDef["COLUMN_NAME"]) .
              " " . $this->columnDefStmt($newColumnDef);
    }

    return $stmt;
  }  // eo update table

















}  // eo mysql struct class






?>
