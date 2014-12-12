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


/*
require_once('lib/fpdf/fpdf.php');
class OgerPdf extends FPDF {
*/
require_once('lib/tcpdf/tcpdf.php');
class OgerPdf extends TCPDF {

  /**
  * Constructor.
  */
  /*
  public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4',           // FPDF
                              $unicode = true, $encoding = 'UTF-8', $diskcace = false) {  // additional parameters for TCPDF

    parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskspace);
    $this->startTime = time();
  }  // eo constructor
  */




  /**
  * Clip cell at given width
  */
  /*
  public function ogerClippedCell($width, $height, $text, $border = 0, $ln = 0, $align = '', $fill = 0, $link = null) {

    while (strlen($text) > 0 && parent::GetStringWidth($text) > $width) {
      $text = substr($text, 0, -1);
    }

    parent::Cell($width, $height, $text, $border, $ln, $align, $fill, $link);
  }  // eo clipped cell
  */
  public function Cell($width, $height, $text, $border = 0, $ln = 0, $align = '', $fill = 0, $link = null) {

    while (strlen($text) > 0 && parent::GetStringWidth($text) > $width) {
      $text = substr($text, 0, -1);
    }

    parent::Cell($width, $height, $text, $border, $ln, $align, $fill, $link);
  }  // eo clipped cell




}  // end of class

?>
