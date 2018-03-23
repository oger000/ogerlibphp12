<?PHP
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/




/**
* Wrapper for tinybutstrong lib to fit
* class detection and file including of init.php
* www.tinybutstrong.com
*/



// load original class file and used plugins
require_once(__DIR__ . "/external/tinybutstrong/tbs_class.php");
require_once(__DIR__ . "/external/tinybutstrong/plugins/tbs_plugin_opentbs.php");


/*
 * Tiny but strong wrapper class
 */
class OgerTinyButStrong extends clsTinyButStrong {


	/**
	* Constructor.
	*/
	public function __construct($opts = null) {

		// default to restrictive security settings
		if ($opts === null) {
			$opts = array("var_prefix" => "otbs_var_", "fct_prefix" => "otbs_vct_");
		}

		parent::__construct($opts);
	}  // eo constructor



}  // eo class

?>
