<?php
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Handle database structure for mysql databases.
* @see class OgerDbStruct.
* Charset and Collation are only used when creating tables.
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

    $stmt = $this->quoteName($columnDef["COLUMN_NAME"]) . " " .
            $columnDef["COLUMN_TYPE"] . " " .
            ($columnDef["COLLATION_NAME"] ? "COLLATE {$columnDef["COLLATION_NAME"]} " : "") .
            ($columnDef["IS_NULLABLE"] == "NO" ? "NOT NULL " : "");

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

      $stmt .= "DEFAULT $default";
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

  }  // eo add column








// #################################################






















  /**
  * check db structure and update if necessary
  * INFO: This method adds new tables to databases,
  *       adds new columns to tables and
  *       changes existing column defs and
  *       adds new indices to tables.
  * @opts: Option array. Possible values are:
  *        - logOnly
  */
  public static function checkDbStruc($dbDef, $opts = array()) {

    // building dbStruc data file from current structure to be included
    // for later checks against this file
    // In this case we do a log only to avoid accidentically reverse update
    if ($opts['buildDbInfo']) {
      $opts['logOnly'] = true;
    }

    $log = '';

    // detect if structure changed
    $updDbStruc = false;

    // if forced we do not need more checks
    if ($dbDef['forceDbStrucUpdate']) {
      $updDbStruc = true;
      $log .= "Db structure update is forced by config.\n";
    }

    // include new structure (and new db struc serial) for check of db serial number
    require('dbStruc.data.inc.php');
    self::$newDbStrucSerial = $newDbStrucSerial;
    self::$newDbStruc = $newDbStruc;

    // Check if db struc update table is present and has the needed field names
    // otherwise the command will fail.
    // It will succedd on windows if the tablename differ only in case sensity,
    // so whe do NOT have to check the table name with uppercase/lowercase.
    if (!$updDbStruc) {
      self::$oldDbStrucSerial = self::getCurrentDbStrucSerial();
      if (self::$oldDbStrucSerial < 0) {   // -1 = query failed
        $updDbStruc = true;
        $log .= "Expected table '" . DbStrucUpdate::$tableName . "' does not exist. Database need update.\n";
      }
      // command did not fail, so check if the serial number is up to date.
      elseif (self::$oldDbStrucSerial < self::$newDbStrucSerial) {
        $updDbStruc = true;
        $log .= "DbStrucSerial is not up to date. Has " . self::$oldDbStrucSerial . " but needs " . self::$newDbStrucSerial . ".\n";
      }
    }  // check db struc update table


    // if logonly is given than fake update dbstruc to get log info
    if ($opts['buildDbInfo'] ) {
      $updDbStruc = true;
    }

    // if no update is neccessary return here
    if (!$updDbStruc) {
      return true;
    }


    ###########################
    # UPDATE is required
    ###########################


    // get current old db structure
    $log .= "\nGet current structure for database DSN " . $dbDef['dbName'] . ".\n\n";
    self::$oldDbStruc = self::getStruc($dbDef['dbName']);


    self::$memoDbDef = $dbDef;

    // preprocess update
    if (!$opts['logOnly']) {
      $log .= self::updDbStrucPreprocess();
    }

    // reverse for building dbStruc data file from current structure to be included
    // for later checks against this file
    if ($opts['buildDbInfo']) {
      $tmp = self::$newDbStruc;
      self::$newDbStruc = self::$oldDbStruc;
      self::$oldDbStruc = $tmp;
      self::$oldDbStrucSerial = self::$newDbStrucSerial;
      self::$newDbStrucSerial = 'NOW';
      $tmp = null;
    }

    // apply new database structure
    foreach(self::$newDbStruc as $newTableName => $newTableDef) {

      // TODO make dependend of plattform
      // maybe PHP_OS will do ????
      // try to detect old table name on case insensitve plattforms like windows
      // for exampel: table names are lowercase in mysql for windows
      $oldTableName = $newTableName;
      if (self::$oldDbStruc[$newTableName]) {
        // table already has a table with this name - everything ok
      }
      elseif (self::$oldDbStruc[strtoupper($newTableName)]) {
        // table already has a table with this name - but old tablename is uppercase
        $oldTableName = strtoupper($newTableName);
        $log .= "Tablename $newTableName found as $oldTableName.\n";
      }
      elseif (self::$oldDbStruc[strtolower($newTableName)]) {
        // table already has a table with this name - but old tablename is lowercase
        $oldTableName = strtolower($newTableName);
        $log .= "Tablename $newTableName found as $oldTableName.\n";
      }
      // on linux try to rename to camelcase
      // this is only done if new (camelcase) table does not exist
      // so we relay on this not existing and do not check hard
      if ($oldTableName != $newTableName) {
        if (strtoupper(PHP_OS) == "LINUX") {
          //try {
            $log .= "Rename table $oldTableName to $newTableName.\n";
            if (!$opts['logOnly']) {
              $pstmt = Db::prepare("RENAME TABLE $oldTableName TO $newTableName");
              $pstmt->execute();
            }
            // adjust old db struc array if rename is successful
            self::$oldDbStruc[$newTableName] = self::$oldDbStruc[$oldTableName];
            unset(self::$oldDbStruc[$oldTableName]);
            $oldTableName = $newTableName;
          //}
          //catch (Exception $ex) {
          //  $log .= $ex->getMessage() . "\n";
          //}
        }  // eo rename to camelcase
      }  // eo tablename changed

      // add new tables.
      if (!self::$oldDbStruc[$oldTableName]) {
        //try {
          $log .= "\nAdd table $newTableName.\n";
          if (!$opts['logOnly']) {
            $pstmt = Db::prepare(self::createAddTableStmt($newTableDef));
            $pstmt->execute();
          }
        //}
        //catch (Exception $ex) {
        //  $log .= $ex->getMessage() . "\n";
        //}
      }  // eo add missing tables

      // add new columns to existing tables or alter existing ones
      else {

        if (!$opts['logOnly']) {
          $log .= "\n* Check definition for table $newTableName.\n";
        }
        $oldColumnDefs = self::$oldDbStruc[$oldTableName]['columns'];
        $newColumnDefs = $newTableDef['columns'];


        $afterColumnName = '';

        // loop over colunms
        foreach ($newColumnDefs as $newColumnName => $newColumnDef) {

          // we assume that the column is present
          // this variable also is used to differ between add and rename
          $oldColumnName = $newColumnName;

          // new column - add or rename unused
          // Column names are case sensitive (at least in mysql - also in windows version)
          if (!$oldColumnDefs[$newColumnName]) {

            // if we add a new column there is no old column name
            // except if we use the rename-mode - see later
            $oldColumnName = '';

            // detect if in the old table def there is a column at the current ordinal position
            $oldColumnDef = null;
            foreach ($oldColumnDefs as $tmpOldColumnName => $tmpOldColumnDef) {
              if ($tmpOldColumnDef['ORDINAL_POSITION'] == $newColumnDef['ORDINAL_POSITION']) {
                $oldColumnDef = $tmpOldColumnDef;
                break;
              }
            }

            // if old column is present at the current position
            if ($oldColumnDef) {
              // check the old column at the current position is "unused" in new table definition
              if (!$newColumnDefs[$oldColumnDef['COLUMN_NAME']]) {
                // if rename of column is prefered over adding an additional column
                if ($dbDef['addColumnPrefereRename']) {
                  $oldColumnName = $oldColumnDef['COLUMN_NAME'];
                  $log .= "Rename column $oldColumnName to $newColumnName on table $newTableName.\n";
                  // adjust old column defs to the new column name
                  $oldColumnDef['COLUMN_NAME'] = $newColumnName;
                  $oldColumnDefs[$newColumnName] = $oldColumnDef;
                  unset($oldColumnDefs[$oldColumnName]);
                }
                // otherwise move to the last position
                else {
                  $tmpOldColumnName = $oldColumnDef['COLUMN_NAME'];
                  $topOldColumnDef = end($oldColumnDefs);
                  $topOldColumnName = $topOldColumnDef['COLUMN_NAME'];
                  $tmpOldRelative = ($afterColumnName ? "AFTER $afterColumnName" : "FIRST");
                  if ($tmpOldColumnName != $topOldColumnName) {
                    $log .= "Move unused column $tmpOldColumnName from $tmpOldRelative to LAST (AFTER $topOldColumnName)" .
                            " on table $newTableName to free the place for $newColumnName.\n";
                    if (!$opts['logOnly']) {
                      $tmpOldColumnDefStmt = self::createColumnDefStmt($oldColumnDef);
                      $pstmt = Db::prepare("ALTER TABLE $newTableName CHANGE COLUMN $tmpOldColumnName $tmpOldColumnDefStmt" .
                                           " AFTER $topOldColumnName");
                      $pstmt->execute();
                    }
                    // adjust old column defs to the changed position
                    unset($oldColumnDefs[$tmpOldColumnName]);
                    $oldColumnDef['ORDINAL_POSITION'] = (string)($topOldColumnDef['ORDINAL_POSITION'] + 1);
                    $oldColumnDefs = self::updColumnDefsArray($oldColumnDefs, $oldColumnDef, $newTableName);
                  }
                }
              }
            }  // eo handle possible column rename
          }  // eo detect and prepare missing columns


          // check existing columns - alter if needed
          // ATTENTION: highly adapted to mysql, so may be do not work with other databases!!!
          // COLUMN_TYPE is sysl-specific and ALTER TABLE CHANGE COLUMN too.

          $relativePosition = ($afterColumnName ? "AFTER $afterColumnName" : "FIRST");

          // if no old column name is present we have to add a new column
          // ATTENTION: The old column defination defs are already adjusted to the new column name !!!
          if (!$oldColumnName) {
            $log .= "Add column $newColumnName to table $newTableName at position $relativePosition.\n";
            if (!$opts['logOnly']) {
              $pstmt = Db::prepare(self::createAddColumnStmt($newTableName, $newColumnDef) . " $relativePosition");
              $pstmt->execute();
            }
            // update old column definitions to reflect database update
            $oldColumnDefs = self::updColumnDefsArray($oldColumnDefs, $newColumnDef, $newTableName);
          }   // eo add new column

          else {  // handle column def change, rename and reorder

            $oldColumnDef = $oldColumnDefs[$newColumnName];

            if ($newColumnDef['COLUMN_TYPE'] != $oldColumnDef['COLUMN_TYPE'] ||
                $newColumnDef['COLUMN_DEFAULT'] != $oldColumnDef['COLUMN_DEFAULT'] ||
                $newColumnDef['IS_NULLABLE'] != $oldColumnDef['IS_NULLABLE'] ||
                $newColumnDef['ORDINAL_POSITION'] != $oldColumnDef['ORDINAL_POSITION'] ||
                $newColumnDef['COLUMN_NAME'] != $oldColumnName) {

              // old column name is already changed on old column def
              // so we have to change back on temp def for log message
              $tmpOldColumnDef = $oldColumnDef;
              $tmpOldColumnDef['COLUMN_NAME'] = $oldColumnName;
              $oldColumnDefStmt = self::createColumnDefStmt($tmpOldColumnDef);
              $newColumnDefStmt = self::createColumnDefStmt($newColumnDef);

              if ($newColumnDefStmt == $oldColumnDefStmt) {
                $log .= "Move column $oldColumnName on table $newTableName to position $relativePosition.\n";
              }
              else {
                $log .= "Change column on table $newTableName from [$oldColumnDefStmt] " .
                        "to [$newColumnDefStmt] at/to position $relativePosition.\n";
              }

              $oldColumnDefs = self::updColumnDefsArray($oldColumnDefs, $newColumnDef, $newTableName);

              if (!$opts['logOnly']) {
                $stmt = "ALTER TABLE $newTableName CHANGE COLUMN $oldColumnName $newColumnDefStmt $relativePosition";
                //$log .= "$stmt\n";
                $pstmt = Db::prepare($stmt);
                $pstmt->execute();
              }

            }  // eo alter column

          }  // eo check existing columns

          $afterColumnName = $newColumnName;
        }  // eo new column def list

        // report unused columns
        if (!$opts['logOnly']) {
          $log .= "- Check for unused columns in table $newTableName.\n";
        }
        foreach ($oldColumnDefs as $oldColumnName => $oldColumnDef) {
          if (!$newColumnDefs[$oldColumnName]) {
            $log .= "Unused column $oldColumnName on table $newTableName.\n";
          }
        }  // eo report unused fields

      }  // eo column check on existing tables


      // check table keys AFTER adding all columns
      // because create table does NOT all add all keys.
      $oldKeyDefs = (self::$oldDbStruc[$oldTableName]['keys'] ?: array());
      $newKeyDefs = ($newTableDef['keys'] ?: array());

      foreach ($newKeyDefs as $newKeyName => $newKeyOrderDef) {

        // sort old and new keyOrderDef. Key = SEQ_IN_INDEX field
        $oldKeyOrderDef = ($oldKeyDefs[$newKeyName] ?: array());
        ksort($oldKeyOrderDef, SORT_NUMERIC);
        ksort($newKeyOrderDef, SORT_NUMERIC);

        // check if keys have changed and drop changed keys
        // the new keys are added later if detected that they are missing
        // - first check that all new fields are in the old key field array
        $changedIndex = false;
        if (count($oldKeyOrderDef) != count($newKeyOrderDef)) {
          $changedIndex = true;
        }
        else {
          foreach ($newKeyOrderDef as $ordinal => $newKeyFieldDef) {
            if ($oldKeyOrderDef[$ordinal]['COLUMN_NAME'] != $newKeyFieldDef['COLUMN_NAME'] ||
                $oldKeyOrderDef[$ordinal]['NON_UNIQUE'] != $newKeyFieldDef['NON_UNIQUE']) {
              $changedIndex = true;
              break;
            }
          }
        }

        // remove old index if exists and not equal
        if ($changedIndex && $oldKeyOrderDef) {
          $log .= "Drop key $newKeyName from table $newTableName.\n";
          $stmt = "ALTER TABLE $newTableName DROP " . ($newKeyName == 'PRIMARY' ? 'PRIMARY KEY' : "KEY $newKeyName");
          //try {
            if (!$opts['logOnly']) {
              $pstmt = Db::prepare($stmt);
              $pstmt->execute();
            }
          //}
          //catch (Exception $ex) {
          //  $log .= $ex->getMessage() . "\n";
          //}

        }  // eo add missing keys

        // add new (or changed) keys
        if (!$oldKeyDefs[$newKeyName] || $changedIndex) {
          $log .= "Add key $newKeyName to table $newTableName.\n";
          $newKeyFields = '';
          $newKeyCmdExt = '';
          foreach ($newKeyOrderDef as $newKeyFieldDef) {
            $newKeyFields .= ($newKeyFields ? ', ' : '') . $newKeyFieldDef['COLUMN_NAME'];
            if (!$newKeyFieldDef['NON_UNIQUE']) {
              $newKeyCmdExt = 'UNIQUE';
            }
          }
          if ($newKeyName == 'PRIMARY') {
            $stmt = "ALTER TABLE $newTableName ADD PRIMARY KEY($newKeyFields)";
          }
          else {
            $stmt = "CREATE $newKeyCmdExt INDEX $newKeyName ON $newTableName ($newKeyFields)";
          }
          if (!$opts['logOnly']) {
            $pstmt = Db::prepare($stmt);
            $pstmt->execute();
          }

        }  // eo add missing keys
      }  // eo key check loop

    } // eo table list

    // report unused tables
    if (!$opts['logOnly']) {
      $log .= "\nCheck for unused tables.\n";
    }
    foreach(self::$oldDbStruc as $oldTableName => $oldTableDef) {
      // try to detect old table name on case insensitve plattforms like windows
      // for exampel: table names are lowercase in mysql for windows
      if (!self::$newDbStruc[$oldTableName] &&
          !self::$newDbStruc[strtoupper($oldTableName)] &&
          !self::$newDbStruc[strtolower($oldTableName)]) {
        $log .= "Unused table $oldTableName.\n";
      }
    }  // eo report unused tables


    // postprocess update
    if (!$opts['logOnly']) {
      $log .= self::updDbStrucPostprocess();
    }


    // update db struc update table with new dbStrucSerial and full log (including postprocess)
    if (!$opts['logOnly']) {
      $log .= "\nUpdate dbStrucSerial in " . DbStrucUpdate::$tableName . " to " . self::$newDbStrucSerial . ".\n";

      $pstmt = Db::prepare('INSERT INTO ' . DbStrucUpdate::$tableName .
                           ' (`serial`, `lastUpdate`, `log`)' .
                           ' VALUES (:serial, :lastUpdate, :log)');
      $pstmt->execute(array('serial' => self::$newDbStrucSerial,
                            'lastUpdate' => date('c'),
                            'log' => $log));
      $pstmt->closeCursor();
    }


    if ($opts['logOnly']) {
      return $log;
    }

  }  // eo check and update db struc





  /**
  * Create an add table statement.
  * ATTENTION: Does not add primary, unique or other keys -
  *            this is done later in an extra step.
  */
  public static function createAddTableStmt($tableDef) {

    $addKeys = false;  // for now

    $stmt = "CREATE TABLE `" . $tableDef['TABLE_NAME'] . "` (";
    $follow = false;
    $primaryKeyColumns = array();
    foreach ($tableDef['columns'] as $columnName => $columnDef) {
      if ($follow) {
        $stmt .= ", ";
      }
      $follow = true;
      $stmt .= self::createColumnDefStmt($columnDef);
      if ($columnDef['COLUMN_KEY'] == 'PRI') {
        $primaryKeyColumns[] = "`" . $columnDef['COLUMN_NAME'] . "`";
      }
    }  // eo column defs

    // handle primary indices
    /*
    EXAMPLE:
      CREATE TABLE IF NOT EXISTS `test` (
        `auto` int(11) NOT NULL AUTO_INCREMENT,
        `id` int(11) NOT NULL,
        `boolfield` tinyint(1) NOT NULL,
        `numberfield` int(11) NOT NULL,
        `textfield` text NOT NULL,
        PRIMARY KEY (`auto`),
        UNIQUE KEY `numberfield` (`numberfield`),
        KEY `id` (`id`)
      );
    */
    if ($primaryKeyColumns && $addKeys) {
      $stmt .= ', PRIMARY KEY (' . implode (', ', $primaryKeyColumns);
    }  // primary index

    $stmt .= ")";

    return $stmt;

  }  // eo create an add table statement


  /**
  * Create an column def statement for CREATE TABLE and ADD COLUMN.
  */
  public static function createColumnDefStmt($columnDef) {

    $stmt = "`" . $columnDef['COLUMN_NAME'] . "` " .
            $columnDef['COLUMN_TYPE'] .
            ($columnDef['IS_NULLABLE'] == 'YES' ? '' : ' NOT') . ' NULL' .
            (!is_null($columnDef['COLUMN_DEFAULT']) ? " DEFAULT '" . $columnDef['COLUMN_DEFAULT'] . "'" : '');

    return $stmt;

  }  // eo create column def statement


  /**
  * Create an add table statement.
  */
  public static function createAddColumnStmt($tableName, $columnDef) {

    $stmt = "ALTER TABLE `$tableName` ADD COLUMN " . self::createColumnDefStmt($columnDef);

    return $stmt;

  }  // eo create an add column statement




  /**
  * Update old column definitions.
  */
  public static function updColumnDefsArray($oldColumnDefs, $newColumnDef, $tableName) {

    // if the column name does not exist than fake existence by adding to array
    if (!$oldColumnDefs[$newColumnDef['COLUMN_NAME']]) {
      $oldColumnDefs[$newColumnDef['COLUMN_NAME']] = $newColumnDef;
    }

    $columnPos = 0;
    $updColumnDefs = array();
    foreach ($oldColumnDefs as $oldColumnName => $oldColumnDef) {
      $columnPos++;
      // insert new column def at whished position
      if ($columnPos == $newColumnDef['ORDINAL_POSITION']) {
        $updColumnDefs[$newColumnDef['COLUMN_NAME']] = $newColumnDef;
        $columnPos++;
        $isIncluded = true;
      }
      // ignore old column def with same name
      if ($oldColumnName == $newColumnDef['COLUMN_NAME']) {
        $columnPos--;
        continue;
      }
      $updColumnDefs[$oldColumnName] = $oldColumnDef;
      $updColumnDefs[$oldColumnName]['ORDINAL_POSITION'] = (string)$columnPos;
    }
    /*
    // add new column def if not included till now
    if (!$isIncluded) {
      $columnPos++;
      if ($columnPos != $newColumnDef['ORDINAL_POSITION']) {
        throw new Exception('ConfigDb::updColumnsDefsArray: Wrong ordinal position for field ' . $newColumnDef['COLUMN_NAME'] .
                            ' should be ' . $newColumnDef['ORDINAL_POSITION'] . " but is $columnPos.");
      }
      $updColumnDefs[$newColumnDef['COLUMN_NAME']] = $newColumnDef;
    }
    */

    return $updColumnDefs;
  }  // eo updateOldColumnDefs



  /**
  * Get current dbstruc serial
  */
  public static function getCurrentDbStrucSerial() {

    $serial = -1;  // this is the default if all failes

    try {
      $pstmt = Db::query('SELECT MAX(serial) FROM ' . DbStrucUpdate::$tableName);
      $serial = $pstmt->fetchColumn();
      $pstmt->closeCursor();
    }
    catch (Exception $ex) {
      // try again with lowercase for databases imported from windows mysql
      try {
        $pstmt = Db::query('SELECT MAX(serial) FROM ' . strtolower(DbStrucUpdate::$tableName));
        $serial = $pstmt->fetchColumn();
        $pstmt->closeCursor();
      }
      catch (Exception $ex) {
        // nothing to do: $serial = -1;
      }
    }

    return $serial;
  }  // eo get current db struc serrial



  /**
  * Preprocess for db struc update
  */
  public static function updDbStrucPreprocess() {
    $log = "\n*** Begin preprocess dbStruc change.\n";
    $fileName = 'dbStruc.preprocess.inc.php';
    if (file_exists($fileName)) {
      require($fileName);
    }
    else {
      $log .= "No file with preprocess instructions found.\n";
    }
    $log .= "*** End preprocess dbStruc change.\n";
    return $log;
  }

  /**
  * Postprocess for db struc update
  */
  public static function updDbStrucPostprocess() {
    $log = "\n*** Begin postprocess dbStruc change.\n";
    $fileName = 'dbStruc.postprocess.inc.php';
    if (file_exists($fileName)) {
      require($fileName);
    }
    else {
      $log .= "No file with postprocess instructions found.\n";
    }
    $log .= "*** End postprocess dbStruc change.\n";
    return $log;
  }



}  // eo mysql struct class






?>
