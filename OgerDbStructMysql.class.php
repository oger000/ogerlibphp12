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
/* TODO
 * - optimize speed
 *   - by using change-counter and force reread of the current
 *     database structure only if the changes happended.
 *   - by handle some actions directly to the current database structure
 *     (e.g. addTable, addTableColumn, ...).
 *   - read structure at once and not per table.
 */
class OgerDbStructMysql extends OgerDbStruct {


  protected $quoteNamBegin = '`';
  protected $quoteNamEnd = '`';

  private $defCatalogName = "def";
  private $sqlServerOs;

  private $refDbStruct;
  private $curDbStruct;

  private $reverseMode;
  private $initialRefDbStruct;


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

    // in reverse mode return the initial reference structure
    if ($this->reverseMode) {
      return ($this->initialRefDbStruct);
    }

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
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["__SCHEMA_META__"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%compile%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["__SCHEMA_META__"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%version%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["__SCHEMA_META__"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }


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
    foreach ((array)$tableRecords as $tableRecord) {
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
      throw new Exception("No table schema found for table name {$tableName}.");
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

    foreach ((array)$columnRecords as $columnRecord) {

      $columnName = $columnRecord["COLUMN_NAME"];
      $columnKey = strtolower($columnName);

      $struct["__COLUMNS__"][$columnKey] = $columnRecord;

    }  // eo column loop


    // ---------------
    // get key info

    // the KEY_COLUMN_USAGE misses info like unique, nullable, etc so wie use STATISTICS for now
    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX AS ORDINAL_POSITION, COLUMN_NAME,	NON_UNIQUE
          FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND
                TABLE_NAME=:tableName
          ORDER BY INDEX_NAME, ORDINAL_POSITION
        ");
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName, "tableName" => $tableName));
    $indexRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    foreach ((array)$indexRecords as $indexRecord) {

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

    foreach ((array)$fkRecords as $fkRecord) {

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

    foreach ((array)$fkRulesRecords as $fkRulesRecord) {

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
   * @param $refDbStruct Array with the reference database structure.
   * @param $curDbStruct Optional array with the current database structure.
   *        If not present it is located from the database connection.
  */
  private function preProcessCheck($refDbStruct = null, $curDbStruct = null) {

    // do not set database reference structure in reverse mode
    // this is done by startReverseMode and must not be changed later.
    if ($refDbStruct && !$this->reverseMode) {
      $this->refDbStruct = $refDbStruct;
    }
    if (!$this->refDbStruct) {
      throw new Exception ("Reference database structure required.");
    }

    $driverName = $this->refDbStruct["__DBSTRUCT_META__"]["DRIVER_NAME"];
    if ($driverName != "mysql") {
      throw new Exception ("Driver '$driverName' not compatible. Only driver 'mysql' supported.");
    }

    // read current database structure if not present
    if (!$this->curDbStruct) {
      $this->log(static::LOG_NOTICE, "-- Read current database structure.\n");
      $this->curDbStruct = $this->getDbStruct();
    }

    // do not overwrite case sensitive database systems with lowercase converted reference structures
    // TODO provide forceLowerCase or ignoreLowerCase option ???
    if ($this->curDbStruct["__SCHEMA_META__"]["lower_case_table_names"] != 1 &&
        $this->refDbStruct["__SCHEMA_META__"]["lower_case_table_names"] == 1) {
      throw new Exception ("It is not allowed to apply lower case forced (table) reference structures" .
                           " to a case sensitive database system.");
    }

  }  // eo prechecks


  /**
   * Handle table name lettercase changes.
   * @param $refTableStruct Array with the reference table structure.
   * @return True if the table was renamed because of lettercase change, false otherwise.
  */
  private function handleTableCase($refTableStruct, $reload = true) {

    $this->preProcessCheck();

    $refTableName = $refTableStruct["__TABLE_META__"]["TABLE_NAME"];
    $tableKey = strtolower($refTableName);
    $curTableName = $this->curDbStruct["__TABLES__"][$tableKey]["__TABLE_META__"]["TABLE_NAME"];

    // if current table does not exist nothing can be renamed
    if (!$curTableName) {
      return false;
    }

    // table name can only differ in lettercase and this is only of
    // interest on case sensitive systems
    if ($refTableName != $curTableName &&
        $this->curDbStruct["__SCHEMA_META__"]["lower_case_table_names"] != 1) {

      $refTableNameQ = $this->quoteName($refTableName);
      $curTableNameQ = $this->quoteName($curTableName);
      $stmt = "RENAME TABLE {$curTableNameQ} TO {$refTableNameQ}";
      $this->executeStmt($stmt);

      // do not reload on dry-run because we did not rename
      if ($reload) {
        if ($this->getParam("dry-run")) {
          $this->curDbStruct["__TABLES__"][$tableKey]["__TABLE_META__"]["TABLE_NAME"] = $refTableName;
        }
        else {
          $this->curDbStruct["__TABLES__"][$tableKey] = $this->getTableStruct($refTableName);
        }
      }

      return true;
    }  // eo rename lettercase

    return false;
  } // eo table name lettercase handling


  // ##############################################
  // ADD STRUCTURE
  // ##############################################

  /**
  * Add missing tables, columns, indices or foreign keys to the database.
  * @see OgerDbStruct::addDbStruct().
  */
  public function addDbStruct($refDbStruct = null, $opts = array()) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck($refDbStruct);

    foreach ((array)$this->refDbStruct["__TABLES__"] as $refTableKey => $refTableStruct) {

      $refTableName = $refTableStruct["__TABLE_META__"]["TABLE_NAME"];
      $curTableStruct = $this->curDbStruct["__TABLES__"][$refTableKey];
      if (!$curTableStruct) {
        $this->addTable($refTableStruct, array("noForeignKeys" => true));
      }
      else {
        $this->addTableElements($refTableStruct, array("noForeignKeys" => true));
      }
    }  // eo table loop


    // add foreign keys after all tables, columns and indices has been created
    if (!$opts["noForeignKeys"]) {
      foreach ((array)$this->refDbStruct["__TABLES__"] as $refTableKey => $refTableStruct) {
        $this->addTableElements($refTableStruct, array("noColumns" => true, "noIndices" => true));
      }  // eo table loop for foreign keys
    }  // eo include foreign keys

    // invalide the current database struct array because we did not update internally
    $this->log(static::LOG_NOTICE, "-- Invalidate current database structure.\n");
    $this->curDbStruct = null;

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

    $this->preProcessCheck();

    $tableMeta = $tableStruct["__TABLE_META__"];
    $tableName = $this->quoteName($tableMeta["TABLE_NAME"]);

    $stmt = "CREATE TABLE $tableName (\n  ";

    // force column order
    $this->orderTableStructColumns($tableStruct["__COLUMNS__"]);

    $delim = "";
    foreach ((array)$tableStruct["__COLUMNS__"] as $columnStruct) {
      $stmt .= $delim . $this->columnDefStmt($columnStruct);
      $delim = ",\n  ";
    }  // eo column loop

    // indices
    if (!$opts["noIndices"]) {
      foreach ((array)$tableStruct["__INDICES__"] as $indexKey => $indexStruct) {
        $stmt .= $delim . $this->indexDefStmt($indexStruct);
      }  // eo index loop
    }  // eo include indices

    // foreign keys
    if (!$opts["noForeignKeys"]) {
      foreach ((array)$tableStruct["__FOREIGN_KEYS__"] as $fkKey => $fkStruct) {
        $stmt .= $delim . $this->foreignKeyDefStmt($fkStruct);
      }  // eo constraint loop
    }  // eo include foreign keys

    $stmt .= "\n)";

    // table defaults
    // Note on charset:
    // Looks like mysql derives the charset from the collation
    // via the INFORMATION_SCHEMA.COLLATION_CHARACTER_SET_APPLICABILITY table
    // and does this internally automatically if a collation is given.
    // So we depend on this - provide the collation and omit the charset.
    $stmt .= " ENGINE={$tableMeta['ENGINE']}" .
             " DEFAULT" .
             // " CHARSET={$tableMeta['']}" .  // see note above
             " COLLATE={$tableMeta['TABLE_COLLATION']}";


    // execute the statement
    $this->executeStmt($stmt);
  }  // eo add table


  /**
  * Add missing table elements.
  * @param $refTableStruct Array with the reference table structure.
  * @param $opts Optional options array where the key is the option name.<br>
  *        Valid options are:<br>
  *        - noColumns<br>
  *        - noIndices<br>
  *        - noForeignKeys<br>
  */
  public function addTableElements($refTableStruct = null, $opts = array()) {

    $this->preProcessCheck();

    // rename lettercase - reload current table structure
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["__TABLES__"][strtolower($refTableStruct["__TABLE_META__"]["TABLE_NAME"])];

    // add columns
    if (!$opts["noColumns"]) {
      $this->orderTableStructColumns($refTableStruct["__COLUMNS__"]);
      $afterColumnName = "";
      foreach ((array)$refTableStruct["__COLUMNS__"] as $refColumnKey => $refColumnStruct) {
        if (!$curTableStruct["__COLUMNS__"][$refColumnKey]) {
          $this->addTableColumn($refColumnStruct, array("afterColumnName" => $afterColumnName));
        }
        // this column exists (old or new created) so the next missing column will be added after this
        $afterColumnName = $refColumnStruct["COLUMN_NAME"];
      }  // eo column loop
    }  // eo columns

    // indices
    if (!$opts["noIndices"]) {
      foreach ((array)$refTableStruct["__INDICES__"] as $refIndexKey => $refIndexStruct) {
        if (!$curTableStruct["__INDICES__"][$refIndexKey]) {
          $this->addTableIndex($refIndexStruct);
        }
      }
    }  // eo index

    // foreign keys
    if (!$opts["noForeignKeys"]) {
      foreach ((array)$refTableStruct["__FOREIGN_KEYS__"] as $refFkKey => $refFkStruct) {
        if (!$curTableStruct["__FOREIGN_KEYS__"][$refFkKey]) {
          $this->addTableForeignKey($refFkStruct);
        }
      }
    }  // eo foreign keys

  }  // eo add missing elements to a table


  /**
  * Add a column to a table structure.
  * @param $columnStruct Array with the table structure.
  * @param $opts Optional options array. Key is option name.<br>
  *        Valid options are:<br>
  *        - afterColumnName: Passed to columnDefStmd(). Description see there.
  */
  public function addTableColumn($columnStruct, $opts = array()) {

    $stmt = "ALTER TABLE " .
            $this->quoteName($columnStruct["TABLE_NAME"]) .
            " ADD COLUMN " .
            $this->columnDefStmt($columnStruct, $opts);

    $this->executeStmt($stmt);
  }  // eo add column to table


  /**
  * Add an index to a table.
  * @param $indexStruct Array with the index structure.
  */
  public function addTableIndex($indexStruct) {
    $tableName = $this->quoteName($indexStruct["__INDEX_META__"]["TABLE_NAME"]);
    $stmt = "ALTER TABLE $tableName ADD " . $this->indexDefStmt($indexStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add index


  /**
  * Add a foreign key to a table.
  * @param $fkStruct Array with the foreign key structure.
  */
  public function addTableForeignKey($fkStruct) {
    $tableName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
    $stmt = "ALTER TABLE $tableName ADD " . $this->foreignKeyDefStmt($fkStruct, $opts);
    $this->executeStmt($stmt);
  }  // eo add foreign key


  // ##############################################
  // REFRESH STRUCTURE
  // ##############################################

  /**
  * Refresh existing tables, columns, indices and foreign keys.
  * @see OgerDbStruct::refreshDbStruct().
  */
  public function refreshDbStruct($refDbStruct = null) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck($refDbStruct);

    // refresh current table if exits
    foreach ((array)$this->refDbStruct["__TABLES__"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $this->curDbStruct["__TABLES__"][$refTableKey];
      if ($curTableStruct) {
        $this->refreshTable($refTableStruct);
      }
    }  // eo table loop

    // invalide the current database struct array because we did not update internally
    $this->log(static::LOG_NOTICE, "-- Invalidate current database structure.\n");
    $this->curDbStruct = null;

  }  // eo refresh struc



  /**
  * Refresh an existing table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTable($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase - reload current table structure
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["__TABLES__"][strtolower($refTableStruct["__TABLE_META__"]["TABLE_NAME"])];

    // table meta / defaults
    $refTableMeta = $refTableStruct["__TABLE_META__"];
    $curTableMeta = $curTableStruct["__TABLE_META__"];
    if ($refTableMeta["TABLE_COLLATION"] != $curTableMeta["TABLE_COLLATION"] ||
        $refTableMeta["ENGINE"] != $curTableMeta["ENGINE"]) {

      $stmt .= "ALTER TABLE " .
               $this->quoteName($refTableMeta["TABLE_NAME"]) .
               " ENGINE=" . $refTableMeta["ENGINE"] .
               " DEFAULT" .
               " COLLATE=" . $refTableMeta["TABLE_COLLATION"];
      $this->executeStmt($stmt);
    }  // eo table meta


    // refresh existing columns
    foreach ((array)$refTableStruct["__COLUMNS__"] as $refColumnKey => $refColumnStruct) {
      if ($curTableStruct["__COLUMNS__"][$refColumnKey]) {
        $this->refreshTableColumn($refColumnStruct);
      }
    }

    // refresh existing indices
    foreach ((array)$refTableStruct["__INDICES__"] as $refIndexKey => $refIndexStruct) {
      if ($curTableStruct["__INDICES__"][$refIndexKey]) {
        $this->refreshTableIndex($refIndexStruct);
      }
    }

    // refresh existing foreign keys
    foreach ((array)$refTableStruct["__FOREIGN_KEYS__"] as $refFkKey => $refFkStruct) {
      if ($curTableStruct["__FOREIGN_KEYS__"][$refFkKey]) {
        $this->refreshTableForeignKey($refFkStruct);
      }
    }

  }  // eo refresh table


  /**
  * Refresh an existing table column.
  * Direct use with care. Better use refreshTable().
  * @param $refColumnStruct Array with the reference column structure.
  */
  public function refreshTableColumn($refColumnStruct) {

    $curColumnStruct = $this->curDbStruct["__TABLES__"][strtolower($refColumnStruct["TABLE_NAME"])]
                       ["__COLUMNS__"][strtolower($refColumnStruct["COLUMN_NAME"])];

    $refColumnSql = $this->columnDefStmt($refColumnStruct);
    $curColumnSql = $this->columnDefStmt($curColumnStruct);

    if ($refColumnSql != $curColumnSql) {

      $tableName = $this->quoteName($curColumnStruct["TABLE_NAME"]);
      $curColumnName = $this->quoteName($curColumnStruct["COLUMN_NAME"]);

      $this->log(static::LOG_NOTICE, "-- Old: $curColumnSql\n" .
                                     "-- New: $refColumnSql\n");

      // TODO: include AFTER | FIRST position here?
      $stmt = "ALTER TABLE {$tableName} CHANGE COLUMN $curColumnName $refColumnSql";
      $this->executeStmt($stmt);
    }  // eo something changed
  }  // eo update column


  /**
  * Refresh an existing table index.
  * @param $refIndexStruct Array with the reference index structure.
  */
  public function refreshTableIndex($refIndexStruct) {

    $curIndexStruct = $this->curDbStruct["__TABLES__"][strtolower($refIndexStruct["__INDEX_META__"]["TABLE_NAME"])]
                      ["__INDICES__"][strtolower($refIndexStruct["__INDEX_META__"]["INDEX_NAME"])];

    $refIndexSql = $this->indexDefStmt($refIndexStruct);
    $curIndexSql = $this->indexDefStmt($curIndexStruct);

    if ($refIndexSql != $curIndexSql) {

      $tableName = $this->quoteName($refIndexStruct["__INDEX_META__"]["TABLE_NAME"]);
      $curIndexName = $this->quoteName($curIndexStruct["__INDEX_META__"]["INDEX_NAME"]);

      $this->log(static::LOG_NOTICE, "-- Old: $curIndexSql\n" .
                                     "-- New: $refIndexSql\n");

      $stmt = "ALTER TABLE $tableName DROP INDEX $curIndexName;" .
              "ALTER TABLE $tableName ADD $refIndexSql";
      $this->executeStmt($stmt);
    }  // eo something changed
  }  // eo update index


  /**
  * Refresh an existing foreign key.
  * @param $refFkStruct Array with the reference foreign key structure.
  */
  public function refreshTableForeignKey($refFkStruct) {

    $curFkStruct = $this->curDbStruct["__TABLES__"][strtolower($refFkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"])]
                      ["__FOREIGN_KEYS__"][strtolower($refFkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"])];

    $refFkSql = $this->foreignKeyDefStmt($refFkStruct);
    $curFkSql = $this->foreignKeyDefStmt($curFkStruct);

    if ($refFkSql != $curFkSql) {

      $tableName = $this->quoteName($refFkStruct["__FOREIGN_KEY_META__"]["TABLE_NAME"]);
      $curFkName = $this->quoteName($curFkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

      $this->log(static::LOG_NOTICE, "-- Old: $curFkSql\n" .
                                     "-- New: $refFkSql\n");

      $stmt = "ALTER TABLE $tableName DROP FOREIGN KEY $curFkName;" .
              "ALTER TABLE $tableName ADD $refFkSql";
      $this->executeStmt($stmt);
    }  // eo something changed
  }  // eo update foreign key



  // ##############################################
  // UPDATE STRUCTURE (ADD + REFRESH)
  // ##############################################


  /**
  * Update existing tables, columns, indices or foreign keys and add missing one.
  * @see OgerDbStruct::updateDbStruct().
  */
  public function updateDbStruct($refDbStruct, $opts = array()) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck($refDbStruct);

    $this->addDbStruct(null, $opts);
    $this->refreshDbStruct(null);

  }  // eo update struc



  // ##############################################
  // REORDER STRUCTURE
  // ##############################################

  /**
  * Reorder database structure.
  * Order only columns of tables because the order of
  * columns in indices and foreign keys is treated significant
  * and therefore is handled by refreshDbStruct().
  * Tables do not have a specific order inside the database.
  * @see OgerDbStruct::reorderDbStruct().
  */
  public function reorderDbStruct($refDbStruct) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck($refDbStruct);

    foreach ((array)$this->refDbStruct["__TABLES__"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $this->curDbStruct["__TABLES__"][$refTableKey];
      if ($curTableStruct) {
        $this->reorderTableColumns($refTableStruct);
      }
    }  // eo table loop

    // invalide the current database struct array because we did not update internally
    $this->log(static::LOG_NOTICE, "-- Invalidate current database structure.\n");
    $this->curDbStruct = null;

  }  // eo order db struct


  /**
  * Reorder table columns.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function reorderTableColumns($refTableStruct) {

    $this->preProcessCheck();

    $tableName = $refTableStruct["__TABLE_META__"]["TABLE_NAME"];

    // rename lettercase - reload current table structure
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["__TABLES__"][strtolower($tableName)];


    $this->orderTableStructColumns($refTableStruct["__COLUMNS__"]);
    $refColNames = array();
    foreach ((array)$refTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $refColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }

    $this->orderTableStructColumns($curTableStruct["__COLUMNS__"]);
    $curColNames = array();
    foreach ((array)$curTableStruct["__COLUMNS__"] as $columnKey => $columnStruct) {
      $curColNames[$columnKey] = $columnStruct["COLUMN_NAME"];
    }


    // remove all column names that are not in both tables
    // because they do not affect the reordering
    foreach ((array)$refColNames as $colKey => $colStruct) {
      if (!$curColNames[$colKey]) {
        unset($refColNames[$colKey]);
      }
    }

    foreach ((array)$curColNames as $colKey => $colStruct) {
      if (!$refColNames[$colKey]) {
        unset($curColNames[$colKey]);
      }
    }


    $tableName = $this->quoteName($tableName);
    // use current column structure because we dont want to change the column definition but only the order
    $afterColumn = "";
    foreach ((array)$refColNames as $colKey => $colName) {

      $nextCurColName = reset($curColNames);

      if ($colName != $nextCurColName) {
        $columnDef = $this->columnDefStmt($curTableStruct["__COLUMNS__"][$colKey], array("afterColumnName" => $afterColumn));
        $colNameQ = $this->quoteName($colName);
        $stmt = "ALTER TABLE $tableName CHANGE COLUMN $colNameQ $columnDef";
        $this->executeStmt($stmt);
      }

      $afterColumn = $colName;

      // remove the processed column name from the unused current column names
      unset($curColNames[$colKey]);
    }  // eo common column loop

  }  // eo order table columns
//echo var_export($curTableStruct); exit;



  // ##############################################
  // CLEANUP STRUCTURE
  // ##############################################

  /**
  * Cleanup surpluss tables, columns, indices and foreign keys.
  * @see OgerDbStruct::cleanupDbStruct().
  */
  public function cleanupDbStruct($refDbStruct) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck();

    // first cleanup foreign keys before we remove tables, columns or indices
    // and as sideeffect handle table lettercase
    foreach ((array)$this->curDbStruct["__TABLES__"] as $curTableKey => $curTableStruct) {

      $refTableStruct = $this->refDbStruct["__TABLES__"][$curTableKey];
      if (!$refTableStruct) {
        continue;
      }

      // rename lettercase - reload current table structure
      $this->handleTableCase($refTableStruct);
      $curTableStruct = $this->curDbStruct["__TABLES__"][strtolower($curTableStruct["__TABLE_META__"]["TABLE_NAME"])];

      $tableName = $this->quoteName($curTableStruct["__TABLE_META__"]["TABLE_NAME"]);
      foreach ((array)$curTableStruct["__FOREIGN_KEYS__"] as $fkKey => $fkStruct) {
        if (!$refTableStruct["__FOREIGN_KEYS__"][$fkKey]) {
          $fkName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);
          $stmt = "ALTER TABLE {$tableName} DROP CONSTRAINT {$fkName}";
          $this->executeStmt($stmt);
        }
      }
    }  // table loop for foreign keys


    // cleanup tables, columns and indices
    foreach ((array)$this->curDbStruct["__TABLES__"] as $curTableKey => $curTableStruct) {

      $refTableStruct = $this->refDbStruct["__TABLES__"][$curTableKey];
      $tableName = $this->quoteName($curTableStruct["__TABLE_META__"]["TABLE_NAME"]);

      if (!$refTableStruct) {
        $stmt = "DROP TABLE {$tableName}";
        $this->executeStmt($stmt);
      }
      else {
        // cleanup indices
        foreach ((array)$curTableStruct["__INDICES__"] as $curIndexKey => $curIndexStruct) {
          if (!$refTableStruct["__INDICES__"][$curIndexKey]) {
            $indexName = $this->quoteName($curIndexStruct["__INDEX_META__"]["INDEX_NAME"]);
            $stmt = "ALTER TABLE {$tableName} DROP INDEX {$indexName}";
            $this->executeStmt($stmt);
          }
        }
        // cleanup columns
        foreach ((array)$curTableStruct["__COLUMNS__"] as $curColumnKey => $curColumnStruct) {
          if (!$refTableStruct["__COLUMNS__"][$curColumnKey]) {
            $columnName = $this->quoteName($curColumnStruct["COLUMN_NAME"]);
            $stmt = "ALTER TABLE {$tableName} DROP COLUMN {$columnName}";
            $this->executeStmt($stmt);
          }
        }
      }  // eo existing table
    }  // eo table loop

     // invalide the current database struct array because we did not update internally
    $this->log(static::LOG_NOTICE, "-- Invalidate current database structure.\n");
    $this->curDbStruct = null;

 }  // eo order db struct




  // ##############################################
  // FORCE STRUCTURE (ADD + REFRESH + REORDER + CLEANUP)
  // ##############################################

  /**
  * Force database structure.
  * @see OgerDbStruct::forceDbStruct().
  */
  public function forceDbStruct($refDbStruct) {

    $this->log(static::LOG_NOTICE, "-- *** Enter " . __METHOD__ . ".\n");

    $this->preProcessCheck($refDbStruct);

    $this->updateDbStruct(null);
    $this->cleanupDbStruct(null);
    $this->reorderDbStruct(null);

  }  // eo order db struct


  // ##############################################
  // FORMAT OUTPUT
  // ##############################################


  /**
  * Format the database struct array into a string.
  * @see OgerDbStruct::formatDbStruct().
  */
  public function formatDbStruct($dbStruct) {
    //return parent::formatDbStruct($dbStruct);
    return $this->formatDbStructHelper($dbStruct);
  }

  /**
  * Format the database struct array into a string.
  * @see OgerDbStruct::formatDbStruct().
  */
  public function formatDbStructHelper($struct, $level = 0, $singleLine = 0) {

    $indent = 2;
    $prefix = str_repeat(" ", $level * $indent);
    $prefix2 = $prefix . str_repeat(" ", $indent);
    $delim = "\n";

    if ($singleLine == 1) {
      $prefix = " ";
      $prefix2 = "";
      $delim = " ";
    }

    $str = "";
    if (is_array($struct)) {
      $str .= "array ({$delim}";
      foreach ($struct as $key => $value) {
        $nextLevel = $level + 1;
        $nextSingleLine = $singleLine;
        if ($singleLine > 1) {
          $nextSingleLine = $singleLine -1;
        }
        if ($key == "__TABLE_META__" ||
            $key == "__INDEX_META__" ||
            $key == "__FOREIGN_KEY_META__") {
          $nextSingleLine = 1;
        }
        if ($key == "__COLUMNS__" ||
            $key == "__INDEX_COLUMNS__" ||
            $key == "__FOREIGN_KEY_COLUMNS__") {
          $nextSingleLine = 2;
        }
        $str .= $prefix2 . var_export($key, true) . " => " . $this->formatDbStructHelper($value, $nextLevel, $nextSingleLine);
      }
      $str .= "{$prefix})" . ($level ? "," : "") . "\n";
    }
    else {
      $str .= var_export($struct, true) . ",{$delim}";
    }

    return $str;
  }  // eo format db struct


  // ##############################################
  // REVERSE MODE
  // ##############################################

 /**
  * Start the reverse mode.
  * Swap database reference structure and current structure to show how
  * the reference structure must be changed to reflect the current structure.
  * Implies param "dry-run".
  * @param $refDbStruct Array with the database reference structure.
  */
  public function startReverseMode ($refDbStruct) {

    if ($this->reverseMode) {
      throw new Exception("Already in reverse mode.");
    }

    $this->refDbStruct = $this->getDbStruct();

    $this->initialRefDbStruct = $refDbStruct;
    $this->curDbStruct = $refDbStruct;

    $this->reverseMode = true;
    $this->setParam("dry-run", true);

  }  // eo start reverse  mode










  // ##############################################
  // SQL DEFINITION STATEMENTS
  // ##############################################

 /**
  * Create sql statement for a column definition.
  * @param $columnStruct  Array with column structure.
  * @param $opts Optional options array. Key is option name.<br>
  *        Valid options are:<br>
  *        - afterColumnName: The column name after which this column should be placed.
  *          Null means append after the last column.
  *          Any other empty value means insert on first position.
  * @return The SQL statement part for a column definition.
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
  * Create a sql statement for a table index.
  * @param $indexStruct  Array with index structure.
  * @return The SQL statement for the index definition.
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
    $this->orderIndexStructColumns($indexStruct["__INDEX_COLUMNS__"]);
    $colNames = array();
    foreach ((array)$indexStruct["__INDEX_COLUMNS__"] as $indexColumnStruct) {
      $colNames[] = $this->quoteName($indexColumnStruct["COLUMN_NAME"]);
    }

    // put fields to statement
    $stmt .= " (" . implode(", ", $colNames) . ")";

    return $stmt;
  }  // eo index def


  /**
  * Create a sql statement for a foreign key.
  * @param $fkStruct  Array with foreign key structure.
  * @return The SQL statement for the foreign key sql statement.
  */
  public function foreignKeyDefStmt($fkStruct) {

    $fkName = $this->quoteName($fkStruct["__FOREIGN_KEY_META__"]["FOREIGN_KEY_NAME"]);

    $stmt = "CONSTRAINT $fkName";

    // force order of columns and extract names
    // we assume that the column order in the reference is the same as in the foreign key
    $this->orderForeignKeyStructColumns($fkStruct["__FOREIGN_KEY_COLUMNS__"]);
    $colNames = array();
    $colNamesRef = array();
    foreach ((array)$fkStruct["__FOREIGN_KEY_COLUMNS__"] as $fkColumnStruct) {
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




  // ##############################################
  // HELPER METHODS (SORTING, ...)
  // ##############################################


  /**
  * Force order of table columns.
  * @param columns Array with the columns structure.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered columns array.
  */
  private function orderTableStructColumns(&$columns) {

    // if no columns then do nothing
    if (!$columns) {
      return $columns;
    }

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
  * Force order of index columns.
  * @param columns Array with the index columns structure.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered columns array.
  */
  public function orderIndexStructColumns(&$columns) {

    // if no columns then do nothing
    if (!$columns) {
      return $columns;
    }

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
  * Force order of foreign key columns.
  * @param columns Array with the foreign key columns structure.
  *        The columns array is passed per reference so
  *        the columns are ordered in place and you
  *        dont need the return value.
  * @return Ordered columns array.
  */
  public function orderForeignKeyStructColumns(&$columns) {

    // if no columns then do nothing
    if (!$columns) {
      return $columns;
    }

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



}  // eo mysql struct class






?>
