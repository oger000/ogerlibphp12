#!/usr/bin/php
<?php

$filNam = "index.php";

$x = file_get_contents($filNam);
$t = token_get_all($x);

$tx = var_export($t, true);
//file_put_contents("{$filNam}.lex1.localonly", $tx);

$tx = "";
foreach ($t as $xTok) {

  if (is_string($xTok)) {
    $idNam = "*PLAIN*";
    $tokTx = $xTok;
  }
  else {
    list($id, $tokTx) = $xTok;
    $idNam = token_name($id);
  }
  $tx .= " {$idNam} => {$tokTx}\n";
}


file_put_contents("{$filNam}.lex2.localonly", $tx);








?>