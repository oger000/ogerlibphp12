<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/




/**
* Extends tcpdf library.
* NOT FULLY TESTED WITH FPDF
* Should work for FPDF too, but not all features are supported:
* - Maxheight of MultiCell
*/




/*
 * HAVE A LOOK AT FPDF_TPL/FPDI
 * at <http://www.setasign.de/products/pdf-php-solutions/fpdi/>
 * may be this "pdf-template from an existing pdf"
 * could replace this home-grown template system?
 */



/**
* Pdf template class.
*/
/*
require_once('lib/fpdf/fpdf.php');
class OgerPdfTok extends FPDF {
*/
require_once('lib/tcpdf/tcpdf.php');
class OgerPdfTpl extends TCPDF {

  public $startTime;

  public $tpl = "";
  public $headerValues = array();
  public $footerValues = array();
  public $attribStore = array();

  private static $plainTokenId = "_";


  /**
  * Constructor.
  */
  public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4',           // FPDF
                              $unicode = true, $encoding = 'UTF-8', $diskcace = false) {  // additional parameters for TCPDF

    parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskspace);
    $this->startTime = time();
  }  // eo constructor



  /**
  * template parser
  */
  public function parse($tpl) {

    $tokenIn = token_get_all($tpl);

    $token = array();
    foreach ($tokenIn as $tok) {

      if (is_string($tok)) {
        $tokTx = $tok;
        $idNam = static::$plainTokenId;
      }
      else {  // array
        list($idNum, $tokTx) = $tok;
        $idNam = token_name($idNum);
      }

      $token[] = array($idNam, $tokTx);
    }  // eo unify token

    $tokenBak = $token;

    $unused = array();
    while ($tok = array_shift($token)) {
      list($idNam, $tokTx) = $tok;

      // filter valid expressions
      switch ($idNam) {

      // raw values
      case "T_STRING":
      case "T_WHITESPACE":
      case "T_CONSTANT_ENCAPSED_STRING":
      case "T_LNUMBER":
        $out .= $tokTx;   // TODO check details ???
        break;

      // common syntax
      case static::$plainTokenId:
      case "T_OPEN_TAG":
      // allowed language features
      case "T_IF":
      case "T_ELSE":
      case "T_FOREACH":
      case "T_CONTINUE":

      case "T_DOUBLE_COLON":
      case "T_IS_EQUAL":
        $out .= $tokTx;
        break;

      // disabled language features
      case "T_REQUIRE_ONCE":
      case "T_ECHO":
      case "T_UNSET":
        // skip expression
        $out .= "\n// Skiped expression: {$idNam} => {$tokTx}\n";
        while (list($idNam, $tokTx) = array_shift($token)) {
          $tokTx = str_replace(array("\n", "\r"), array("\\n", "\\r"), $tokTx);
          $out .= "\n// Skiped expression {$idNam} => {$tokTx}\n";
          if ($idNam == static::$plainTokenId && $tokTx == ";") {
            break;
          }
        }
        break;

      // mask variables - only keys in $vals array are allowed
      case "T_VARIABLE":
        $out .= "\$vals['" . substr($tokTx, 1) . "']";
        break;

      // suppressed
      case "T_COMMENT":
        break;

      default:
        $out .= "\n// Unused {$idNam}\n";
      }

    }  // filter


    return $out;
  }  // eo parse



}  // eo class

?>
