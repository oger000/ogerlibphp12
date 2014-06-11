#!/usr/bin/php
<?php

$skipLogonCheck = true;
require_once("php/init.inc.php");



//$filNam = "ledgerAcct.php";
//$filNam = "localonly.txt";
$filNam = "index.php";

$in = file_get_contents($filNam);

$p = new OgerPdfTpl();
$out = $p->parse($in);

file_put_contents("{$filNam}.lex.localonly", $out);






?>