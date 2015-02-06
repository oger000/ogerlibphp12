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
//require_once('lib/tcpdf/tcpdf.php');
//class OgerPdfTpl extends TCPDF {
class OgerPdfTpl {

	private static $plainTokenId = "_";


	/**
	* Constructor.
	*/
	/*
	public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4',           // FPDF
															$unicode = true, $encoding = 'UTF-8', $diskcace = false) {  // additional parameters for TCPDF
		parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskspace);
	}  // eo constructor
	*/
	public function __construct() {
		//parent::__construct();
	}  // eo constructor



	/**
	* template parser
	*/
	public function parse($tpl , $rawOut = false) {

		$tokenIn = token_get_all($tpl);

		$allToken = array();
		foreach ($tokenIn as $tok) {

			if (is_string($tok)) {
				$tokTx = $tok;
				$idNam = static::$plainTokenId;
			}
			else {  // array
				list($idNum, $tokTx) = $tok;
				$idNam = token_name($idNum);
			}

			$allToken[] = array($idNam, $tokTx);
		}  // eo unify token

		if ($rawOut) {
			return $allToken;
		}


		$allTtokenBak = $allToken;
		$out = "";

		// filter the tokens
		while ($tok = array_shift($allToken)) {
			list($idNam, $tokTx) = $tok;

			switch ($idNam) {

			// function call and more
			// TODO definitly MUST be checked for security issues
			case "T_STRING":
				switch (strtolower($tokTx)) {
				case "setx":
				case "write":
					$out .= $tokTx;
					break;
				}
			break;

			// raw values
			case "T_CONSTANT_ENCAPSED_STRING":
			case "T_ENCAPSED_AND_WHITESPACE":
			case "T_LNUMBER":
				$out .= $tokTx;
				break;

			// common syntax (TODO has to be checked for security issues)
			case static::$plainTokenId:
				switch (strtolower($tokTx)) {
				case "\$":  // a single dollar looks like variable variable or variable function call
					$tokTx = "";
					break;
				}
			$out .= $tokTx;
			break;

			// common syntax (TODO should be checked for security issues ????)
			case "T_CURLY_OPEN":

			// harmless common syntax (no need to further checks)
			case "T_WHITESPACE":
			case "T_START_HEREDOC":
			case "T_END_HEREDOC":
			case "T_DOUBLE_COLON":  // skip ???
			case "T_IS_EQUAL":

			// allowed language features
			case "T_IF":
			case "T_ELSE":
			case "T_FOREACH":
			case "T_AS":
			case "T_CONTINUE":

			//case "T_ECHO":
			case "T_UNSET":

				$out .= $tokTx;
				break;

			// skip unwanted known token ids till end and write internal log
			case "T_REQUIRE_ONCE":
			case "T_ECHO":
				$tokTx = nl2br($tokTx);
				$out .= "\n// Skiped: {$idNam} => {$tokTx}";
				while (list($idNam, $tokTx) = array_shift($allToken)) {
					$tokTx = str_replace(array("\n", "\r"), array("\\n", "\\r"), $tokTx);
					$out .= " {$tokTx}";
					if ($idNam == static::$plainTokenId && $tokTx == ";") {
						$out .= "\n";
						break;
					}
				}
				break;

			// remove variables at all, we use string substitution to get vars into template
			// remove also curly braces (looks like clurly braces outside strings
			// have no special id but are treated as "plain" syntax tokens)
			case "T_VARIABLE":
			case "T_STRING_VARNAME":
			//case "T_CURLY_OPEN":
				// OBSOLETED ???? mask variables - only keys in $vals array are allowed
				//$out .= "\$vals['" . substr($tokTx, 1) . "']";
				break;

			case "T_DOLLAR_OPEN_CURLY_BRACES":
				//$out .= "{\$";
				$out .= "{";
				break;

			// skip silently
			case "T_OPEN_TAG":
			case "T_COMMENT":
			case "T_EXIT":
				break;

			// skip unknown token ids and write internal log
			default:
				$tokTx = str_replace(array("\n", "\r"), array("\\n", "\\r"), $tokTx);
				$out .= "\n// Unknown: {$idNam} => {$tokTx}\n";
			}

		}  // filter


		return $out;
	}  // eo parse



}  // eo class

?>
