#!/usr/bin/php
<?php


$oriCwd = getcwd();
$dirPrefix = "";
while (true) {
  // stop on index file directory
  if (file_exists("index.php")) {
    break;
  }
  $oldCwd = getcwd();
  chdir("..");
  $dirPrefix .= "../";
  // Avoid endless loops when reaching the root directory
  // It is very likely wrong if we reach the root dir
  // but we do not exit. The logfile will do the rest.
  if (getcwd() == $oldCwd) {
    break;
  }
}
chdir($oriCwd);

require_once("{$dirPrefix}lib/ogerlibphp12/OgerPdfTpl.class.php");



//$filNam = "ledgerAcct.php";
//$filNam = "localonly.txt";
$filNam = "StratumReportShort.tpl";

$in = file_get_contents($filNam);

$p = new OgerPdfTpl();

$out = $p->parse($in);
file_put_contents("{$filNam}.lex2a.localonly", $out);

$tok = $p->parse($in, true);
$out = "";
foreach ($tok as $t) {
  $out .= " {$t[0]} => {$t[1]}\n";
}
file_put_contents("{$filNam}.lex2b.localonly", $out);


?>
