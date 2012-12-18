<?PHP



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






}  // eo mysql struct class






?>
