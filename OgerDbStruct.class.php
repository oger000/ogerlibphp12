<?php
/*
#LICENSE BEGIN
#LICENSE END
*/


/***
* Config database access, check, update DbStruc, etc
* Info: We do not catch db errors longer, because if there is an error
* in the db struc the app should not start at all
*/
class OgerDbStruct {

  public static $newDbStruc = array();
  public static $newDbStrucSerial;
  public static $oldDbStruc = array();
  public static $oldDbStrucSerial;

  public static $memoDbDef;  // mainly for pre- and postprocessing


  /**
  * init database settings
  */
  public static function initDb($dbDefAliasId, $finalCheck) {

    // init database connection
    $dbDef = Config::$dbDefs[$dbDefAliasId];
    Db::init($dbDef['dbName'], $dbDef['dbUser'], $dbDef['dbPass'], $dbDef['dbAttributes']);

    // handle final setting, do dbcheck only if final flag is set
    if ($finalCheck) {

      if (!$dbDef) {
        echo Extjs::unsuccessMsg(Oger::_('No database definition given or detected.'));
        exit;
      }

      try {
        $conn = Db::getConn();
      }
      catch (Exception $ex) {
        $conn = false;
      }
      if ($conn === false) {
        echo Extjs::unsuccessMsg(Oger::_('Kann Datenbankverbindung nicht herstellen.'));
        exit;
      }

      if (!$dbDef['skipDbStrucCheck'] || $dbDef['forceDbStrucUpdate']) {
        self::checkDbStruc($dbDef);
      }

    }  // eo handle final settings

  }  // eo init db




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
    self::$oldDbStruc = self::getDbStruc($dbDef['dbName']);


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
  * Get database structure (driver dependend).
  * ATTENTION: Be aware, that a db connection must be open when calling this method!
  */
  public static function getDbStruc($dsn) {

    $dbStruc = array();

    // get database name
    list($dbDriver, $driverSpecificPart) = explode(':', $dsn, 2);

    $tmpParts = explode(';', $driverSpecificPart);
    $dsnParts = array();
    foreach ($tmpParts as $tmpPart) {
      list($key, $value) = explode("=", $tmpPart, 2);
      $dsnParts[$key] = $value;
    }

    if ($dbDriver == 'mysql') {
      // mysql should be ansi information schema compatible (and may be has some extensions)
      // $dbStruc = self::getDbStrucMysql($dbName);
      $dbStruc = self::getDbStrucAnsiInformationSchema($dsnParts['dbname']);
    }
    /*
    elseif ($dbDriver == 'sqlite') {
      $dbStruc = self::getDbStrucSqlite($dbName);
    }
    elseif ($dbDriver == 'ansiinformationschema') {
      $dbStruc = self::getDbStrucAnsiInformationSchema($dbName);
    }
    */

    return $dbStruc;

  }  // eo get db struc



  /**
  * Get database structure (for sqlite databases).
  */
  public static function getDbStrucSqlite($dbName) {

    $dbStruc = array();

    /*

    // from http://www.sqlite.org/cvstrac/wiki?p=InformationSchema

    CREATE VIEW INFORMATION_SCHEMA_TABLES AS
      SELECT * FROM (
          SELECT 'main'     AS TABLE_CATALOG,
                 'sqlite'   AS TABLE_SCHEMA,
                 tbl_name   AS TABLE_NAME,
                 CASE WHEN type = 'table' THEN 'BASE TABLE'
                      WHEN type = 'view'  THEN 'VIEW'
                 END        AS TABLE_TYPE,
                 sql        AS TABLE_SOURCE
          FROM   sqlite_master
          WHERE  type IN ('table', 'view')
                 AND tbl_name NOT LIKE 'INFORMATION_SCHEMA_%'
          UNION
          SELECT 'main'     AS TABLE_CATALOG,
                 'sqlite'   AS TABLE_SCHEMA,
                 tbl_name   AS TABLE_NAME,
                 CASE WHEN type = 'table' THEN 'TEMPORARY TABLE'
                      WHEN type = 'view'  THEN 'TEMPORARY VIEW'
                 END        AS TABLE_TYPE,
                 sql        AS TABLE_SOURCE
          FROM   sqlite_temp_master
          WHERE  type IN ('table', 'view')
                 AND tbl_name NOT LIKE 'INFORMATION_SCHEMA_%'
      ) ORDER BY TABLE_TYPE, TABLE_NAME;

    */

    // Note, 12 Jan 2006: I reformatted this page so it was actually possible to read it,
    // but I did not debug the SQL code given. As stated, it does not work; any query on the view
    // gives the error "no such table: sqlite_temp_master". If you don't use temporary tables
    // you can just rip out the second inner SELECT (which then renders the outer SELECT unnecessary):

    /*

    CREATE VIEW INFORMATION_SCHEMA_TABLES AS
        SELECT 'main'     AS TABLE_CATALOG,
               'sqlite'   AS TABLE_SCHEMA,
               tbl_name   AS TABLE_NAME,
               CASE WHEN type = 'table' THEN 'BASE TABLE'
                    WHEN type = 'view'  THEN 'VIEW'
               END        AS TABLE_TYPE,
               sql        AS TABLE_SOURCE
        FROM   sqlite_master
        WHERE  type IN ('table', 'view')
               AND tbl_name NOT LIKE 'INFORMATION_SCHEMA_%'
        ORDER BY TABLE_TYPE, TABLE_NAME;

    }

    */


    return $dbStruc();

  } // eo db struc for mysql databases



  /**
  * Get database structure (for databases with ansi conform information schema).
  */
  public static function getDbStrucAnsiInformationSchema($dbName) {

    $dbStruc = array();

    $pstmt = Db::prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE INFORMATION_SCHEMA.TABLES.TABLE_SCHEMA=:dbName');
    $pstmt->execute(array('dbName' => $dbName));
    $tableRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    $tables = array();
    foreach ($tableRecords as $tableRecord) {

      // get columns info

      $pstmt = Db::prepare('SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, ' .
                                    'CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, CHARACTER_SET_NAME, COLLATION_NAME, ' .
                                    'NUMERIC_PRECISION, NUMERIC_SCALE, ORDINAL_POSITION, ' .
                                    'COLUMN_TYPE, ' .
                                    'COLUMN_KEY ' .
                             ' FROM INFORMATION_SCHEMA.COLUMNS ' .
                             ' WHERE INFORMATION_SCHEMA.COLUMNS.TABLE_SCHEMA=:dbName AND ' .
                                   ' INFORMATION_SCHEMA.COLUMNS.TABLE_NAME=:tableName ' .
                             ' ORDER BY ORDINAL_POSITION'
                          );

      $pstmt->execute(array('dbName' => $dbName, 'tableName' => $tableRecord['TABLE_NAME']));
      $columnRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
      $pstmt->closeCursor();

      $columns = array();
      foreach ($columnRecords as $columnRecord) {
        // add table name to column record
        $columnRecord['TABLE_NAME'] = $tableRecord['TABLE_NAME'];
        $columns[$columnRecord['COLUMN_NAME']] = $columnRecord;
      }
      $tableRecord['columns'] = $columns;


      // get key info
      /*
      $pstmt = Db::prepare('SELECT CONSTRAINT_NAME, ORDINAL_POSITION,	POSITION_IN_UNIQUE_CONSTRAINT, COLUMN_NAME ' .
                             ' FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE ' .
                             ' WHERE TABLE_SCHEMA=:dbName AND ' .
                                   ' TABLE_NAME=:tableName' .
                              ' ORDER BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
      $pstmt->execute(array('dbName' => $dbName, 'tableName' => $tableRecord['TABLE_NAME']));
      $keyRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
      $pstmt->closeCursor();

      $keys = array();
      foreach ($keyRecords as $keyRecord) {
        $keys[$keyRecord['CONSTRAINT_NAME']][$keyRecord['ORDINAL_POSITION']] = $keyRecord;
      }
      $tableRecord['keys'] = $keys;
      */

      // get key info2 - use statistic schema
      $pstmt = Db::prepare('SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME,	NON_UNIQUE ' .
                             ' FROM INFORMATION_SCHEMA.STATISTICS ' .
                             ' WHERE TABLE_SCHEMA=:dbName AND ' .
                                   ' TABLE_NAME=:tableName' .
                              ' ORDER BY TABLE_SCHEMA, TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
      $pstmt->execute(array('dbName' => $dbName, 'tableName' => $tableRecord['TABLE_NAME']));
      $keyRecords = $pstmt->fetchAll(PDO::FETCH_ASSOC);
      $pstmt->closeCursor();

      $keys = array();
      foreach ($keyRecords as $keyRecord) {
        $keys[$keyRecord['INDEX_NAME']][$keyRecord['SEQ_IN_INDEX']] = $keyRecord;
      }
      $tableRecord['keys'] = $keys;


      // finally put table info to overall record
      $tables[$tableRecord['TABLE_NAME']] = $tableRecord;

    }  // loop over table names



    // return tables array
    return $tables;


    /*
    we dont need this extra info
    // compose final array
    $dbStruc[$dbName] = array('driver' => $dbDriver,
                              'name' => $dbName,
                              'tables' => $tables);

    return $dbStruc;
    */

  }  // eo get db structure



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



}  // end of class

?>
