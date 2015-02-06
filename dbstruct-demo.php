#!/usr/bin/php
<?PHP

error_reporting((E_ALL | E_STRICT));
error_reporting(error_reporting() ^ E_NOTICE);

require_once("OgerDbStruct.class.php");
require_once("OgerDbStructMysql.class.php");


$dbFromDSN = "mysql:dbname=sourcedb;host=127.0.0.1";
$dbFromName = "sourcedb";
$dbFromUser = "root";
$dbFromPass = "";

$dbToDSN = "mysql:dbname=targetdb;host=127.0.0.1";
$dbToName = "targetdb";
$dbToUser = "root";
$dbToPass = "";

/*
$dbFromDSN = "mysql:dbname=test;host=127.0.0.1";
$dbFromName = "test";
$dbToDSN = "mysql:dbname=test;host=127.0.0.1";
$dbToName = "test";
*/

$structFile = "dbstruct.inc.php";


$cmd = $argv[1];
$opt = $argv[2];

if ($cmd == "getstruct") {

	$conn = new PDO($dbFromDSN, $dbFromUser, $dbFromPass);
	// we rely on utf8 and exception mode
	$conn->exec("SET CHARACTER SET UTF8");
	$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$structer = new OgerDbStructMysql($conn, $dbFromName);
	$dbStruct = $structer->getDbStruct();
	echo "Write structure file {$structFile}.\n";
	file_put_contents($structFile, "<?PHP\n return\n" . $structer->formatDbStruct($dbStruct) . "\n;\n?>\n");

	exit;
}  // eo get struct



if ($cmd == "update") {

	$conn = new PDO($dbToDSN, $dbToUser, $dbToPass);
	// we rely on utf8 and exception mode
	$conn->exec("SET CHARACTER SET UTF8");
	$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$strucTpl = include($structFile);

	$structer = new OgerDbStructMysql($conn, $dbToName);
	$structer->setParams(array("log-level" => $structer::LOG_NOTICE,
														 "echo-log" => true));

	if ($opt == "apply") {
		echo "*** Apply following changes in the database.\n";
		$structer->setParam("dry-run", false);  // this is the default, but to be shure
		// we do not want to remove surplus fields so only update and reorder
		//$structer->forceDbStruct($strucTpl);
		$structer->updateDbStruct($strucTpl);
		$structer->reorderDbStruct();
	}

	// (re)check after apply or if  apply was not requested (log-only)
	echo "*** Following must be changed in the database to be in sync with the struct file.\n";
	$structer->setParam("dry-run", true);
	$structer->getDbStruct();  // (re)read structure
	$structer->forceDbStruct($strucTpl);

	exit;
}  // eo update


echo "\n";
echo "Usage: dbstruct-demo.php command [options]\n";
echo "command: possible values are 'getstruct' or 'update'.\n";
echo "options: 'apply' for update command. Otherwise log-only mode is used.\n";
echo "\n";



?>
