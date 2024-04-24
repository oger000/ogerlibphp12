<?PHP
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/


// THIS FILE ONLY EXISTS FOR BACKWARD COMPABILITY WITH ogerArch
// DO NOT USE IN NEW PROJECTS !!!!


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


/*
require_once('lib/fpdf/fpdf.php');
class OgerPdf extends FPDF {
*/
require_once('lib/tcpdf/tcpdf.php');
class OgerPdf0 extends TCPDF {

	public $startTime;

	public $tpl = "";
	public $headerValues = array();
	public $footerValues = array();
	public $attribStore = array();

	/**
	* Constructor.
	*/
	public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4',           // FPDF
															$unicode = true, $encoding = 'UTF-8', $diskspace = false) {  // additional parameters for TCPDF

		parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskspace);
		$this->startTime = time();
	}  // eo constructor




	/**
	* Clip cell at given width
	*/
	public function ogerClippedCell($width, $height, $text, $border = 0, $ln = 0, $align = '', $fill = 0, $link = null) {

		while (strlen($text) > 0 && parent::GetStringWidth($text) > $width) {
			$text = mb_substr($text, 0, -1, "UTF-8");
		}
		parent::Cell($width, $height, $text, $border, $ln, $align, $fill, $link);

	}  // eo clipped cell


	########## TEMPLATE BEGIN ##########


	/**
	* Set template
	* @tpl: Template text
	*/
	public function tplSet($tpl) {
		$this->tpl = $tpl;
	}

	/**
	* Set header values
	*/
	public function tplSetHeaderValues($values) {
		$this->headerValues = $values;
	}

	/**
	* Display header
	* Overwrites TcPdf::Header()
	*/
	public function header() {
		$this->tplUse('header', $this->headerValues);
	}

	/**
	* Set footer values
	*/
	public function tplSetFooterValues($values) {
		$this->footerValues = $values;
	}

	/**
	* Display footer
	* Overwrites TcPdf::Footer()
	*/
	public function footer() {
		$this->tplUse('footer', $this->footerValues);
	}


	/**
	* Use template
	* @params: assocoiative array with variableName => value pairs.
	*/
	public function tplUse($blockName, $values = array()) {

		$tpl = $this->tpl;

		// if a block name is given than only this block is used from the template
		if ($blockName) {
			$tpl = $this->tplGetBlock($blockName);
		}

		// unify newlines
		$tpl = str_replace("\r", "\n", $tpl);

		$lines = explode("\n", $tpl);
		for ($lineNumber = 0; $lineNumber < count($lines); $lineNumber++) {

			$line = $lines[$lineNumber];

			// commandpart and text are separated by #
			list($cmd, $text) = explode("#", $line, 2);
			// command parts and parseOpts are separated by "::"
			// Multiple parts are possible (may be also separated by "::")
			list($cmd, $moreLines) = explode("::", $cmd, 2);

			// read continous lines
			$moreLines = trim($moreLines);
			if ($moreLines) {
				if (strlen($moreLines) > 3 && substr($moreLines, 0, 3) == '...') {
					while (++$lineNumber < count($lines)) {
						if (trim($lines[$lineNumber]) == $moreLines) {
							break;
						}
						$text .= "\n" . $lines[$lineNumber];
					}
				}
				else {
					while ($moreLines > 0 && ++$lineNumber < count($lines)) {
						$text .= "\n" . $lines[$lineNumber];
						$moreLines = $moreLines - 1;
					}
				}
			}  // eo morelines

			// command and opts are separated with one or more spaces
			$cmd = trim($cmd);
			list($cmd, $opts) = explode(' ', $cmd, 2);
			$opts = trim($opts);

			// ignore empty commands
			if (!$cmd) {
				continue;
			}

			// ignore comments "//"
			if (substr($cmd, 0, 2) == '//') {
				continue;
			}

			// recognize and ignore blocks here
			if (substr($cmd, 0, 1) == '{') {
				while (++$lineNumber < count($lines)) {
					if (substr(trim($lines[$lineNumber]), 0, 1) == "}") {
						break;
					}
				}
				continue;
			}  // blocks


			$text = $this->substTextVals($text, $values);

			// handle IF out of order  / condition is in text to allow REALY everything
			// check only for variable has any value for now (no locical operator etc)
			if ($cmd == 'IF') {
				// if condition failes than skip till ENDIF
				if (!$this->tplEvalIf($text)) {
					while (++$lineNumber < count($lines)) {
						if (substr(trim($lines[$lineNumber]), 0, 5) == "ENDIF") {
							break;
						}
					}
				}
				continue;
			}  // eo IF command
			if ($cmd == 'IFNOT') {
				// if condition succeed than skip till ENDIF
				if ($this->tplEvalIf($text)) {
					while (++$lineNumber < count($lines)) {
						if (substr(trim($lines[$lineNumber]), 0, 5) == "ENDIF") {
							break;
						}
					}
				}
				continue;
			}  // eo IF command
			if ($cmd == 'ENDIF') {
				continue;
			}  // eo ENDIF stanca of IF command


			// add some system variables to the values array
			$values['__TIME__'] = date('c', $this->startTime);
			$values['__PAGENO__'] = $this->pageNo();
			$values['__NBPAGES__'] = $this->getAliasNbPages();


			// execute command
			$this->tplExecuteCmd($cmd, $opts, $text, $lineNumber);

		}  // line loop

	}  // eo use template


	/**
	* Execute template command
	* @cmd: Command name.
	* @opts: Unparsed options string.
	* @text: Text.
	* @checkOnly: True to do a checkonly run without executing the command.
	*/
	public function tplExecuteCmd($cmd, $opts, $text, $lineNumber) {

		$opts = $this->tplParseOpts($opts);

		// for fpdf we have to decode utf8 explicitly here
		if (get_parent_class($this) == 'FPDF') {
			$text = utf8_decode($text);
		}

		switch ($cmd) {
		case '//':
		case '#':        // # schould never happen - but anyway
			break;
		case 'INIT':
			$this->tplInitPdf($opts);
			break;
		case 'MARGINS':
			list($left, $top, $right, $keep) = $opts[0];
			$this->setMargins($left, $top, $right, $keep);
			break;
		case 'AUTOPAGEBREAK':
			$this->setAutoPageBreak($opts[0][0], $opts[0][1]);
			break;
		case 'NEWLINE':
		case 'NL':
		case 'LN':
			$this->ln();
			break;
		case 'STARTTRANSFORM':
			$this->startTransform();
			break;
		case 'STOPTRANSFORM':
			$this->stopTransform();
			break;
		case 'FONT':
			$this->tplSetFont($opts[0]);
			break;
		case 'LINEDEF':
			$this->tplSetLineDef($opts[0]);
			break;
		case 'LINE':
			$this->tplSetLineDef($opts[1]);
			$this->tplLine($opts[0]);
			break;
		case 'DRAWCOL':
			$this->tplSetDrawCol($opts[0]);
			break;
		case 'FILLCOL':
			$this->tplSetFillCol($opts[0]);
			break;
		case 'RECT':
			list ($rect, $lineDef, $fill) = $opts;
			$this->tplRect($rect, $lineDef, $fill);
			break;
		case 'CLIPCELL':
			list ($cell, $font) = $opts;
			$this->tplSetFont($font);
			$this->tplClippedCell($cell, $text);
			break;
		case 'CLIPCELLAT':
			list ($pos, $cell, $font) = $opts;
			$this->tplSetXY($pos);
			$this->tplSetFont($font);
			$this->tplClippedCell($cell, $text);
			break;
		case 'CELL':
			list ($cell, $font) = $opts;
			$this->tplSetFont($font);
			$this->tplCell($cell, $text);
			break;
		case 'CELLAT':
			list ($pos, $cell, $font) = $opts;
			$this->tplSetXY($pos);
			$this->tplSetFont($font);
			$this->tplCell($cell, $text);
			break;
		case 'MCELL':
		case 'MULTICELL':
			list ($cell, $font) = $opts;
			$this->tplSetFont($font);
			$this->tplMultiCell($cell, $text);
			break;
		case 'MCELLAT':
		case 'MULTICELLAT':
			list ($pos, $cell, $font) = $opts;
			$this->tplSetXY($pos);
			$this->tplSetFont($font);
			$this->tplMultiCell($cell, $text);
			break;
		case 'HTMLCELL':
			list ($cell, $font) = $opts;
			$this->tplSetFont($font);
			$this->tplHtmlCell($cell, $text);
			break;
		case 'HTML':
			list ($htmlOpts, $font) = $opts;
			$this->tplSetFont($font);
			$this->tplHtml($htmlOpts, $text);
			break;
		case 'WRITE':
			$this->write($this->getFontSize(), $text);   // FIXME incomplete
			break;
		case 'STORE':
			$this->tplStoreAttib($opts);
			break;
		case 'RESTORE':
			$this->tplRestoreAttib($opts);
			break;
		default:
			throw new Exception("OgerPdf::tplExecuteCmd: Unknown command: $cmd in line $lineNumber.\n");
		} // eo cmd switch

	}  // eo execute template command



	/**
	* Parse opts from template
	*/
	public function tplParseOpts(&$opts, $inBlock = false) {

		// if not in option-block than this is the initial call
		// and we have to prepare the opts string
		if (!$inBlock) {
			$opts = str_replace(' ', '', $opts);
			$opts = str_replace('~', ' ', $opts);
		}

		$optBlock = array();
		$value = '';
		while (strlen($opts) > 0) {
			$char = substr($opts, 0, 1);
			$opts = substr($opts, 1);
			switch ($char) {
			case ',':
			case ']':
				if (substr($value, 0, 1) == "=") {
					$value = Oger::evalMath(substr($value, 1));
				}
				$optBlock[] = $value;
				$value = '';
				break;
			case '[':
				$optBlock[] = $this->tplParseOpts($opts, true);
				break;
			default:
				$value .= $char;
			}

			// end of block
			if ($char == ']') {
				// if closing char is followed by a comma
				// than remove this to avoid undesired empty extraoption
				if (substr($opts, 0, 1) == ',') {
					$opts = substr($opts, 1);
				}
				return $optBlock;
			}

		}  // eo char loop

		// script should reach this point only at top level of recursion
		// otherwise if the last option-block is not closed with ']'
		// Try to correct silently by adding current value (or an empty one)
		if ($inBlock) {
			if (substr($value, 0, 1) == "=") {
				$value = Oger::evalMath(substr($value, 1));
			}
			$optBlock[] = $value;
		}

		return $optBlock;

	}  // eo parse tpl opts



	/**
	* Get marked blocks from template
	*/
	public function tplGetBlocks() {

		preg_match_all('/^\s*\{(.*?$)(.*?)^\s*\}/ms', $this->tpl, $matches);

		$blocks = array();
		for ($i = 0; $i < count($matches[1]) ; $i++) {
			$blocks[trim($matches[1][$i])] = trim($matches[2][$i]);
		}

		return $blocks;
	}  // get marked blocks



	/**
	* Get named block from template
	*/
	public function tplGetBlock($name) {
		$blocks = $this->tplGetBlocks($this->tpl);
		return $blocks[$name];
	}  // get named block

	/**
	* Set X and Y coordinate from template notation
	*/
	public function tplSetXY($opts) {

		list ($x, $y) = $opts;

		if ($x === '' || $x === null) { $x = parent::GetX(); }
		if ($y === '' || $y === null) { $y = parent::GetY(); }
		parent::SetXY($x, $y);

	}  // eo tpl set xy



	/**
	* Init pdf
	* The parameters are the same as in __construct, but for now only the first three ones
	*/
	public function tplInitPdf($opts) {
		$orientation = $opts[0][0];
		$unit = $opts[0][1];
		$format = $opts[0][2];
		if ($orientation) {
			parent::setPageOrientation($orientation);
		}
		if ($unit) {
			parent::setPageUnit($unit);
		}
		if ($format) {
			parent::setPageFormat($format, $orientation);
		}
	}  // eo tpl init pdf



	/**
	* Set template font
	*/
	public function tplSetFont($opts) {
		list($family, $style, $size) = $opts;
		parent::SetFont($family, $style, $size);
	}  // eo tpl set font



	/**
	* Set template line definition
	*/
	public function tplSetLineDef($lineDef) {

		list($thick, $color) = $lineDef;
		if ($thick !== '' && $thick !== null) {
			parent::SetLineWidth($thick);
		}
		$this->tplSetDrawColor($color);
	}  // eo set line def



	/**
	* Set template draw color
	*/
	public function tplSetDrawColor($color) {

		if (!$color) {
			return;
		}

		list($red, $green, $blue) = $color;
		parent::SetDrawColor($red, $green, $blue);
	}  // eo set draw color



	/**
	* Set template fill color
	*/
	public function tplSetFillColor($color) {

		if (!$color) {
			return;
		}

		list($red, $green, $blue) = $color;
		parent::SetFillColor($red, $green, $blue);
	}  // eo set fill color



	/**
	* Output rectangle
	*/
	public function tplRect($rect, $lineDef, $fill) {

		$this->tplSetLineDef($lineDef);
		$this->tplSetFillColor($fill);

		list ($x, $y, $width, $height, $style) = $rect;
		parent::Rect($x, $y, $width, $height, $style);

	}  // eo tpl set font



	/**
	* Output template cell
	*/
	public function tplCell($opts, $text) {

		list($width, $height, $borderDef, $ln, $align, $fillDef, $link, $stretch) = $opts;

		if ($width === 'FIT') {
			$width = parent::GetStringWidth($text);
		}

		if ($borderDef) {
			if (!is_array($borderDef)) {
				$borderDef = array($borderDef);
			}
			list ($border, $lineDef) = $borderDef;
			$this->tplSetLineDef($lineDef);
		}

		if ($fillDef) {
			if (!is_array($fillDef)) {
				$fillDef = array($fillDef);
			}
			list ($fill, $color) = $fillDef;
			$this->tplSetFillColor($color);
		}

		parent::Cell($width, $height, $text, $border, $ln, $align, $fill, $link, $stretch);
	}  // eo tpl cell output



	/**
	* Output clipped template cell
	*/
	public function tplClippedCell($opts, $text) {

		list($width, $height, $borderDef, $ln, $align, $fillDef, $link) = $opts;

		if ($width === 'FIT') {
			$width = parent::GetStringWidth($text);
		}

		if ($borderDef) {
			if (!is_array($borderDef)) {
				$borderDef = array($borderDef);
			}
			list ($border, $lineDef) = $borderDef;
			$this->tplSetLineDef($lineDef);
		}

		if ($fillDef) {
			if (!is_array($fillDef)) {
				$fillDef = array($fillDef);
			}
			list ($fill, $color) = $fillDef;
			$this->tplSetFillColor($color);
		}

		$this->ogerClippedCell($width, $height, $text, $border, $ln, $align, $fill, $link);

	}  // eo tpl clipped cell output



	/**
	* Output template multicell
	*/
	public function tplMultiCell($opts, $text) {

		list($width, $height, $borderDef, $align, $fillDef,
				 $ln, $x, $y, $resetH, $stretch, $isHtml, $autoPadding, $maxHeight, $vAlign, $fitCell) = $opts;

		if ($borderDef) {
			if (!is_array($borderDef)) {
				$borderDef = array($borderDef);
			}
			list ($border, $lineDef) = $borderDef;
			$this->tplSetLineDef($lineDef);
		}

		if ($fillDef) {
			if (!is_array($fillDef)) {
				$fillDef = array($fillDef);
			}
			list ($fill, $color) = $fillDef;
			$this->tplSetFillColor($color);
		}

		// handle x position and indention of first line
		if ($x) {
			if (!is_array($x)) {
				$x = array($x);
			}
			list ($x, $xFirst) = $x;
			if ($xFirst !== null) {
				$xFirst = $this->tplEvalX($xFirst);
				if ($xFirst !== $x) {
					parent::setX($xFirst);
					$text = parent::write($height, $text, '', false, '', false, 0, true, false, 0);
					// if the first line ends with an hard line break this remains in the remaining text, so we remove it
					//$text = ltrim($text);
					if (substr($text, 0, 1) == "\n") {
						$text = substr($text, 1);
					}
				}
			}
		}

		// resetH default to true !
		if ($resetH === "") {
			$resetH = true;
		}

		parent::MultiCell($width, $height, $text, $border, $align, $fill,
											$ln, $x, $y, $resetH, $stretch, $isHtml, $autoPadding, $maxHeight, $vAlign, $fitCell);

	}  // eo tpl multi cell


	/**
	* Output template html cell
	*/
	public function tplHtmlCell($opts, $text) {

		list($width, $height, $x, $y, $borderDef, $ln, $fillDef, $resetH, $align, $autoPadding) = $opts;

		/*
		html text includes formating, so lenght cannot be detected this way
		if ($width === 'FIT') {
			$width = parent::GetStringWidth($text);
		}
		*/

		if ($borderDef) {
			if (!is_array($borderDef)) {
				$borderDef = array($borderDef);
			}
			list ($border, $lineDef) = $borderDef;
			$this->tplSetLineDef($lineDef);
		}

		if ($fillDef) {
			if (!is_array($fillDef)) {
				$fillDef = array($fillDef);
			}
			list ($fill, $color) = $fillDef;
			$this->tplSetFillColor($color);
		}

		parent::writeHTMLCell($width, $height, $x, $y, $text, $border, $ln, $fill, $resetH, $align, $autoPadding);
	}  // eo tpl html cell output


	/**
	* Output template html (write html)
	*/
	public function tplHtml($opts, $text) {

		list($ln, $fillDef, $resetH, $cellMode, $align) = $opts;

		if ($fillDef) {
			if (!is_array($fillDef)) {
				$fillDef = array($fillDef);
			}
			list ($fill, $color) = $fillDef;
			$this->tplSetFillColor($color);
		}

		parent::writeHTML($text, $ln, $fill, $resetH, $cellMode, $align);
	}  // eo tpl write html output





	/**
	* Template line
	*/
	public function tplLine($opts) {

		list($x1, $y1, $x2, $y2) = $opts;

		$x1 = $this->tplEvalX($x1);
		$y1 = $this->tplEvalY($y1);
		$x2 = $this->tplEvalX($x2);
		$y2 = $this->tplEvalY($y2);

		$this->line($x1, $y1, $x2, $y2);
	}  // eo template line






	########## TEMPLATE END ##########


	/**
	* Get variable names used in template
	*/
	public function getVarNames($text) {

		$varNames = array();

		// prepare text and substitute variables
		// MEMO: if preg_match_all is to slow we can try exploding at "{" etc
		//       have a look at this->substTextVals()
		preg_match_all('/(\{.*?\})/ms', $text, $varDefs);
		foreach ($varDefs[1] as $varDef) {  // loop over first matching braces
			$varName = trim(substr(substr($varDef, 1), 0, -1));  // remove {}
			list($varName, $format) = explode(" ", $varName, 2);
			$varName = trim($varName);
			$varNames[$varName] = $varName;
		}

		return $varNames;
	}   // eo get var names



	/**
	* Substitute variables in text
	*/
	public function substTextVals($text, $values) {

		// prepare text and substitute variables
		// MEMO: if preg_match_all is to slow we can try exploding at "{" etc
		preg_match_all('/(\{.*?\})/ms', $text, $varDefs);
		foreach ($varDefs[1] as $varDef) {  // loop over first matching braces
			$varName = trim(substr(substr($varDef, 1), 0, -1));  // remove {}
			list($varName, $format) = explode(" ", $varName, 2);
			$varName = trim($varName);
			$format = trim($format);
			if (array_key_exists($varName, $values)) {
				$value = $values[$varName];
				if ($format) {
					list($formatType, $format) = explode(':', $format, 2);
					$formatType = trim($formatType);
					switch ($formatType) {
					case "datetime":
						// empty date remains empty
						if ($value && strtotime($value) && substr($value, 0, 4) != "0000") {
							$value = date($format, strtotime($value));
							// $value .= "abc";
						}
						else {
							$value = "";
						}
						break;
					}  // eo formattye
				}
				$text = str_replace($varDef, $value, $text);
			}
		}

		return $text;
	}   // eo substitute text vars


	/**
	* Evaluate IF condition
	* @condition: condition string after substituting variables
	*/
	public function tplEvalIf($condition) {

		// for now we only check if the condition contains ANY CONTENT
		if (trim($condition)) {
			return true;
		}

		return false;
	}   // eo eval IF condition



	/**
	* template eval XY pos
	*/
	private function tplEvalXY($opts, $current) {

		if (!is_array($opts)) {
			$opts = array($opts);
		}

		list($pos, $offset) = $opts;

		if (trim($pos) == 'CURRENT') {
			$pos = $current;
		}
		$current *= 1;

		return $pos + $offset;
	}   // eo eval xy
	/**
	* template eval X pos
	*/
	public function tplEvalX($opts) {
		return $this->tplEvalXY($opts, $this->getX());
	}   // eo eval y
	/**
	* template eval Y pos
	*/
	public function tplEvalY($opts) {
		return $this->tplEvalXY($opts, $this->getY());
	}   // eo eval y



	/**
	* Store attributes
	*/
	public function tplStoreAttib($opts) {

		list ($attributes, $indizes) = $opts;
		if (!is_array($indizes)) {
			$indizes = array($indizes);
		}

		foreach ($indizes as $index) {
			$index = trim($index);
			if (!$index) {
				$index = 'DEFAULT';
			}
			foreach ($attributes as $attrib) {
				switch ($attrib) {
				case 'POS_XY':
					$this->attribStore[$index]['POS_X'] = $this->getX();
					$this->attribStore[$index]['POS_Y'] = $this->getY();
					break;
				case 'POS_X':
					$this->attribStore[$index][$attrib] = $this->getX();
					break;
				case 'POS_Y':
					$this->attribStore[$index][$attrib] = $this->getY();
					break;
				default:
					// ignore silently
				}
			}
		}
	}   // eo store attributes

	/**
	* Restore attributes
	*/
	public function tplRestoreAttib($opts) {

		list ($attributes, $indizes) = $opts;
		if (!is_array($indizes)) {
			$indizes = array($indizes);
		}

		foreach ($indizes as $index) {
			$index = trim($index);
			if (!$index) {
				$index = 'DEFAULT';
			}
			foreach ($attributes as $attrib) {
				switch ($attrib) {
				case 'POS_X':
					parent::setX($this->attribStore[$index][$attrib]);
					break;
				case 'POS_Y':   // ATTENTION sety alone set x back to left margin, so use setXY
					parent::setXY($this->getX(), $this->attribStore[$index][$attrib]);
					break;
				case 'POS_XY':
					parent::setXY($this->attribStore[$index]['POS_X'],$this->attribStore[$index]['POS_Y']);
					break;
				default:
					// ignore unknown attributes silently
				}
			}
		}
	}   // eo restore attributes





}  // end of class

?>
