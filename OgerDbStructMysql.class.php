<?php
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Handle database structure for mysql databases.
* @see class OgerDbStruct.<br>
* This class works in a "pseudo-case-insensitive" way.
* Names (tablenames, columnnames, indexnames, ...) are searched
* case insensitive, so it is not possible to have the same name with different
* lettercase twice in the same area. It is no problem to have the same
* name in different areas. Though the names are searched in a case independent way
* they are stored case sensitive and can be used this way if necessary.<br>
* The structure does not contain privileges.<br>
* We handle Collations (which in turn modifies charset).<br>
*/
class OgerDbStructMysql extends OgerDbStruct {


  protected $quoteNamBegin = '`';
  protected $quoteNamEnd = '`';

  private $defCatalogName = "def";
  private $sqlServerOs;

  private $refDbStruct;
  private $curDbStruct;

  private $refLowerTableName;
  private $refLowerFs;
  private $curLowerTableName;
  private $curLowerFs;



  /**
   * @see OgerDbStruct::__construct().
   * @throw Throws an exception if the driver name does not fit.
   */
  public function __construct($conn, $dbName) {

    parent::__construct($conn, $dbName);

    if ($this->driverName != "mysql") {
      throw new Exception("Invalid driver {$this->driverName} for " . __CLASS__);
    }

    // get platform - needed for case sensitive check.
    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE 'version_compile_os'");
    $pstmt->execute();
    $records = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    $this->sqlServerOs = $records[0];

  }  // eo constructor


  /**
  * Get the current database structure.
  * @see OgerDbStruct::getDbStruct().
  */
  public function getDbStruct($opts = array()) {

    // get structure head
    $struct = $this->createStructHead();

    // get schema structure
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
    $struct["__SCHEMA_META__"] = $schemaRecords[0];

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%case%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    $struct["__SCHEMA_META__"] = array_merge($schemaRecords, $struct["__SCHEMA_META__"]);

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%compile%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    $struct["__SCHEMA_META__"] = array_merge($schemaRecords, $struct["__SCHEMA_META__"]);

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%version%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    $struct["__SCHEMA_META__"] = array_merge($schemaRecords, $struct["__SCHEMA_META__"]);


    // get table structure
    $stmt = "
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName
        ";
    if ($opts["whereTables"]) {
      $stmt .= " AND {$opts["whereTables"]}";
    }
    $pstmt = $this->conn->prepare($stmt);
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    $tableNames = array();
    foreach ($tableRecords as $tableRecord) {
      $tableName = $tableRecord["TABLE_NAME"];
      $tableKey = strtolower($tableName);
      $struct["__TABLES__"][$tableKey] = $this->getTableStruct($tableName);
    }  // eo table loop


    return $struct;
  }  // eo get db struct


  /**
  * Get the database structure for one table.
  * @param $tableName Name of the table for which we want to get the structure.
  * @return Array with table structure.
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
   * Precheck before process changes.
   * @param $refStruct Array with the reference database structure.
   * @param $curStruct Optional array with the current database structure.
   *        If not present it is located from the database connection.
  */
  private function preProcessCheck($refDbStruct, $curDbStruct = null) {
    $driverName = $refDbStruct["__DBSTRUCT_META__"]["__DRIVER_NAME__"];
    if ($driverName != "mysql") {
      throw new Exception ("Driver '$driverName' not compatible. Only driver 'mysql' supported.");
    }

    if ($refDbStruct) {
      $this->refDbStruct = $refDbStruct;
      $this->refLowerTableName = $refDbStruct["__SCHEMA_META__"]["lower_case_table_names"]
    }
    if (!$this->refDbStruct) {
      throw new Exception ("Reference database structure required.");
    }

    if (!$this->curDbStruct) {
      $this->curDbStruct = $this->getDbStruct();
      $this->curLowerTableName = $curDbStruct["__SCHEMA_META__"]["lower_case_table_names"]
    }

    // do not overwrite case sensitive database systems with lowercase converted reference structures
    // TODO provide forceLowerCase and ignoreLowerCase options ???
    if ($this->curLowerTableName != 1 && $this->refLowerTableName == 1) {
      throw new Exception ("It is not allowed to apply lower case forced (table) reference structures" .
                           " to a case sensitive database system.");
    }

  }  // eo prechecks



  /**
  * Add missing tables and columns to the database.
  * @param $refStruct Array with the reference database structure.
  * @param $opts Optional options array.<br>
  * @see See OgerDbStruct::addDbStruct().
  */
  public function addDbStruct($refDbStruct = null, $opts = array()) {

    $this->preProcessCheck($refStruct);

    foreach ($refStruct["__TABLES__"] as $refTableKey => $refTableStruct) {

      $refTableName = $refTableStruct["__TABLE_META__"]["TABLE_NAME"];
      $curTableStruct = $curStruct["__TABLES__"][$refTableKey];
      if (!$curTableStruct) {
        $this->addTable($refTableStruct, array("noForeignKeys" => true));
      }
      else {
        $this->updateTable($refTableStruct, array("noRefresh" => true, "noForeignKeys" => true));
      }
    }  // eo table loop


    // add foreign keys after all tables, columns and indices has been created
    if (!$opts["noForeignKeys"]) {
      foreach ($refStruct["__TABLES__"] as $refTableKey => $refTableStruct) {

        $refTableName = $refTableStruct["__TABLE_META__"]["TABLE_NAME"];

        // foreign keys
        if ($refTableStruct["__FOREIGN_KEYS__"]) {
          foreach ($refTableStruct["__FOREIGN_KEYS__"] as $refFkKey => $refFkStruct) {
            if (!$curStruct["__TABLES__"][$refTableKey]["__FOREIGN_KEYS__"][$refFkKey]) {
              $this->addTableForeignKey($refFkStruct);
            }
          }
        }  // eo constraint loop
      }  // eo table loop for foreign keys
    }  // eo include foreign keys

  }  // eo add db struc


  /**
  * Add a table to the current database structure.
  * @param $tableStruct Array with the table structure.
  * @param $opts Optional option array. Key is option.
  *        Valid options:<br>
  *        - noIndices<br>
  *        - noForeignKeys
  */
  public function addTable($tableStruct, $opts = array()) {

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


    // execute the statement
    $this->executeStmt($stmt);
  }  // eo add table


  /**
  * Update an existing table and add missing parts if requested.
  * @param $refTableStruct Array with the reference table structure.
  * @param $opts Optional options array where the key is the option name.<br>
  *        Valid options are:<br>
  *        - noRefresh<br>
  *        - noIndices<br>
  *        - noForeignKeys<br>
  */
  public function updateTable($refTableStruct = null, $opts = array()) {

    $this->preProcessCheck($refTableStruct);

    if (!$opts["noRefresh"]) {
      $this->refreshTable($refTableStruct, $curTableStruct);
    }

    // add columns
    $this->orderTableStructColumns($refTableStruct["__COLUMNS__"]);
    $afterColumnName = "";
    foreach ($refTableStruct["__COLUMNS__"] as $refColumnKey => $refColumnStruct) {
      if (!$curTableStruct["__COLUMNS__"][$refColumnKey]) {
        $this->addTableColumn($refColumnStruct, array("afterColumnName" => $afterColumnName));
      }
      // this column exists (old or new created) so the next missing column will be added after this
      $afterColumnName = $refColumnStruct["COLUMN_NAME"];
    }  // eo column loop

    if (!$opts["noIndices"]) {
      foreach ($refTableStruct["__INDICES__"] as $refIndexKey => $refIndexStruct) {
        if (!$curTableStruct["__INDICES__"][$refIndexKey]) {
          $this->addTableIndex($refIndexStruct);
        }
      }
    }  // eo index

    if (!$opts["noForeignKeys"]) {
      foreach ($refTableStruct["__FOREIGN_KEYS__"] as $refFkKey => $refFkStruct) {
        if (!$curTableStruct["__FOREIGN_KEYS__"][$refFkKey]) {
          $this->addTableForeignKey($refFkStruct);
        }
      }
    }  // eo foreign keys

  }  // eo update existing table


  /**
  * Refresh an existing table.
  * @param $refTableStruct Array with the reference table structure.
  * @param $opts Optional options array where the key is the option name.<br>
  */
  public function refreshTable($refTableStruct = null, $opts = array()) {

    $refTableMeta = $refTableStruct["__TABLE_META__"];
    $tableName = $refTableMeta["TABLE_NAME"];
    $tableKey = strtolower($tableName);

    $curTableStruct = $this->curDbStruct["__TABLES__"][$tableKey];
    $curTableMeta = $this->curDbStruct["__TABLE_META__"];

    // table name - check for different case

TODO: CONTINUE HERE

    $changed = false;
    if ($refTableMeta["TABLE_NAME"] != $curTableMeta["TABLE_NAME"]) {
      $changed = true;
      // to nothing if only differ in case on windows
      if (strtolower($refTableMeta["TABLE_NAME"]) == strtolower($curTableMeta["TABLE_NAME"]) &&
          stripos("win", $this->sqlServerOs) !== false ) {
        $changed = false;
      }
    }
    if ($changed) {
      $refTableName = $this->quoteName($refTableMeta["TABLE_NAME"]);
      $curTableName = $this->quoteName($curTableMeta["TABLE_NAME"]);
      $stmt = "RENAME TABLE {$refTableName} TO {$curTableName}";
      $this->executeStmt($stmt);
    }

    // table defaults
    $changed = false;
    if ($refTableMeta["TABLE_COLLATION"] != $curTableMeta["TABLE_COLLATION"]) {
      $changed = true;
    }
    if ($refTableMeta["ENGINE"] != $curTableMeta["ENGINE"]) {
      $changed = true;
    }

    if ($changed) {
      $stmt .= "ALTER TABLE " .
               $this->quoteName($curTableMeta["TABLE_NAME"]) .
               " ENGINE " . $refTableMeta["ENGINE"];
               " COLLATE " . $refTableMeta["TABLE_COLLATION"];
    }





    $stmt = $this->tableDefUpdateStmt($refTableStruct, $curTableStruct);
    $this->executeStmt($stmt);

    // refresh existing columns
    foreach ($refTableStruct["__COLUMNS__"] as $refColumnKey => $refColumnStruct) {
      $curColumnStruct = $curTableStruct["__COLUMNS__"][$refColumnKey];
      if ($curColumnStruct) {
        $this->refreshTableColumn($refColumnStruct, $curColumnStruct);
      }
    }

    // refresh existing indices
    foreach ($refTableStruct["__INDICES__"] as $refIndexKey => $refIndexStruct) {
      $curIndexStruct = $curTableStruct["__INDICES__"][$refIndexKey];
      if ($curIndexStruct) {
        $this->refreshTableIndex($refIndexStruct, $curIndexStruct);
      }
    }

    // refresh existing foreign keys
    foreach ($refTableStruct["__FOREIGN_KEYS__"] as $refFkKey => $refFkStruct) {
      $curFkStruct = $curTableStruct["__FOREIGN_KEYS__"][$refFkKey];
      if ($curFkStruct) {
        $this->refreshTableForeignKey($refFkStruct, $curFkStruct);
      }
    }

  }  // eo refresh table

















  /**
  * Create an alter table statement for table defaults.
  * @param $refTableStruct Array with the reference table structure.
  * @param $curTableStruct Array with the current table structure.
  */
  abstract public function tableDefUpdateStmt($refTableStruct, $curTableStruct);









...


  /**
  * Add a column to a table structure.
  * @param $columnStruct Array with the table definition.
  */
  public function addTableColumn($columnStruct, $opts) {
    $stmt = $this->columnDefAddStmt($columnStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add column to table
  /**
  * Add a column to a table structure.
  * @param $columnStruct Array with the table structure.
  */
  public function addTableColumn($columnStruct, $opts) {
    $stmt = $this->columnDefAddStmt($columnStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add column to table






  /**
  * Create a column definition statement.
  * @param $columnStruct  Array with column definition.
  * @param $opts Optional options array. Key is option name.<br>
  *        Valid options are:<br>
  *        - afterColumnName: The column name after which this column should be placed.
  *          Null means append after the last column.
  *          Any other empty value means insert on first position.
  * @return The SQL statement part for a column definition.
  */
  abstract public function columnDefStmt($columnStruct, $opts = array());













---






  /**
  * Force order of table columns.
  * @param columns Array with the column definitions.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered array with the column definitions.
  */
  private function orderTableStructColumns(&$columns){

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
  public function tableDefUpdateStmt($refTableStruct, $curTableStruct) {

    return $stmt;
  }  // eo update table


  /**
  * Create an alter table statement to alter a column.
  * @see OgerDbStruct::columnDefUpdateStmt().
  */
  public function columnDefUpdateStmt($refColumnStruct, $curColumnStruct) {

    if ($refColumnStruct["COLUMN_NAME"] != $curColumnStruct["COLUMN_NAME"]) {
      $changed = true;
    }

    if ($refColumnStruct["COLUMN_TYPE"] != $curColumnStruct["COLUMN_TYPE"]) {
      $changed = true;
    }
    if ($refColumnStruct["COLLATION_NAME"] && $refColumnStruct["COLLATION_NAME"] != $curColumnStruct["COLLATION_NAME"]) {
      $changed = true;
    }
    if ($refColumnStruct["IS_NULLABLE"] != $curColumnStruct["IS_NULLABLE"]) {
      $changed = true;
    }
    if ($refColumnStruct["COLUMN_DEFAULT"] != $curColumnStruct["COLUMN_DEFAULT"]) {
      $changed = true;
    }
    if ($refColumnStruct["EXTRA"] != $curColumnStruct["EXTRA"]) {
      $changed = true;
    }

    // create change statement
    // TODO: include AFTER | FIRST position here?
    if ($changed) {
      $stmt = "ALTER TABLE " . $this->quoteName($curColumnStruct["TABLE_NAME"]) .
              " CHANGE COLUMN " . $this->quoteName($curColumnStruct["COLUMN_NAME"]) .
              " " . $this->quoteName($refColumnStruct["COLUMN_NAME"]) .
              " " . $this->columnDefStmt($refColumnStruct);
    }

    return $stmt;
  }  // eo update column


  /**
  * Create an alter table statement to alter a index.
  * @see OgerDbStruct::indexDefUpdateStmt().
  */
  public function indexDefUpdateStmt($refIndexStruct, $curIndexStruct) {

    $refIndexSql = $this->indexDefStmt($refIndexStruct);
    $curIndexSql = $this->indexDefStmt($curIndexStruct);

    $tableName = $this->quoteName($curIndexStruct["__INDEX_META__"]["TABLE_NAME"]);
    $indexName = $this->quoteName($curIndexStruct["__INDEX_META__"]["INDEX_NAME"]);

    // create change statement
    if ($refIndexSql != $curIndexSql) {
      $this->log(static::LOG_NOTICE, "OLD: $curIndexSql\nNEW: $refIndexSql\n";
      $stmt = "ALTER TABLE $tableName DROP INDEX $indexName;" .
              "ALTER TABLE $tableName ADD $refIndexSql";
    }

    return $stmt;
  }  // eo update index


  /**
  * Create an alter table statement to alter a foreign key.
  * @see OgerDbStruct::foreignKeyDefUpdateStmt().
  */
  public function foreignKeyDefUpdateStmt($refFkStruct, $curFkStruct) {

    $refFkSql = $this->foreignKeyDefStmt($refFkStruct);
    $curFkSql = $this->foreignKeyDefStmt($curFkStruct);

    $tableName = $this->quoteName($curFkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
    $fkName = $this->quoteName($curFkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

    // create change statement
    if ($refFkSql != $curFkSql) {
      $this->log(static::LOG_NOTICE, "OLD: $curFkSql\nNEW: $refFkSql\n";
      $stmt = "ALTER TABLE $tableName DROP FOREIGN KEY $fkName;" .
              "ALTER TABLE $tableName ADD $refFkSql";
    }

    return $stmt;
  }  // eo update foreign key


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
  public function reorderTableColumns($refTableStruct, $curTableStruct){

    $this->orderTableStructColumns($refTableStruct["__COLUMNS__"]);
    $refColNames = array();
    foreach ($refTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $refColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }

    $this->orderTableStructColumns($curTableStruct["__COLUMNS__"]);
    $curColNames = array();
    foreach ($curTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $curColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }


    // remove all column names that are not in both tables
    // because they do not affect the reordering

    foreach ($refColNames as $colKey => $colStruct) {
      if (!$curColNames[$colKey]) {
        unset($refColNames[$colKey]);
      }
    }

    foreach ($curColNames as $colKey => $colStruct) {
      if (!$refColNames[$colKey]) {
        unset($curColNames[$colKey]);
      }
    }

    $tableName = $this->quoteName($refTableStruct["__TABLE_META__"]["TABLE_NAME"]);

    // use current column structure because we dont want to change the column but only the order
    $afterColumn = "";
    foreach ($refColNames as $columnName) {

      $columnDef = $this->columnDefStmt($curTableStruct["__COLUMNS__"][$columnName], array("afterColumnName" => $afterColumn));
      $stmt = "ALTER TABLE $tableName CHANGE COLUMN $colName $columnDef";
      $afterColumn = $colName;

      $this->executeStmt($stmt);

    }  // eo common column loop

  }  // eo order table columns









}  // eo mysql struct class






?>
