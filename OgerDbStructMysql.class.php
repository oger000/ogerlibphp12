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
  public function checkDriverCompat($driverName) {
    if ($driverName != "mysql") {
      throw new Exception ("Driver '$driverName' not compatible. 'mysql' expected.");
    }
  }  // eo check driver compat


  /**
  * Get database schema structure for a mysql database.
  * @see OgerDbStruct::getDbSchemaStruct().
  * @throw Throws an exception if more than one schema is found for the given database name
  *        or no schema is found.
  */
  public function getDbSchemaStruct() {

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
      throw new Exception("More than one schema found for database name {$this->dbName}.");
    }

    if (count($schemaRecords) < 1) {
      throw new Exception("No schema found for database name {$this->dbName}.");
    }

    return $schemaRecords[0];
  }  // eo get schema struct


  /**
  * Get table names.
  * @see OgerDbStruct::getTableNames().
  */
  public function getTableNames($opts) {

    $stmt = "
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName
        ";
    if ($opts["tablesWhere"]) {
      $stmt .= " AND {$opts["tablesWhere"]}";
    }
    $pstmt = $this->conn->prepare($stmt);
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    $tableNames = array();
    foreach ($tableRecords as $tableRecord) {
      $tableNames[strtolower($tableRecord["TABLE_NAME"])] = $tableRecord["TABLE_NAME"];
    }

    return $tableNames;
  }  // eo get table names


  /**
  * Get table structure.
  * @see OgerDbStruct::getTableStruct().
  */
  public function getTableStruct($tableName) {

    $struct = array();

    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
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
    // get foreign keys references and columns

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

      // referenced columns
      $fkColumnKey = strtolower($fkRecord["COLUMN_NAME"]);
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_COLUMNS__"][$fkColumnKey] = $fkRecord;

    }  // eo foreign key columns loop


    // ---------------
    // get foreign keys constraint rules

    // unused columns: UNIQUE_CONSTRAINT_CATALOG, UNIQUE_CONSTRAINT_SCHEMA, UNIQUE_CONSTRAINT_NAME

    $pstmt = $this->conn->prepare("
        SELECT CONSTRAINT_SCHEMA, TABLE_NAME, CONSTRAINT_NAME,
               MATCH_OPTION, UPDATE_RULE, DELETE_RULE, REFERENCED_TABLE_NAME
          FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_CATALOG=:catalogName AND
                CONSTRAINT_SCHEMA=:dbName AND
                TABLE_NAME=:tableName
          ORDER BY TABLE_NAME, CONSTRAINT_NAME
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
    $fkRulesRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ($fkRulesRecords as $fkRulesRecord) {

      $fkName = $fkRulesRecord["CONSTRAINT_NAME"];
      $fkKey = strtolower($fkName);

      // complete the meta info
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["MATCH_OPTION"] = $fkRulesRecord["MATCH_OPTION"];
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["UPDATE_RULE"] = $fkRulesRecord["UPDATE_RULE"];
      $struct["__FOREIGN_KEYS__"][$fkKey]["__FOREIGN_KEY_META__"]["DELETE_RULE"] = $fkRulesRecord["DELETE_RULE"];

    }  // eo foreign key columns loop

    return $struct;
  }  // eo get table struc


  /**
  * Create an add table statement.
  * @see OgerDbStruct::tableDefCreateStmt() and
  *      OgerDbStruct::addTable() for option description.
  */
  public function tableDefCreateStmt($tableStruct, $opts) {

    $tableMeta = $tableStruct["__TABLE_META__"];
    $tableName = $this->quoteName($tableMeta["TABLE_NAME"]);
    $stmt = "CREATE TABLE $tableName (\n  ";

    // force column order
    $this->orderTableStructColumns($tableStruct["__COLUMNS__"]);

    $delim = "";
    foreach ($tableStruct["__COLUMNS__"] as $columnStruct) {
      $stmt .= $delim . $this->columnDefStmt($columnStruct);
      $delim = ",\n  ";
    }  // eo column loop

    // indices
    if (!$opts["noIndices"]) {
      if ($tableStruct["__INDICES__"]) {
        foreach ($tableStruct["__INDICES__"] as $indexKey => $indexStruct) {
          $stmt .= $delim . $this->indexDefStmt($indexStruct);
        }
      }  // eo index loop
    }  // eo include indices

    // foreign keys
    if (!$opts["noForeignKeys"]) {
      if ($tableStruct["__FOREIGN_KEYS__"]) {
        foreach ($tableStruct["__FOREIGN_KEYS__"] as $fkKey => $fkStruct) {
          $stmt .= $delim . $this->foreignKeyDefStmt($fkStruct);
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
  * @see OgerDbStruct::orderTableStructColumns().
  */
  public function orderTableStructColumns(&$columns){

    $tmpCols = array();

    // preserve references
    foreach ($columns as $columnKey => &$columnStruct) {
      $tmpCols[$columnStruct["ORDINAL_POSITION"] * 1] = &$columnStruct;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnStruct) {
      $columns[strtolower($columnStruct["COLUMN_NAME"])] = &$columnStruct;
    }

    // return per value
    return $columns;
  }  // eo order table columns


  /**
  * Create a column definition statement.
  * @see OgerDbStruct::columnDefStmt().
  */
  public function columnDefStmt($columnStruct, $opts = array()) {

    $stmt = $this->quoteName($columnStruct["COLUMN_NAME"]) .
            " " . $columnStruct["COLUMN_TYPE"] .
            ($columnStruct["COLLATION_NAME"] ? " COLLATE {$columnStruct["COLLATION_NAME"]}" : "") .
            ($columnStruct["IS_NULLABLE"] == "NO" ? " NOT NULL" : "") .
            ($columnStruct["EXTRA"] ? " {$columnStruct["EXTRA"]}" : "");

    // create column default
    if (!is_null($columnStruct["COLUMN_DEFAULT"]) || $columnStruct["IS_NULLABLE"] == "YES") {

      $default = $columnStruct['COLUMN_DEFAULT'];
      if (is_null($default)) {
        $default = "NULL";
      }
      elseif ($default == "CURRENT_TIMESTAMP") {
        // nothing to do
      }
      else {
        // quote default value
        // FIXME quote '
        $default = "'$default'";
      }

      $stmt .= " DEFAULT $default";
    }  // eo default

    // if afterColumnName is null we do nothing (that means the field is appended without position)
    if (!is_null($opts["afterColumnName"])) {
      // empty values result in inserting on first position
      if (!$opts["afterColumnName"]) {
        $stmt .= " FIRST";
      }
      else {
        $afterColumnName = $this->quoteName($opts["afterColumnName"]);
        $stmt .= " AFTER {$afterColumnName}";
      }
    }

    return $stmt;
  }  // eo column def stmt


  /**
  * Create a table index statement.
  * @see OgerDbStruct::indexDefStmt().
  */
  public function indexDefStmt($indexStruct) {

    $indexName = " " . $this->quoteName($indexStruct["__INDEX_META__"]["INDEX_NAME"]);

    // the primary key has no separate name
    if ($indexStruct["__INDEX_META__"]["INDEX_KEY_TYPE"] == "PRIMARY") {
      $indexName = "";
    }

    $indexKeyType = $indexStruct["__INDEX_META__"]["INDEX_KEY_TYPE"];
    if ($indexKeyType) {
      $indexKeyType .= " ";
    }

    $stmt .= "{$indexKeyType}KEY{$indexName}";

    // force order of columns and extract names
    $this->orderIndexColumns($indexStruct["__INDEX_COLUMNS__"]);
    $colNames = array();
    foreach ($indexStruct["__INDEX_COLUMNS__"] as $indexColumnStruct) {
      $colNames[] = $this->quoteName($indexColumnStruct["COLUMN_NAME"]);
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
    foreach ($columns as $columnKey => &$columnStruct) {
      $tmpCols[$columnStruct["ORDINAL_POSITION"] * 1] = &$columnStruct;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnStruct) {
      $columns[strtolower($columnStruct["COLUMN_NAME"])] = &$columnStruct;
    }

    // return per value
    return $columns;
  }  // eo order index columns


  /**
  * Create an add column statement.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function columnDefAddStmt($columnStruct, $opts) {

    $stmt = "ALTER TABLE " .
            $this->quoteName($columnStruct["TABLE_NAME"]) .
            " ADD COLUMN " .
            $this->columnDefStmt($columnStruct, $opts);

    return $stmt;
  }  // eo add column


  /**
  * Create an alter table statement for table defaults.
  * @see OgerDbStruct::columnDefAddStmt().
  */
  public function tableDefUpdateStmt($newTableStruct, $oldTableStruct) {

    $newTableMeta = $newTableStruct["__TABLE_META__"];
    $oldTableMeta = $oldTableStruct["__TABLE_META__"];

    if ($newTableMeta["TABLE_COLLATION"] != $oldTableMeta["TABLE_COLLATION"]) {
      $changed = true;
    }
    if ($newTableMeta["ENGINE"] != $oldTableMeta["ENGINE"]) {
      $changed = true;
    }

    if ($changed) {
      $stmt = "ALTER TABLE " .
              $this->quoteName($oldTableMeta["TABLE_NAME"]) .
              " ENGINE " . $newTableMeta["ENGINE"];
              " COLLATE " . $newTableMeta["TABLE_COLLATION"];
    }

    return $stmt;
  }  // eo update table


  /**
  * Create an alter table statement to alter a column.
  * @see OgerDbStruct::columnDefUpdateStmt().
  */
  public function columnDefUpdateStmt($newColumnStruct, $oldColumnStruct) {

    if ($newColumnStruct["COLUMN_TYPE"] != $oldColumnStruct["COLUMN_TYPE"]) {
      $changed = true;
    }
    if ($newColumnStruct["COLLATION_NAME"] && $newColumnStruct["COLLATION_NAME"] != $oldColumnStruct["COLLATION_NAME"]) {
      $changed = true;
    }
    if ($newColumnStruct["IS_NULLABLE"] != $oldColumnStruct["IS_NULLABLE"]) {
      $changed = true;
    }
    if ($newColumnStruct["COLUMN_DEFAULT"] != $oldColumnStruct["COLUMN_DEFAULT"]) {
      $changed = true;
    }
    if ($newColumnStruct["EXTRA"] != $oldColumnStruct["EXTRA"]) {
      $changed = true;
    }

    // create change statement
    // TODO: include AFTER | FIRST position here?
    if ($changed) {
      $stmt = "ALTER TABLE " . $this->quoteName($oldColumnStruct["TABLE_NAME"]) .
              " CHANGE COLUMN " . $this->quoteName($oldColumnStruct["COLUMN_NAME"]) .
              " " . $this->columnDefStmt($newColumnStruct);
    }

    return $stmt;
  }  // eo update column


  /**
  * Create an alter table statement to alter a index.
  * @see OgerDbStruct::indexDefUpdateStmt().
  */
  public function indexDefUpdateStmt($newIndexStruct, $oldIndexStruct) {

    $newIndexSql = $this->indexDefStmt($newIndexStruct);
    $oldIndexSql = $this->indexDefStmt($oldIndexStruct);

    $tableName = $this->quoteName($oldIndexStruct["__INDEX_META__"]["TABLE_NAME"]);
    $indexName = $this->quoteName($oldIndexStruct["__INDEX_META__"]["INDEX_NAME"]);

    // create change statement
    if ($newIndexSql != $oldIndexStruct) {
      $stmt = "ALTER TABLE $tableName DROP INDEX $indexName;" .
              "ALTER TABLE $tableName ADD $newIndexSql";
    }

    return $stmt;
  }  // eo update index


  /**
  * Create an alter table statement to alter a foreign key.
  * @see OgerDbStruct::foreignKeyDefUpdateStmt().
  */
  public function foreignKeyDefUpdateStmt($newFkStruct, $oldFkStruct) {

    $newFkSql = $this->foreignKeyDefStmt($newFkStruct);
    $oldFkSql = $this->foreignKeyDefStmt($oldFkStruct);

    $tableName = $this->quoteName($oldIndexStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
    $fkName = $this->quoteName($oldIndexStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

    // create change statement
    if ($newFkSql != $oldFkStruct) {
      $stmt = "ALTER TABLE $tableName DROP FOREIGN KEY $fkName;" .
              "ALTER TABLE $tableName ADD $newFkSql";
    }

    return $stmt;
  }  // eo update index


  /**
  * Create a table foreign key statement.
  * @see OgerDbStruct::foreignKeyDefStmt().
  */
  public function foreignKeyDefStmt($fkStruct) {

    $fkName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

    $stmt = "CONSTRAINT $fkName";

    // force order of columns and extract names
    // we assume that the column order in the reference is the same as in the foreign key
    $this->orderForeignKeyColumns($fkStruct["__FOREIGN_KEY_COLUMNS__"]);
    $colNames = array();
    $colNamesRef = array();
    foreach ($fkStruct["__FOREIGN_KEY_COLUMNS__"] as $fkColumnStruct) {
      $colNames[] = $this->quoteName($fkColumnStruct["COLUMN_NAME"]);
      $colNamesRef[] = $this->quoteName($fkColumnStruct["REFERENCED_COLUMN_NAME"]);
    }

    // put fields and reference to statement
    $fkMeta = $fkStruct["__FOREIGN_KEY_META__"];
    $stmt .= " FOREIGN KEY (" . implode(", ", $colNames) . ")";
    $refTable = $this->quoteName($fkMeta["REFERENCED_TABLE_NAME"]);
    $stmt .= " REFERENCES $refTable (" . implode(", ", $colNamesRef) . ")" .
             " ON DELETE {$fkMeta['DELETE_RULE']} ON UPDATE {$fkMeta['UPDATE_RULE']}";

    return $stmt;
  }  // eo foreign key def


  /**
  * Force order of foreign key columns.
  * @see OgerDbStruct::orderForeignKeyColumns().
  */
  public function orderForeignKeyColumns(&$columns){

    $tmpCols = array();

    // preserve references
    foreach ($columns as $columnKey => &$columnStruct) {
      $tmpCols[$columnStruct["ORDINAL_POSITION"] * 1] = &$columnStruct;
    }
    ksort($tmpCols);

    // assign back to original array
    $columns = array();
    foreach ($tmpCols as &$columnStruct) {
      $columns[strtolower($columnStruct["COLUMN_NAME"])] = &$columnStruct;
    }

    // return per value
    return $columns;
  }  // eo order foreign key columns


  /**
  * Reorder table columns.
  * @see OgerDbStruct::reorderTableColumns().
  */
  public function reorderTableColumns($newTableStruct, $oldTableStruct){

    $this->orderTableStructColumns($newTableStruct["__COLUMNS__"]);
    $newColNames = array();
    foreach ($newTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $newColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }

    $this->orderTableStructColumns($oldTableStruct["__COLUMNS__"]);
    $oldColNames = array();
    foreach ($oldTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $oldColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }


    // remove all column names that are not in both tables
    // because they do not affect the reordering

    foreach ($newColNames as $colKey => $colStruct) {
      if (!$oldColNames[$colKey]) {
        unset($newColNames[$colKey]);
      }
    }

    foreach ($oldColNames as $colKey => $colStruct) {
      if (!$newColNames[$colKey]) {
        unset($oldColNames[$colKey]);
      }
    }

    $tableName = $this->quoteName($newTableStruct["__TABLE_META__"]["TABLE_NAME"]);

    // use old column structure because we dont want to change the column but only the order
    $afterColumn = "";
    foreach ($newColNames as $columnName) {

      $columnDef = $this->columnDefStmt($oldTableStruct["__COLUMNS__"][$columnName], array("afterColumnName" => $afterColumn));
      $stmt = "ALTER TABLE $tableName CHANGE COLUMN $colName $columnDef";
      $afterColumn = $colName;

      $this->executeStmt($stmt);

    }  // eo common column loop

  }  // eo order table columns









}  // eo mysql struct class






?>
