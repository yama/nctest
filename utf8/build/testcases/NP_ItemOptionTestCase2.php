<?php
/**
 * +-------------------------------------------------------
 * |            Nucleus ItemOption TestCase              
 * +-------------------------------------------------------
 * |
 * +-INFO--------------------------------------------------
 * |  Author:   Jeroen Budts (TeRanEX)
 * |  URL:      http://budts.be/weblog/
 * |  JabberID: teranex@jabber.org
 * |
 * +-TODO--------------------------------------------------
 * | 
 * +-HISTORY-----------------------------------------------
 * |  
 * |
 * +-CVS---------------------------------------------------
 * | $Id: NP_ItemOptionTestCase2.php,v 1.1.1.1 2005-02-28 07:14:30 kimitake Exp $
 * |
 * +-------------------------------------------------------
 */

class NP_ItemOptionTestCase2 extends NucleusPlugin {

// --------- Plug-in Info ---------------------------------
  // name of plugin
  function getName() {
    return 'ItemOptionTestCase2';
  }
  
  // author of plugin
  function getAuthor() {
    return 'TeRanEX';
  }
  // an URL to the plugin website
  function getURL() {
    return 'http://budts.be/weblog/';
  }
  
  // version of the plugin
  function getVersion() {
    return '0.1';
  }
  
  // a description to be shown on the installed plugins listing
  function getDescription() {
    return 'A plugin to test the itemoptions';
  }
  
  //supported features
  function supportsFeature($what) {
    switch($what) {
      case 'SqlTablePrefix':
        return 1;
      default:
        return 0;
    }
  }
  
  function getMinNucleusVersion() {
    return 250;
  }
// --------- Install and Uninstall functions --------------
  function install() {
    $this->createItemOption('TestCase', 'TestCaseOption', 'text', 'testing');
  }
  

// --------- do...-Functions ------------------------------
  function doTemplateVar(&$item) {
      //currently we do nothing :-)
	  echo $this->getItemOption($item->itemid, 'TestCase');
  }
  
}
?>