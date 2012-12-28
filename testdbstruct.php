#!/usr/bin/php
<?PHP

include("testdbstruct.localonly.inc.php");

//include("Oger.class.php");
include("OgerDbStruct.class.php");
include("OgerDbStructMysql.class.php");

$dbOpts = array("connectionCharset" => "UTF8",
                 PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);

$dbName1 = "test";
$dbh1 = new PDO("mysql:dbname=$dbName1;host=127.0.0.1", $dbUser, $dbPass, $dbOpts);
$dbh1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dbs1 = new OgerDbStructMysql($dbh1, $dbName1);



$dbName2 = "test2";
$dbh2 = new PDO("mysql:dbname=$dbName2;host=127.0.0.1", $dbUser, $dbPass, $dbOpts);
$dbh2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dbs2 = new OgerDbStructMysql($dbh2, $dbName2);



$dbs2->setParam("log-level", OgerDbStruct::LOG_NOTICE);
$dbs2->setParam("echo-log", true);
$dbs2->setParam("dry-run", true);



$dbStruct1 = $dbs1->getDbStruct();
echo $dbs1->formatDbStruct($dbStruct1);
echo "\n\n";
$dbs2->forceDbStruct($dbStruct1);


?>
