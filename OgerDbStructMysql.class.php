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

  private $defCatalogName = "def";


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

    $this->defCatalogName = "def";

    $pstmt = $this->conn->prepare("
        SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
          FROM INFORMATION_SCHEMA.SCHEMATA
          WHERE CATALOG_NAME=:catalogName AND
                SCHEMA_NAME=:dbName
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName));
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    if (count($schemaRecords) > 1) {
      throw new Exception("More than one schema found for name {$this->dbName}.");
    }

    // return silently if no schema found
    if (count($schemaRecords) < 1) {
      return $struct;
    }

    $struct["__SCHEMA_META__"] = reset($schemaRecords);


    // -----------------------
    // get tables info

    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ($tableRecords as $tableRecord) {

      $tableName = $tableRecord["TABLE_NAME"];
      $tableKey = strtolower($tableName);

      $struct["__TABLE_NAMES__"][$tableKey] = $tableName;
      $struct["__TABLES__"][$tableKey] = $this->getTableStruct($tableName);

    }  // eo table loop

    return $struct;
  }  // eo get db structure


  /**
  * Get table structure.
  * @see OgerDbStruct::getTableStruct().
  */
  public function getTableStruct($tableName) {

    $struct = array();

    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND
                TABLES.TABLE_NAME=:tableName
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    if (count($tableRecords) > 1) {
      throw new Exception("More than one table schema found for table name {$tableName}.");
    }

    if (count($tableRecords) < 1) {
      return $struct;
    }


    $tableRecord = reset($tableRecords);
    $tableKey = strtolower($tableName);

    $struct["__TABLE_META__"] = $tableRecord;


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
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND
                TABLE_NAME=:tableName
          ORDER BY ORDINAL_POSITION
        ");

    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
    $columnRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ($columnRecords as $columnRecord) {

      $columnName = $columnRecord["COLUMN_NAME"];
      $columnKey = strtolower($columnName);

      $struct["__COLUMN_NAMES__"][$columnKey] = $columnName;
      $struct["__COLUMNS__"][$columnKey] = $columnRecord;

    }  // eo column loop


    // ---------------
    // get key info

    // the KEY_COLUMN_USAGE misses info like unique, nullable, etc so wie use STATISTICS for now
    $pstmt = $this->conn->prepare("
        SELECT INDEX_NAME, SEQ_IN_INDEX AS ORDINAL_POSITION, COLUMN_NAME,	NON_UNIQUE
          FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND
                TABLE_NAME=:tableName
          ORDER BY INDEX_NAME, ORDINAL_POSITION
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
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

      $struct["__INDEX_NAMES__"][$indexKey] = $indexName;

      // the meta info is taken from the last column info which overwrites the prevous meta info
      $struct["__INDICES__"][$indexKey]["__INDEX_META__"]["INDEX_NAME"] = $indexName;
      $struct["__INDICES__"][$indexKey]["__INDEX_META__"]["INDEX_KEY_TYPE"] = $indexType;
      $struct["__INDICES__"][$indexKey]["__INDEX_META__"]["TABLE_NAME"] = $indexRecord["TABLE_NAME"];

      // index columns
      $indexColumnKey = strtolower($indexRecord["COLUMN_NAME"]);
      $struct["__INDICES__"][$indexKey]["__INDEX_COLUMNS__"][$indexColumnKey] = $indexRecord;

    }  // eo index loop


    // ---------------
    // get foreign keys info

    // the TABLE_CONSTRAINTS contains only constraint names,
    // so we use KEY_COLUMN_USAGE this time
    $pstmt = $this->conn->prepare("
        SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, POSITION_IN_UNIQUE_CONSTRAINT,
               REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND
                TABLE_NAME=:tableName AND
                REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
    $fkRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ($fkRecords as $fkRecord) {

      $fkName = $fkRecord["CONSTRAINT_NAME"];
      $fkKey = strtolower($fkName);


      $struct["__FOREIGN_KEY_NAMES__"][$fkKey] = $fkName;

      // the meta info is taken from the last entry info which overwrites the prevous meta info
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"] = $fkName;
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["TABLE_NAME"] = $fkRecord["TABLE_NAME"];
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["REFERENCED_TABLE_SCHEMA"] = $fkRecord["REFERENCED_TABLE_SCHEMA"];
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["REFERENCED_TABLE_NAME"] = $fkRecord["REFERENCED_TABLE_NAME"];

      /* see <http://stackoverflow.com/questions/953035/multiple-column-foreign-key-in-mysql>
       * CREATE TABLE MyReferencingTable AS (
           [COLUMN DEFINITIONS]
           refcol1 INT NOT NULL,
           rofcol2 INT NOT NULL,
           CONSTRAINT fk_mrt_ot FOREIGN KEY (refcol1, refcol2)
                                REFERENCES OtherTable(col1, col2)
          ) ENGINE=InnoDB;

          MySQL requires foreign keys to be indexed, hence the index on the referencing columns
          Use of the constraint syntax enables you to name a constraint, making it easier to alter and drop at a later time if needed.
          InnoDB enforces foreign keys, MyISAM does not. (The syntax is parsed but ignored)
      */

      /*
       * ALTER TABLE `testtab4` ADD FOREIGN KEY ( `id1` ) REFERENCES `test`.`testtab1` (
          `id1`
          ) ON DELETE RESTRICT ON UPDATE RESTRICT ;
      */

      // foreign keys
      // I could not find anything that could I use as uniqe key, so add to array without key
      $fkColumnKey = strtolower($fkRecord["COLUMN_NAME"]);
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_COLUMNS__"][$fkColumnKey] = $fkRecord;

    }  // eo constraint loop

    return $struct;
  }  // eo get table struc


  /**
  * Create an add table statement.
  * @see OgerDbStruct::tableDefCreateStmt() and
  *      OgerDbStruct::addTable() for option description.
  */
  public function tableDefCreateStmt($tableDef, $opts) {

    $tableMeta = $tableDef["__TABLE_META__"];
    $tableName = $this->quoteName($tableMeta["TABLE_NAME"]);
    $stmt = "CREATE TABLE $tableName (\n  ";

    // force column order
    $this->orderTableColumns($tableDef["__COLUMNS__"]);

    $delim = "";
    foreach ($tableDef["__COLUMNS__"] as $columnDef) {
      $stmt .= $delim . $this->columnDefStmt($columnDef);
      $delim = ",\n  ";
    }  // eo column loop

    // indices
    if (!$opts["noIndices"]) {
      if ($tableDef["__INDICES__"]) {
        foreach ($tableDef["__INDICES__"] as $indexKey => $indexDef) {
          $stmt .= $delim . $this->indexDefStmt($indexDef);
        }
      }  // eo index loop
    }  // eo include indices

    // foreign keys
    if (!$opts["noForeignKeys"]) {
      if ($tableDef["__FOREIGN_KEYS__"]) {
        foreach ($tableDef["__FOREIGN_KEYS__"] as $fkKey => $fkDef) {
          $stmt .= $delim . $this->foreignKeyDefStmt($fkDef);
        }
      }  // eo constraint loop
    }  // eo include foreign keys

    $stmt .= "\n)";

    // table defaults
    // Note on charset:
    // Looks like mysql derives the charset from the collation
    // via the INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY table
    // and does this internally automatically if a collation is given.
    // So we depend on this - provide the collation and omit the charset.
    $stmt .= " DEFAULT" .
          " ENGINE={$tableMeta['ENGINE']}" .
          // " CHARSET={$tableMeta['']}" .  // see note above
          " COLLATE={$tableMeta['TABLE_COLLATION']}";

    return $stmt;
  }  // eo add table


  /**
  * Force order of table columns.
  * @see OgerDbStruct::orderTableColumns().
  */
  public function orderTableColumns(&$columns){

    $tmpCols = array();

    // preserve references
    foreach ($columns as $columnKey => &$columnDef) {
      $tmpCols[$columnDef["ORDINAL_POSITION"] * 1] = &$columnDef;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnDef) {
      $columns[strtolower($columnDef["COLUMN_NAME"])] = &$columnDef;
    }

    // return per value
    return $columns;
  }  // eo order table columns


  /**
  * Create a column definition statement.
  * @see OgerDbStruct::columnDefStmt().
  */
  public function columnDefStmt($columnDef, $opts = array()) {

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

    // force order of columns and extract names
    $this->orderIndexColumns($indexDef["__INDEX_COLUMNS__"]);
    $colNames = array();
    foreach ($indexDef["__INDEX_COLUMNS__"] as $indexColumnDef) {
      $colNames[] = $this->quoteName($indexColumnDef["COLUMN_NAME"]);
    }

    // put fields to statement
    $stmt .= " (" . implode(", ", $colNames) . ")";

    return $stmt;
  }  // eo index def


  /**
  * Force order of index columns.
  * @see OgerDbStruct::orderIndexColumns().
  */
  public function orderIndexColumns(&$columns){

    $tmpCols = array();

    // preserve references
    foreach ($columns as $columnKey => &$columnDef) {
      $tmpCols[$columnDef["ORDINAL_POSITION"] * 1] = &$columnDef;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnDef) {
      $columns[strtolower($columnDef["COLUMN_NAME"])] = &$columnDef;
    }

    // return per value
    return $columns;
  }  // eo order index columns


  /**
  * Create an add column statement.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function columnDefAddStmt($columnDef, $opts) {

    $stmt = "ALTER TABLE " .
            $this->quoteName($columnDef["TABLE_NAME"]) .
            " ADD COLUMN " .
            $this->columnDefStmt($columnDef);

    // if afterColumnName is empty we do nothing (that means the field is appended without position)
    if ($opts["afterColumnName"]) {
      // negative numeric values result in inserting on first position
      if ($opts["afterColumnName"] < 0) {
        $stmt .= " FIRST";
      }
      else {
        $stmt .= " AFTER {$opts["afterColumnName"]}";
      }
    }

    return $stmt;
  }  // eo add column


  /**
  * Create an alter table statement for table defaults.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function tableDefUpdateStmt($newTableDef, $oldTableDef) {

    $newTableMeta = $newTableDef["__TABLE_META__"];
    $oldTableMeta = $oldTableDef["__TABLE_META__"];

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
  public function columnDefUpdateStmt($newColumnDef, $oldColumnDef) {

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




  /**
  * Create a table foreign key statement.
  * @see OgerDbStruct::foreignKeyDefStmt().
  */
  public function foreignKeyDefStmt($fkDef) {

    $fkName = $this->quoteName($fkDef["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

    $stmt = "CONSTRAINT $fkName";

    // force order of columns and extract names
    // we assume that the column order in the reference is the same as in the foreign key
    $this->orderForeignKeyColumns($fkDef["__FOREIGN_KEY_COLUMNS__"]);
    $colNames = array();
    $colNamesRef = array();
    foreach ($fkDef["__FOREIGN_KEY_COLUMNS__"] as $fkColumnDef) {
      $colNames[] = $this->quoteName($fkColumnDef["COLUMN_NAME"]);
      $colNamesRef[] = $this->quoteName($fkColumnDef["REFERENCED_COLUMN_NAME"]);
    }

    // put fields and reference to statement
    $stmt .= " FOREIGN KEY (" . implode(", ", $colNames) . ")";
    $refTable = $this->quoteName($fkDef["__FOREIGN_KEY_META__"]["REFERENCED_TABLE_NAME"]);
    $stmt .= " REFERENCES $refTable (" . implode(", ", $colNamesRef) . ")";

    return $stmt;
  }  // eo foreign key def


  /**
  * Force order of foreign key columns.
  * @see OgerDbStruct::orderForeignKeyColumns().
  */
  public function orderForeignKeyColumns(&$columns){

    $tmpCols = array();

    // preserve references
    foreach ($columns as $columnKey => &$columnDef) {
      $tmpCols[$columnDef["ORDINAL_POSITION"] * 1] = &$columnDef;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnDef) {
      $columns[strtolower($columnDef["COLUMN_NAME"])] = &$columnDef;
    }

    // return per value
    return $columns;
  }  // eo order foreign key columns











}  // eo mysql struct class






?>
