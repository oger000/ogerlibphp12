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
* Collations are handled (which in turn modifies charset).<br>
* Cross database references are NOT handled by design.<br>
* Handling of views is experimental and does NOT work well beetween linux and windows.<br>
*
*/
/* TODO
 * - optimize speed
 *   - read structure at once and not per table.
 */
 /* FIXME
  * unhandled situations:
  * - AUTO_INCREMENT columns needs an index. This is not forced and
  *   therefore some combinations of add/refresh/cleanup will fail.
  *   So we have to check/force an index for autoInc columns.
  *   - addTableColumn -> add column without autoInc, add index, refresh with autoInc
  *   - refreshTableColumn -> add index, refresh with autoInc
  *
  *   - maybe cleanupTableIndex, refreshTableIndex
  *     has to check that an index for autoInc columns remain ???
  */
/* MEMO
 * - temporary disable constraint checking
 *   SET foreign_key_checks = 0;
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
      $this->log(static::LOG_NOTICE, "-- Get db struct in reverse mode: Return initial reference structure.\n");
      return ($this->initialRefDbStruct);
    }

    $this->log(static::LOG_NOTICE, "-- Read current database structure.\n");

    // get structure head
    $struct = $this->getNewStructHead();
    $struct["DBSTRUCT_META"]["HOSTNAME"] = gethostname();
    // TODO ip-number of database connection


    // get schema structure
    $pstmt = $this->conn->prepare("
        SELECT SCHEMA_NAME,DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
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

    $struct["SCHEMA_META"] = $schemaRecords[0];


    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%case%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["SCHEMA_META"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%compile%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["SCHEMA_META"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }

    $pstmt = $this->conn->prepare("SHOW VARIABLES LIKE '%version%'");
    $pstmt->execute();
    $schemaRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    foreach ((array)$schemaRecords as $schemaRecord) {
      $struct["SCHEMA_META"][$schemaRecord["Variable_name"]] = $schemaRecord["Value"];
    }


    // get table structure
    $stmt = "
        SELECT TABLE_NAME
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName AND TABLE_TYPE='BASE TABLE'
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
      $struct["TABLES"][$tableKey] = $this->getTableStruct($tableName);
    }  // eo table loop



    // get views
    $stmt = "
        SELECT TABLE_NAME, VIEW_DEFINITION,
          CHECK_OPTION, IS_UPDATABLE,
          CHARACTER_SET_CLIENT, COLLATION_CONNECTION
          FROM INFORMATION_SCHEMA.VIEWS
          WHERE TABLE_CATALOG=:catalogName AND
                TABLE_SCHEMA=:dbName
        ";
    $pstmt = $this->conn->prepare($stmt);
    $pstmt->execute(array("catalogName" => $this->defCatalogName, "dbName" => $this->dbName));
    $viewRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    $viewNames = array();
    foreach ((array)$viewRecords as $viewRecord) {
      $viewName = $viewRecord["TABLE_NAME"];
      $viewKey = strtolower($viewName);
      $struct["VIEWS"][$viewKey] = $this->getViewStruct($viewRecord);
    }  // eo view loop


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

    $struct["TABLE_META"] = $tableRecord;


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

      // if column is not nullable but default value is NULL then
      // unset the default value and let the database decide what to do
      if ($columnRecord["COLUMN_DEFAULT"] === null && $columnRecord["IS_NULLABLE"] == "NO") {
        unset($columnRecord["COLUMN_DEFAULT"]);
      }

      // unset some "unused" fields
      if (!$columnRecord["EXTRA"]) {
        unset($columnRecord["EXTRA"]);
      }
      if (!$columnRecord["COLUMN_KEY"]) {
        unset($columnRecord["COLUMN_KEY"]);
      }
      if ($columnRecord["CHARACTER_MAXIMUM_LENGTH"] === null) {
        unset($columnRecord["CHARACTER_MAXIMUM_LENGTH"]);
      }
      if ($columnRecord["CHARACTER_OCTET_LENGTH"] === null) {
        unset($columnRecord["CHARACTER_OCTET_LENGTH"]);
      }
      if ($columnRecord["CHARACTER_SET_NAME"] === null) {
        unset($columnRecord["CHARACTER_SET_NAME"]);
      }
      if ($columnRecord["COLLATION_NAME"] === null) {
        unset($columnRecord["COLLATION_NAME"]);
      }
      if ($columnRecord["NUMERIC_PRECISION"] === null) {
        unset($columnRecord["NUMERIC_PRECISION"]);
      }
      if ($columnRecord["NUMERIC_SCALE"] === null) {
        unset($columnRecord["NUMERIC_SCALE"]);
      }



      $struct["COLUMNS"][$columnKey] = $columnRecord;

    }  // eo column loop


    // ---------------
    // get key info
    // correstponding table name is STATISTICS !

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
      $struct["INDICES"][$indexKey]["INDEX_META"]["INDEX_NAME"] = $indexName;
      $struct["INDICES"][$indexKey]["INDEX_META"]["INDEX_KEY_TYPE"] = $indexType;
      $struct["INDICES"][$indexKey]["INDEX_META"]["TABLE_NAME"] = $indexRecord["TABLE_NAME"];

      // index columns
      $indexColumnKey = strtolower($indexRecord["COLUMN_NAME"]);
      $struct["INDICES"][$indexKey]["INDEX_COLUMNS"][$indexColumnKey] = $indexRecord;

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

    // the table TABLE_CONSTRAINTS contains only constraint names,
    // so we use table KEY_COLUMN_USAGE this time
    // we do not support cross database settings, so we removed TABLE_SCHEMA and REFERENCED_TABLE_SCHEMA from query
    $pstmt = $this->conn->prepare("
        SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, POSITION_IN_UNIQUE_CONSTRAINT,
               REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
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
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["FOREIGN_KEY_NAME"] = $fkName;
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["TABLE_NAME"] = $fkRecord["TABLE_NAME"];
      //$struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["REFERENCED_TABLE_SCHEMA"] = $fkRecord["REFERENCED_TABLE_SCHEMA"];
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["REFERENCED_TABLE_NAME"] = $fkRecord["REFERENCED_TABLE_NAME"];

      // referenced columns
      $fkColumnKey = strtolower($fkRecord["COLUMN_NAME"]);
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_COLUMNS"][$fkColumnKey] = $fkRecord;

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
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["MATCH_OPTION"] = $fkRulesRecord["MATCH_OPTION"];
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["UPDATE_RULE"] = $fkRulesRecord["UPDATE_RULE"];
      $struct["FOREIGN_KEYS"][$fkKey]["FOREIGN_KEY_META"]["DELETE_RULE"] = $fkRulesRecord["DELETE_RULE"];

    }  // eo foreign key columns loop

    return $struct;
  }  // eo get table struc



  /**
  * Prepare structure for one view.
  * @param $viewRecord Record with view data.
  * @return Array with view structure.
  */
  public function getViewStruct($viewRecord) {

    $struct = array();

    // extract definition
    $definition = $viewRecord['VIEW_DEFINITION'];
    unset($viewRecord['VIEW_DEFINITION']);

    // remove schema name to make transferable
    $schemaQ = $this->quoteName($this->dbName);
    $definition = preg_replace("/{$schemaQ}\./", "", $definition);

    // put all remaining into meta data
    $struct["VIEW_META"] = $viewRecord;
    $struct['DEFINITION'] = $definition;

    return $struct;
  }  // eo get view struc




  // ##############################################
  // DRIVER SPECIFIC HELPERS
  // ##############################################

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

    $driverName = $this->refDbStruct["DBSTRUCT_META"]["DRIVER_NAME"];
    if ($driverName != "mysql") {
      throw new Exception ("Driver '$driverName' not compatible. Only driver 'mysql' supported.");
    }

    // read current database structure if not present
    if (!$this->curDbStruct) {
      $this->curDbStruct = $this->getDbStruct();
    }

    // do not overwrite case sensitive database systems with lowercase converted reference structures
    // lower_case_table_names: 0=linux, 1=windows, 2=mac
    // TODO provide forceLowerCase or ignoreLowerCase option ???
    if ($this->curDbStruct["SCHEMA_META"]["lower_case_table_names"] != 1 &&
        $this->refDbStruct["SCHEMA_META"]["lower_case_table_names"] == 1) {
      throw new Exception ("It is not allowed to apply lower case forced (table) reference structures" .
                           " to a case sensitive database system.");
    }

  }  // eo prechecks


  /**
   * Handle table name lettercase changes.
   * @param $refTableStruct Array with the reference table structure.
   * @return True if the table was renamed because of lettercase change, false otherwise.
  */
  private function handleTableCase($refTableStruct) {

    $this->preProcessCheck();

    $refTableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $tableKey = strtolower($refTableName);
    $curTableName = $this->curDbStruct["TABLES"][$tableKey]["TABLE_META"]["TABLE_NAME"];

    // if current table does not exist nothing can be renamed
    if (!$curTableName) {
      return false;
    }

    // check that table name only differ in lettercase
    if (strtolower($refTableName) != strtolower($curTableName)) {
      return false;
    }

    // difference in lettercase is only of interest on case sensitive systems
    if ($refTableName != $curTableName &&
        $this->curDbStruct["SCHEMA_META"]["lower_case_table_names"] != 1) {

      $refTableNameQ = $this->quoteName($refTableName);
      $curTableNameQ = $this->quoteName($curTableName);
      $stmt = "RENAME TABLE {$curTableNameQ} TO {$refTableNameQ}";
      $this->execChange($stmt);


      // adapt lettercase on the fly
      array_walk_recursive($this->curDbStruct["TABLES"][$tableKey], "OgerDbStructMysql::walkTableCase", $refTableName);

      return true;
    }  // eo rename lettercase

    return false;
  } // eo table name lettercase handling


  /**
  * Adopt current table structure array on the fly after table case change.
  */
  public static function walkTableCase(&$oldValue, $key, $newValue) {

    // sanity check to restrict change to matching table names
    if ($key == "TABLE_NAME" &&
        strtolower($oldValue) == strtolower($newValue)) {
      $oldValue = $newValue;
    }
  }  // eo adopt table case in structure


  /**
   * Handle view name lettercase changes.
   * @param $refViewStruct Array with the reference view structure.
   * @return True if the view was renamed because of lettercase change, false otherwise.
  */
  private function handleViewCase($refViewStruct) {

    $this->preProcessCheck();

    $refViewName = $refViewStruct["VIEW_META"]["TABLE_NAME"];
    $viewKey = strtolower($refViewName);
    $curViewName = $this->curDbStruct["VIEW"][$viewKey]["VIEW_META"]["TABLE_NAME"];

    // if current view does not exist nothing can be renamed
    if (!$curViewName) {
      return false;
    }

    // check that view name only differ in lettercase
    if (strtolower($refViewName) != strtolower($curViewName)) {
      return false;
    }

    // difference in lettercase is only of interest on case sensitive systems
    if ($refViewName != $curViewName &&
        $this->curDbStruct["SCHEMA_META"]["lower_case_table_names"] != 1) {

      $refViewNameQ = $this->quoteName($refViewName);
      $curViewNameQ = $this->quoteName($curViewName);
      $stmt = "RENAME TABLE {$curViewNameQ} TO {$refViewNameQ}";
      $this->execChange($stmt);

      // adapt lettercase on the fly
      array_walk_recursive($this->curDbStruct["VIEW"][$viewKey], "OgerDbStructMysql::walkTableCase", $refViewName);

      return true;
    }  // eo rename lettercase

    return false;
  } // eo view name lettercase handling


  // ##############################################
  // ADD STRUCTURE
  // ##############################################

  /**
  * Add missing tables, columns, indices or foreign keys to the database.
  * @see OgerDbStruct::addDbStruct().
  */
  public function addDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    // table loop
    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {

      $refTableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
      $curTableStruct = $this->curDbStruct["TABLES"][$refTableKey];
      if (!$curTableStruct) {
        $this->addTableCore($refTableStruct);
      }
      else {
        $this->addTableColumns($refTableStruct);
        $this->addTableIndices($refTableStruct);
      }
    }  // eo table loop

    // add foreign keys after all tables, columns and indices has been created
    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {
      $this->addTableForeignKeys($refTableStruct);
    }  // eo include foreign keys

    // view loop
    foreach ((array)$this->refDbStruct["VIEWS"] as $refViewKey => $refViewStruct) {
      $curViewStruct = $this->curDbStruct["VIEWS"][$refViewKey];
      if (!$curViewStruct) {
        $this->addView($refViewStruct);
      }
    }  // eo view loop

  }  // eo add db struc


  /**
  * Add a table to the current database structure.
  * @param $tableStruct Array with the table structure.
  * @param $opts Optional option array. Key is option.
  */
  public function addTable($tableStruct) {

    $this->preProcessCheck();

    $this->addTableCore($tableStruct);  // includes columns
    //$this->addTableIndices($tableStruct);
    //$this->addTableForeignKeys($tableStruct);
  }  // eo add table


  /**
  * Add table (with columns, but without indexes
  * and without foreign keys) to the current database structure.
  * @param $tableStruct Array with the table structure.
  */
  public function addTableCore($tableStruct) {

    $this->preProcessCheck();

    $tableMeta = $tableStruct["TABLE_META"];
    $tableName = $tableMeta["TABLE_NAME"];
    $tableNameQ = $this->quoteName($tableName);

    $stmt = "CREATE TABLE $tableNameQ (\n  ";

    // force column order
    $this->asortColumnsByOrdinalPos($tableStruct["COLUMNS"]);

    $delim = "";
    foreach ((array)$tableStruct["COLUMNS"] as $columnStruct) {
      $stmt .= $delim . $this->columnDefStmt($columnStruct);
      $delim = ",\n  ";
    }  // eo column loop

    // add indices here, because auto increment columns need one
    foreach ((array)$tableStruct["INDICES"] as $indexKey => $indexStruct) {
      $stmt .= $delim . $this->indexDefStmt($indexStruct);
    }  // eo index loop

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
    $this->execChange($stmt);

    // update current db struct array
    //unset($tableStruct["INDICES"]);
    unset($tableStruct["FOREIGN_KEYS"]);
    $this->curDbStruct["TABLES"][strtolower($tableName)] = $tableStruct;

  }  // eo add table


  /**
  * Add missing columns to a table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function addTableColumns($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($refTableStruct["TABLE_META"]["TABLE_NAME"])];

    $this->asortColumnsByOrdinalPos($refTableStruct["COLUMNS"]);
    $afterColumnName = -1;
    foreach ((array)$refTableStruct["COLUMNS"] as $refColumnKey => $refColumnStruct) {
      if (!$curTableStruct["COLUMNS"][$refColumnKey]) {
        $this->addTableColumn($refColumnStruct, $afterColumnName);
      }
      // this column exists (old or new created) so the next missing column will be added after this
      $afterColumnName = $refColumnStruct["COLUMN_NAME"];
    }  // eo column loop

  }  // eo add missing columns


  /**
  * Add missing indices.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function addTableIndices($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($refTableStruct["TABLE_META"]["TABLE_NAME"])];

    foreach ((array)$refTableStruct["INDICES"] as $refIndexKey => $refIndexStruct) {
      if (!$curTableStruct["INDICES"][$refIndexKey]) {
        $this->addTableIndex($refIndexStruct);
      }
    }

  }  // eo add missing indices


  /**
  * Add missing foreign keys.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function addTableForeignKeys($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($refTableStruct["TABLE_META"]["TABLE_NAME"])];

    foreach ((array)$refTableStruct["FOREIGN_KEYS"] as $refFkKey => $refFkStruct) {
      if (!$curTableStruct["FOREIGN_KEYS"][$refFkKey]) {
        $this->addTableForeignKey($refFkStruct);
      }
    }

  }  // eo add missing foreign keys


  /**
  * Add a column to a table structure.
  * @param $columnStruct Array with the table structure.
  * @param $afterColumnName: Passed to columnDefStmd(). Description see there.
  */
  public function addTableColumn($columnStruct, $afterColumnName) {

    $this->preProcessCheck();
    $tableName = $columnStruct["TABLE_NAME"];

    $stmt = "ALTER TABLE " . $this->quoteName($tableName) .
            " ADD COLUMN " . $this->columnDefStmt($columnStruct, $afterColumnName);

    $this->execChange($stmt);

    // update current db struct array
    $tableKey = strtolower($tableName);
    $colKey = strtolower($columnStruct["COLUMN_NAME"]);
    if ($afterColumnName) {
      if ($afterColumnName == -1) {  // first position
        $afterColPos = 0;            // ordinal pos starts at 1
      }
      else {
        $afterColPos = $this->curDbStruct["TABLES"][$tableKey]["COLUMNS"][strtolower($afterColumnName)]["ORDINAL_POSITION"];
      }
    }
    else {  // append - last ordinal pos is column count
      $afterColPos = count($this->curDbStruct["TABLES"][$tableKey]["COLUMNS"]);
    }
    foreach ($this->curDbStruct["TABLES"][$tableKey]["COLUMNS"] as &$tmpCol) {
      if ($tmpCol["ORDINAL_POSITION"] > $afterColPos) {
        $tmpCol["ORDINAL_POSITION"]++;
      }
    }
    $columnStruct["ORDINAL_POSITION"] = $afterColPos + 1;
    $this->curDbStruct["TABLES"][$tableKey]["COLUMNS"][$colKey] = $columnStruct;
    // reorder column struct array
    $this->asortColumnsByOrdinalPos($this->curDbStruct["TABLES"][$tableKey]["COLUMNS"]);

  }  // eo add column to table


  /**
  * Add an index to a table.
  * @param $indexStruct Array with the index structure.
  */
  public function addTableIndex($indexStruct) {

    $this->preProcessCheck();
    $tableName = $indexStruct["INDEX_META"]["TABLE_NAME"];
    $tableNameQ = $this->quoteName($tableName);

    $stmt = "ALTER TABLE $tableNameQ ADD " . $this->indexDefStmt($indexStruct);
    $this->execChange($stmt);

    // update current db struct array
    $indexKey = strtolower($indexStruct["INDEX_META"]["INDEX_NAME"]);
    $this->curDbStruct["TABLES"][strtolower($tableName)][$indexKey] = $indexStruct;
  }  // eo add index


  /**
  * Add a foreign key to a table.
  * @param $fkStruct Array with the foreign key structure.
  */
  public function addTableForeignKey($fkStruct) {

    $this->preProcessCheck();
    $tableName = $fkStruct["FOREIGN_KEY_META"]["TABLE_NAME"];
    $tableNameQ = $this->quoteName($tableName);

    $stmt = "ALTER TABLE $tableName ADD " . $this->foreignKeyDefStmt($fkStruct, $opts);
    $this->execChange($stmt);

    // update current db struct array
    $fkKey = strtolower($fkStruct["FOREIGN_KEY_META"]["FOREIGN_KEY_NAME"]);
    $this->curDbStruct["TABLES"][strtolower($tableName)][$fkKey] = $fkStruct;
  }  // eo add foreign key


  /**
  * Add a view to the current database structure.
  * @param $viewStruct Array with the view structure.
  */
  public function addView($viewStruct) {

    $this->preProcessCheck();

    $viewMeta = $viewStruct["VIEW_META"];
    $viewName = $viewMeta["TABLE_NAME"];
    $viewNameQ = $this->quoteName($viewName);

    $stmt = "CREATE VIEW $viewNameQ AS {$viewStruct['DEFINITION']}";
    $this->execChange($stmt);

    $this->curDbStruct["VIEWS"][strtolower($viewName)] = $viewStruct;
  }  // eo add view





  // ##############################################
  // REFRESH STRUCTURE
  // ##############################################

  /**
  * Refresh existing tables, columns, indices and foreign keys.
  * @see OgerDbStruct::refreshDbStruct().
  */
  public function refreshDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    // refresh current table if exits
    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $this->curDbStruct["TABLES"][$refTableKey];
      if ($curTableStruct) {
        $this->refreshTableCore($refTableStruct);
        $this->refreshTableColumns($refTableStruct);
        $this->refreshTableIndices($refTableStruct);
      }
    }  // eo table loop

    // refresh foreign keys after all tables, columns and indices has been refreshed
    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $this->curDbStruct["TABLES"][$refTableKey];
      if ($curTableStruct) {
        $this->refreshTableForeignKeys($refTableStruct);
      }
    }  // eo refresh foreign keys


    // view loop
    foreach ((array)$this->refDbStruct["VIEWS"] as $refViewKey => $refViewStruct) {
      $curViewStruct = $this->curDbStruct["VIEWS"][$refViewKey];
      if ($curViewStruct) {
        $this->refreshView($refViewStruct);
      }
    }  // eo view loop

  }  // eo refresh struc



  /**
  * Refresh an existing table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTable($refTableStruct) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($refTableStruct["TABLE_META"]["TABLE_NAME"])];

    $this->refreshTableCore($refTableStruct);
    $this->refreshTableColumns($refTableStruct);
    $this->refreshTableIndices($refTableStruct);
    $this->refreshTableForeignKeys($refTableStruct);
  }  // eo refresh table


  /**
  * Refresh an existing table. Meta info and defaults.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTableCore($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);

    $tableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($tableName)];

    $refTableMeta = $refTableStruct["TABLE_META"];
    $curTableMeta = $curTableStruct["TABLE_META"];
    if ($refTableMeta["TABLE_COLLATION"] != $curTableMeta["TABLE_COLLATION"] ||
        $refTableMeta["ENGINE"] != $curTableMeta["ENGINE"]) {

      $stmt .= "ALTER TABLE " .
               $this->quoteName($tableName) .
               " ENGINE=" . $refTableMeta["ENGINE"] .
               " DEFAULT" .
               " COLLATE=" . $refTableMeta["TABLE_COLLATION"];
      $this->execChange($stmt);

      // update current db struct array
      $this->curDbStruct["TABLES"][strtolower($tableName)]["TABLE_META"] = $refTableMeta;

    }  // eo table meta

  }  // eo refresh table core


  /**
  * Refresh columns of an existing table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTableColumns($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);

    $tableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($tableName)];

    foreach ((array)$refTableStruct["COLUMNS"] as $refColumnKey => $refColumnStruct) {
      if ($curTableStruct["COLUMNS"][$refColumnKey]) {
        $this->refreshTableColumn($refColumnStruct);
      }
    }

  }  // eo refresh table columns


  /**
  * Refresh indices of an existing table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTableIndices($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);

    $tableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($tableName)];

    foreach ((array)$refTableStruct["INDICES"] as $refIndexKey => $refIndexStruct) {
      if ($curTableStruct["INDICES"][$refIndexKey]) {
        $this->refreshTableIndex($refIndexStruct);
      }
    }

  }  // eo refresh table indices


  /**
  * Refresh foreign keys of an existing table.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function refreshTableForeignKeys($refTableStruct = null) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleTableCase($refTableStruct);

    $tableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $curTableStruct = $this->curDbStruct["TABLES"][strtolower($tableName)];

    foreach ((array)$refTableStruct["FOREIGN_KEYS"] as $refFkKey => $refFkStruct) {
      if ($curTableStruct["FOREIGN_KEYS"][$refFkKey]) {
        $this->refreshTableForeignKey($refFkStruct);
      }
    }

  }  // eo refresh table foreign keys


  /**
  * Refresh an existing table column.
  * Direct use with care. Better use refreshTable().
  * @param $refColumnStruct Array with the reference column structure.
  */
  public function refreshTableColumn($refColumnStruct) {

    $tableName = $refColumnStruct["TABLE_NAME"];
    $columnName = $refColumnStruct["COLUMN_NAME"];

    $curColumnStruct = $this->curDbStruct["TABLES"][strtolower($tableName)]
                                         ["COLUMNS"][strtolower($columnName)];

    $refColumnSql = $this->columnDefStmt($refColumnStruct);
    $curColumnSql = $this->columnDefStmt($curColumnStruct);

    if ($refColumnSql != $curColumnSql) {

      $tableNameQ = $this->quoteName($tableName);
      $curColumnName = $this->quoteName($columnName);

      $this->log(static::LOG_DEBUG, "-- Old: $curColumnSql\n" .
                                    "-- New: $refColumnSql\n");

      // TODO: include [ AFTER ... | FIRST ] position here?
      $stmt = "ALTER TABLE {$tableNameQ} CHANGE COLUMN $curColumnName $refColumnSql";
      $this->execChange($stmt);

      // preserve ordinal position and update current db struct array
      $refColumnStruct["ORDINAL_POSITION"] = $curColumnStruct["ORDINAL_POSITION"];
      $this->curDbStruct["TABLES"][strtolower($tableName)]
                        ["COLUMNS"][strtolower($columnName)] = $refColumnStruct;

    }  // eo something changed
  }  // eo update column


  /**
  * Refresh an existing table index.
  * @param $refIndexStruct Array with the reference index structure.
  */
  public function refreshTableIndex($refIndexStruct) {

    $tableName = $refIndexStruct["INDEX_META"]["TABLE_NAME"];
    $indexName = $refIndexStruct["INDEX_META"]["INDEX_NAME"];

    $curIndexStruct = $this->curDbStruct["TABLES"][strtolower($tableName)]
                      ["INDICES"][strtolower($indexName)];

    $refIndexSql = $this->indexDefStmt($refIndexStruct);
    $curIndexSql = $this->indexDefStmt($curIndexStruct);

    if ($refIndexSql != $curIndexSql) {

      $tableNameQ = $this->quoteName($tableName);
      $curIndexName = $this->quoteName($indexName);

      $this->log(static::LOG_DEBUG, "-- Old: $curIndexSql\n" .
                                    "-- New: $refIndexSql\n");

      $stmt = "ALTER TABLE $tableNameQ DROP INDEX $curIndexName;" .
              "ALTER TABLE $tableNameQ ADD $refIndexSql";
      $this->execChange($stmt);

      // update current db struct array
      $this->curDbStruct["TABLES"][strtolower($tableName)]
                        ["INDICES"][strtolower($indexName)] = $refIndexStruct;

    }  // eo something changed
  }  // eo update index


  /**
  * Refresh an existing foreign key.
  * @param $refFkStruct Array with the reference foreign key structure.
  */
  public function refreshTableForeignKey($refFkStruct) {

    $tableName = $refFkStruct["FOREIGN_KEY_META"]["TABLE_NAME"];
    $fkName = $refFkStruct["FOREIGN_KEY_META"]["FOREIGN_KEY_NAME"];

    $curFkStruct = $this->curDbStruct["TABLES"][strtolower($tableName)]
                      ["FOREIGN_KEYS"][strtolower($fkName)];

    $refFkSql = $this->foreignKeyDefStmt($refFkStruct);
    $curFkSql = $this->foreignKeyDefStmt($curFkStruct);

    if ($refFkSql != $curFkSql) {

      $tableNameQ = $this->quoteName($tableName);
      $curFkName = $this->quoteName($fkName);

      $this->log(static::LOG_DEBUG, "-- Old: $curFkSql\n" .
                                    "-- New: $refFkSql\n");

      $stmt = "ALTER TABLE $tableNameQ DROP FOREIGN KEY $curFkName;" .
              "ALTER TABLE $tableNameQ ADD $refFkSql";
      $this->execChange($stmt);

      // update current db struct array
      $this->curDbStruct["TABLES"][strtolower($tableName)]
                        ["FOREIGN_KEYS"][strtolower($fkName)] = $refFkStruct;

    }  // eo something changed
  }  // eo update foreign key


  /**
  * Refresh an existing view.
  * @param $refViewStruct Array with the reference view structure.
  */
  public function refreshView($refViewStruct) {

    $this->preProcessCheck();

    // rename lettercase
    $this->handleViewCase($refViewStruct);

    $viewName = $refViewStruct["VIEW_META"]["TABLE_NAME"];
    $viewKey = strtolower($viewName);
    $curViewStruct = $this->curDbStruct["VIEWS"][$viewKey];

    // simply drop and create new
    if ($curViewStruct['DEFINITION'] != $refViewStruct['DEFINITION']) {
/*echo "\n\n--- current:\n";
var_export($curViewStruct['DEFINITION']);

echo "\n\n--- reference:\n";
var_export($refViewStruct['DEFINITION']);

echo "\n\n--- end\n";
throw new Exception("woher?");
*/
      $stmt = "DROP VIEW " . $this->quoteName($viewName);
      $this->execChange($stmt);
      unset($this->curDbStruct["VIEWS"][$viewKey]);

      $this->addView($refViewStruct);
    }
  }  // eo refresh view



  // ##############################################
  // UPDATE STRUCTURE (ADD + REFRESH)
  // ##############################################


  /**
  * Update existing tables, columns, indices or foreign keys and add missing ones.
  * @see OgerDbStruct::updateDbStruct().
  */
  public function updateDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {

      // add missing tables, columns and indices
      $refTableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
      $curTableStruct = $this->curDbStruct["TABLES"][$refTableKey];
      if (!$curTableStruct) {
        $this->addTableCore($refTableStruct);
      }
      else {
        // add mising table elements
        $this->addTableColumns($refTableStruct);
        $this->addTableIndices($refTableStruct);
        // refresh existing tables, columns and indices
        $this->refreshTableCore($refTableStruct);
        $this->refreshTableColumns($refTableStruct);
        $this->refreshTableIndices($refTableStruct);
      }
    }  // eo table loop

    // refresh existing foreign keys and add missing ones
    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {
      $this->refreshTableForeignKeys($refTableStruct);
      $this->addTableForeignKeys($refTableStruct);
    }  // eo refresh foreign keys


    // view loop
    foreach ((array)$this->refDbStruct["VIEWS"] as $refViewKey => $refViewStruct) {
      $curViewStruct = $this->curDbStruct["VIEWS"][$refViewKey];
      if (!$curViewStruct) {
        $this->addView($refViewStruct);
      }
      else {
        $this->refreshView($refViewStruct);
      }
    }  // eo view loop

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
  public function reorderDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    foreach ((array)$this->refDbStruct["TABLES"] as $refTableKey => $refTableStruct) {
      $curTableStruct = $this->curDbStruct["TABLES"][$refTableKey];
      if ($curTableStruct) {
        $this->reorderTableColumns($refTableStruct);
      }
    }  // eo table loop

    // reorder views by simply refresh
    foreach ((array)$this->refDbStruct["VIEWS"] as $refViewKey => $refViewStruct) {
      $curViewStruct = $this->curDbStruct["VIEWS"][$refViewKey];
      if ($curViewStruct) {
        $this->refreshView($refViewStruct);
      }
    }  // eo view loop

  }  // eo order db struct


  /**
  * Reorder table columns.
  * @param $refTableStruct Array with the reference table structure.
  */
  public function reorderTableColumns($refTableStruct) {

    $this->preProcessCheck();

    $tableName = $refTableStruct["TABLE_META"]["TABLE_NAME"];
    $tableKey = strtolower($tableName);
    $tableNameQ = $this->quoteName($tableName);

    // rename lettercase
    $this->handleTableCase($refTableStruct);

    // sort reference columns by ordinal positions
    $this->asortColumnsByOrdinalPos($refTableStruct["COLUMNS"]);

    $afterColumnName = -1;
    $afterColPos = 0;            // ordinal pos starts at 1
    foreach ((array)$refTableStruct["COLUMNS"] as $refColKey => $refColumnStruct) {

      $curColumnStruct = $this->curDbStruct["TABLES"][$tableKey]["COLUMNS"][$refColKey];

      // skip columns that does not exist in the current table
      if (!$curColumnStruct) {
        continue;
      }

      $colName = $curColumnStruct["COLUMN_NAME"];
      $newColPos = $afterColPos + 1;
      $oldColPos = $curColumnStruct["ORDINAL_POSITION"];

      if ($oldColPos != $newColPos) {
        // use current column structure because we dont want to change the column definition but only the order
        $columnDef = $this->columnDefStmt($curColumnStruct, $afterColumnName);
        $colNameQ = $this->quoteName($colName);
        $this->log(static::LOG_DEBUG, "-- OldColumnPos: $oldColPos\n" .
                                      "-- NewColumnPos: $newColPos\n");
        $stmt = "ALTER TABLE $tableNameQ CHANGE COLUMN $colNameQ $columnDef";
        $this->execChange($stmt);

        // update current db struct array
        foreach ($this->curDbStruct["TABLES"][$tableKey]["COLUMNS"] as &$tmpCol) {
          if ($tmpCol["ORDINAL_POSITION"] >= $newColPos &&
              $tmpCol["ORDINAL_POSITION"] < $oldColPos) {
            $tmpCol["ORDINAL_POSITION"]++;
          }
        }
        $this->curDbStruct["TABLES"][$tableKey]["COLUMNS"][$refColKey]["ORDINAL_POSITION"] = $newColPos;
      }  // eo change order

      $afterColumnName = $colName;
      $afterColPos++;

      // remove the processed column name from the unused current column names
      unset($curColNames[$colKey]);
    }  // eo common column loop

    // reorder column struct array - may be conditional only if changes happened?
    $this->asortColumnsByOrdinalPos($this->curDbStruct["TABLES"][$tableKey]["COLUMNS"]);

  }  // eo order table columns



  // ##############################################
  // CLEANUP STRUCTURE
  // ##############################################

  /**
  * Cleanup surpluss tables, columns, indices and foreign keys.
  * @see OgerDbStruct::cleanupDbStruct().
  */
  public function cleanupDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    // view loop
    foreach ((array)$this->curDbStruct["VIEWS"] as $curViewKey => $curViewStruct) {
      $refViewStruct = $this->refDbStruct["VIEWS"][$curViewKey];
      if (!$refViewStruct) {
        $curViewName = $curViewStruct['VIEW_META']['TABLE_NAME'];
        $stmt = "DROP VIEW " . $this->quoteName($curViewName);
        $this->execChange($stmt);
        unset($this->curDbStruct["VIEWS"][$curViewKey]);
      }
    }  // eo view loop

    // first cleanup foreign keys before we remove tables, columns or indices
    // and as sideeffect handle table lettercase
    foreach ((array)$this->curDbStruct["TABLES"] as $curTableKey => $curTableStruct) {

      $refTableStruct = $this->refDbStruct["TABLES"][$curTableKey];
      if (!$refTableStruct) {
        continue;
      }

      // rename lettercase
      $this->handleTableCase($refTableStruct);
      $tableNameQ = $this->quoteName($curTableStruct["TABLE_META"]["TABLE_NAME"]);

      foreach ((array)$curTableStruct["FOREIGN_KEYS"] as $fkKey => $fkStruct) {
        if (!$refTableStruct["FOREIGN_KEYS"][$fkKey]) {
          $fkName = $this->quoteName($fkStruct["FOREIGN_KEY_META"]["FOREIGN_KEY_NAME"]);
          $stmt = "ALTER TABLE {$tableName} DROP CONSTRAINT {$fkName}";
          $this->execChange($stmt);

          // update current db struct array
          unset($this->curDbStruct["TABLES"][$curTableKey]["FOREIGN_KEYS"][$fkKey]);
        }
      }
    }  // table loop for foreign keys

    // cleanup tables, indices and columns
    foreach ((array)$this->curDbStruct["TABLES"] as $curTableKey => $curTableStruct) {

      $refTableStruct = $this->refDbStruct["TABLES"][$curTableKey];
      $tableNameQ = $this->quoteName($curTableStruct["TABLE_META"]["TABLE_NAME"]);

      if (!$refTableStruct) {
        $stmt = "DROP TABLE {$tableNameQ}";
        $this->execChange($stmt);
        // update current db struct array
        unset($this->curDbStruct["TABLES"][$curTableKey]);
      }

      else {

        // cleanup indices
        foreach ((array)$curTableStruct["INDICES"] as $curIndexKey => $curIndexStruct) {
          if (!$refTableStruct["INDICES"][$curIndexKey]) {
            $indexName = $this->quoteName($curIndexStruct["INDEX_META"]["INDEX_NAME"]);
            $stmt = "ALTER TABLE {$tableNameQ} DROP INDEX {$indexName}";
            $this->execChange($stmt);
            // update current db struct array
            unset($this->curDbStruct["TABLES"][$curTableKey]["INDICES"][$curIndexKey]);
          }
        }

        // cleanup columns
        foreach ((array)$curTableStruct["COLUMNS"] as $curColumnKey => $curColumnStruct) {
          if (!$refTableStruct["COLUMNS"][$curColumnKey]) {
            $columnName = $this->quoteName($curColumnStruct["COLUMN_NAME"]);
            $stmt = "ALTER TABLE {$tableNameQ} DROP COLUMN {$columnName}";
            $this->execChange($stmt);

            // update current db struct array
            foreach ($this->curDbStruct["TABLES"][$curTableKey]["COLUMNS"] as &$tmpCol) {
              if ($tmpCol["ORDINAL_POSITION"] > $curColumnStruct["ORDINAL_POSITION"]) {
                $tmpCol["ORDINAL_POSITION"]--;
              }
            }
            unset($this->curDbStruct["TABLES"][$curTableKey]["COLUMNS"][$curColumnKey]);
          }
        }

      }  // eo existing table

    }  // eo table loop

 }  // eo order db struct




  // ##############################################
  // FORCE STRUCTURE (ADD + REFRESH + REORDER + CLEANUP)
  // ##############################################

  /**
  * Force database structure.
  * @see OgerDbStruct::forceDbStruct().
  */
  public function forceDbStruct($refDbStruct = null) {

    $this->preProcessCheck($refDbStruct);

    $this->cleanupDbStruct(null);
    $this->updateDbStruct(null);
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
        if ($key == "TABLE_META" ||
            $key == "INDEX_META" ||
            $key == "FOREIGN_KEY_META") {
          $nextSingleLine = 1;
        }
        if ($key == "COLUMNS" ||
            $key == "INDEX_COLUMNS" ||
            $key == "FOREIGN_KEY_COLUMNS") {
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

    // call guard
    if ($this->reverseMode) {
      throw new Exception("Already in reverse mode.");
    }

    $this->log(static::LOG_NOTICE, "-- Start reverse mode: Will read current database structure and use as refence structure.\n");
    $this->refDbStruct = $this->getDbStruct();

    $this->initialRefDbStruct = $refDbStruct;
    $this->curDbStruct = $refDbStruct;

    $this->setParam("dry-run", true);

    $this->reverseMode = true;
  }  // eo start reverse  mode



  // ##############################################
  // SQL DEFINITION STATEMENTS
  // ##############################################

 /**
  * Create sql statement for a column definition.
  * @param $columnStruct  Array with column structure.
  * @param $afterColumnName: The column name after which this column should be placed.
  *        Empty means append after the last column.
  *        -1 means insert on first position.
  * @return The SQL statement part for a column definition.
  */
  public function columnDefStmt($columnStruct, $afterColumnName = null) {

    $stmt = $this->quoteName($columnStruct["COLUMN_NAME"]) .
            " " . $columnStruct["COLUMN_TYPE"] .
            ($columnStruct["COLLATION_NAME"] ? " COLLATE {$columnStruct["COLLATION_NAME"]}" : "") .
            ($columnStruct["IS_NULLABLE"] == "NO" ? " NOT NULL" : "") .
            ($columnStruct["EXTRA"] ? " {$columnStruct["EXTRA"]}" : "");

    // create column default
    // if column is not nullable but default value is NULL (or unset) then
    // do not generate a default value and let the database decide what to do
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

    // if afterColumnName is empty we do nothing (that means the field is appended without position)
    if ($afterColumnName) {
      // -1 result in inserting on first position
      if ($afterColumnName == -1) {
        $stmt .= " FIRST";
      }
      else {
        $stmt .= " AFTER " . $this->quoteName($afterColumnName);
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

    $indexName = " " . $this->quoteName($indexStruct["INDEX_META"]["INDEX_NAME"]);

    // the primary key has no separate name
    if ($indexStruct["INDEX_META"]["INDEX_KEY_TYPE"] == "PRIMARY") {
      $indexName = "";
    }

    $indexKeyType = $indexStruct["INDEX_META"]["INDEX_KEY_TYPE"];
    if ($indexKeyType) {
      $indexKeyType .= " ";
    }

    $stmt .= "{$indexKeyType}KEY{$indexName}";

    // force order of columns and extract names
    $this->orderIndexStructColumns($indexStruct["INDEX_COLUMNS"]);
    $colNames = array();
    foreach ((array)$indexStruct["INDEX_COLUMNS"] as $indexColumnStruct) {
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

    $fkName = $this->quoteName($fkStruct["FOREIGN_KEY_META"]["FOREIGN_KEY_NAME"]);

    $stmt = "CONSTRAINT $fkName";

    // force order of columns and extract names
    // we assume that the column order in the reference is the same as in the foreign key
    $this->orderForeignKeyStructColumns($fkStruct["FOREIGN_KEY_COLUMNS"]);
    $colNames = array();
    $colNamesRef = array();
    foreach ((array)$fkStruct["FOREIGN_KEY_COLUMNS"] as $fkColumnStruct) {
      $colNames[] = $this->quoteName($fkColumnStruct["COLUMN_NAME"]);
      $colNamesRef[] = $this->quoteName($fkColumnStruct["REFERENCED_COLUMN_NAME"]);
    }

    // put fields and reference to statement
    $fkMeta = $fkStruct["FOREIGN_KEY_META"];
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
  private function asortColumnsByOrdinalPos(&$columns) {

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



  /**
   * Reload the internal current database struct info.
   * Allow reload of internal current structure for external user.
   */
   /*
  public function reloadCurDbStruct($opts = array()) {
    $this->curDbStruct = $this->getDbStruct($opts);
    return $this->curDbStruct;
  }  // eo invalidate current dbstruct
  */





}  // eo mysql struct class






?>
