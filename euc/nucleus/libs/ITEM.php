<?php

/**
  * Nucleus: PHP/MySQL Weblog CMS (http://nucleuscms.org/) 
  * Copyright (C) 2002-2004 The Nucleus Group
  *
  * This program is free software; you can redistribute it and/or
  * modify it under the terms of the GNU General Public License
  * as published by the Free Software Foundation; either version 2
  * of the License, or (at your option) any later version.
  * (see nucleus/documentation/index.html#license for more info)
  *
  * A class representing an item
  *
  * $Id: ITEM.php,v 1.1.1.1 2005-02-28 07:14:11 kimitake Exp $
  */
class ITEM {
	
	var $itemid;
	
	function ITEM($itemid) {
		$this->itemid = $itemid;
	}
	
	/**
	  * Returns one item with the specific itemid
	  * (static)
	  */
	function getitem($itemid, $allowdraft, $allowfuture) {
		global $manager;

		$itemid = intval($itemid);
		
		$query =  'SELECT i.idraft as draft, i.inumber as itemid, i.iclosed as closed, '
		       . ' i.ititle as title, i.ibody as body, m.mname as author, '
		       . ' i.iauthor as authorid, i.itime, i.imore as more, i.ikarmapos as karmapos, '
		       . ' i.ikarmaneg as karmaneg, i.icat as catid, i.iblog as blogid '
		       . ' FROM '.sql_table('item').' as i, '.sql_table('member').' as m, ' . sql_table('blog') . ' as b '
		       . ' WHERE i.inumber=' . $itemid
		       . ' and i.iauthor=m.mnumber '
		       . ' and i.iblog=b.bnumber';
		       
		if (!$allowdraft)
			$query .= ' and i.idraft=0';
			
		if (!$allowfuture) {
			$blog =& $manager->getBlog(getBlogIDFromItemID($itemid));
			$query .= ' and i.itime <=' . mysqldate($blog->getCorrectTime());		
		}
		
		$query .= ' LIMIT 1';

		$res = sql_query($query);

		if (mysql_num_rows($res) == 1)
		{
			$aItemInfo = mysql_fetch_assoc($res);
			$aItemInfo['timestamp'] = strtotime($aItemInfo['itime']);	
			return $aItemInfo;
		} else {
			return 0;
		}

	}	
	
	/**
	 * Tries to create an item from the data in the current request (comes from
	 * bookmarklet or admin area 
	 *
	 * Returns an array with status info (status = 'added', 'error', 'newcategory')
	 *
	 * (static)
	 */
	function createFromRequest() {
		 global $member, $manager;
		 
	 	 $i_author = 		$member->getID();
		 $i_body = 			postVar('body');
		 $i_title =			postVar('title');
		 $i_more = 			postVar('more');
		 $i_actiontype = 	postVar('actiontype');		 
		 $i_closed = 		intPostVar('closed');
		 $i_hour = 			intPostVar('hour');		 
		 $i_minutes = 		intPostVar('minutes');		 
		 $i_month = 		intPostVar('month');		 
		 $i_day = 			intPostVar('day');		 
		 $i_year = 			intPostVar('year');		 		 

		 $i_catid = 		postVar('catid');
		 
		 if (!$member->canAddItem($i_catid))
		 	return array('status' => 'error', 'message' => _ERROR_DISALLOWED);
		 
		 if (!$i_actiontype) $i_actiontype = 'addnow';

		 switch ($i_actiontype) {
		 	case 'adddraft':
		 		$i_draft = 1;
		 		break;
		 	case 'addfuture':
		 	case 'addnow':
		 	default:
		 		$i_draft = 0;
		 }
		 
		 if (!trim($i_body))
		 	return array('status' => 'error', 'message' => _ERROR_NOEMPTYITEMS);
		 
		// create new category if needed 
		if (strstr($i_catid,'newcat')) {
			// get blogid 
			list($i_blogid) = sscanf($i_catid,"newcat-%d");
			
			// create
			$blog =& $manager->getBlog($i_blogid);
			$i_catid = $blog->createNewCategory();

			// show error when sth goes wrong
			if (!$i_catid) 
				return array('status' => 'error','message' => 'Could not create new category');
		} else {
			// force blogid (must be same as category id)
			$i_blogid = getBlogIDFromCatID($i_catid);
			$blog =& $manager->getBlog($i_blogid);
		}
		
		if ($i_actiontype == 'addfuture') {
			$posttime = mktime($i_hour, $i_minutes, 0, $i_month, $i_day, $i_year);
			
			// make sure the date is in the future, unless we allow past dates 
			if ((!$blog->allowPastPosting()) && ($posttime < $blog->getCorrectTime()))
				$posttime = $blog->getCorrectTime();
		} else {
			// time with offset, or 0 for drafts
			$posttime = $i_draft ? 0 : $blog->getCorrectTime();	
		}
		
	 	$itemid = $blog->additem($i_catid, $i_title,$i_body,$i_more,$i_blogid,$i_author,$posttime,$i_closed,$i_draft);	
	 	
		//Setting the itemOptions
		$aOptions = requestArray('plugoption');
		NucleusPlugin::_applyPluginOptions($aOptions, $itemid);
		$manager->notify('PostPluginOptionsUpdate',array('context' => 'item', 'itemid' => $itemid, 'item' => array('title' => $i_title, 'body' => $i_body, 'more' => $i_more, 'closed' => $i_closed, 'catid' => $i_catid)));
	 	
	 	// success
	 	if ($i_catid != intRequestVar('catid'))
		 	return array('status' => 'newcategory', 'itemid' => $itemid, 'catid' => $i_catid);
		else
			return array('status' => 'added', 'itemid' => $itemid);
	}
	
	
	/**
	  * Updates an item (static)
	  */
	function update($itemid, $catid, $title, $body, $more, $closed, $wasdraft, $publish, $timestamp = 0) {
		global $manager;
		
		$itemid = intval($itemid);

		// make sure value is 1 or 0
		if ($closed != 1) $closed = 0;
			
		// get destination blogid 
		$new_blogid = getBlogIDFromCatID($catid);
		$old_blogid = getBlogIDFromItemID($itemid);
		
		// move will be done on end of method
		if ($new_blogid != $old_blogid)
			$moveNeeded = 1;
		
		// add <br /> before newlines
		$blog =& $manager->getBlog($new_blogid);
		if ($blog->convertBreaks()) {
			$body = addBreaks($body);
			$more = addBreaks($more);
		}
	
		// call plugins
		$manager->notify('PreUpdateItem',array('itemid' => $itemid, 'title' => &$title, 'body' => &$body, 'more' => &$more, 'blog' => &$blog, 'closed' => &$closed, 'catid' => &$catid));
	
		// update item itsself
		$query =  'UPDATE '.sql_table('item')
		       . ' SET' 
		       . " ibody='". addslashes($body) ."',"
		       . " ititle='" . addslashes($title) . "',"
		       . " imore='" . addslashes($more) . "',"
		       . " iclosed=" . intval($closed) . ","
		       . " icat=" . intval($catid);

		// if we received an updated timestamp in the past, but past posting is not allowed,
		// reject that date change (timestamp = 0 will make sure the current date is kept)
		if ( (!$blog->allowPastPosting()) && ($timestamp < $blog->getCorrectTime()))
				$timestamp = 0;
		
		if ($wasdraft && $publish) {
			$query .= ', idraft=0';
			
			// set timestamp to current date only if it's not a future item
			// draft items have timestamp == 0
			// don't allow timestamps in the past (unless otherwise defined in blogsettings)
			if ($timestamp > $blog->getCorrectTime())
				$isFuture = 1;
			
			if ($timestamp == 0)
				$timestamp = $blog->getCorrectTime();
			
			// send new item notification
			if (!$isFuture && $blog->getNotifyAddress() && $blog->notifyOnNewItem()) 
				$blog->sendNewItemNotification($itemid, $title, $body);
		}
		
		// update timestamp when needed
		if ($timestamp != 0)
			$query .= ", itime=" . mysqldate($timestamp);	

		// make sure the correct item is updated			
		$query .= ' WHERE inumber=' . $itemid;
		
		// off we go!
		sql_query($query);	
		
		// when needed, move item and comments to new blog
		if ($moveNeeded) 
			ITEM::move($itemid, $catid);
		
		//update the itemOptions
		$aOptions = requestArray('plugoption');
		NucleusPlugin::_applyPluginOptions($aOptions);
		$manager->notify('PostPluginOptionsUpdate',array('context' => 'item', 'itemid' => $itemid, 'item' => array('title' => $title, 'body' => $body, 'more' => $more, 'closed' => $closed, 'catid' => $catid)));
		
	}
	
	// move an item to another blog (no checks, static)
	function move($itemid, $new_catid) {
		global $manager;
		
		$itemid = intval($itemid);
		$new_catid = intval($new_catid);
		
		$new_blogid = getBlogIDFromCatID($new_catid);

		$manager->notify(
			'PreMoveItem',
			array(
				'itemid' => $itemid,
				'destblogid' => $new_blogid,
				'destcatid' => $new_catid
			)
		);
	
	
		// update item table
		$query = 'UPDATE '.sql_table('item')." SET iblog=$new_blogid, icat=$new_catid WHERE inumber=$itemid";
		sql_query($query);		
		
		// update comments
		$query = 'UPDATE '.sql_table('comment')." SET cblog=" . $new_blogid." WHERE citem=" . $itemid;
		sql_query($query);	
		
		$manager->notify(
			'PostMoveItem',
			array(
				'itemid' => $itemid,
				'destblogid' => $new_blogid,
				'destcatid' => $new_catid
			)
		);		
	}
	
	/**
	  * Deletes an item
	  */
	function delete($itemid) {
		global $manager;
		
		$itemid = intval($itemid);
		
		$manager->notify('PreDeleteItem', array('itemid' => $itemid));
		
		// delete item
		$query = 'DELETE FROM '.sql_table('item').' WHERE inumber=' . $itemid;
		sql_query($query);

		// delete the comments associated with the item
		$query = 'DELETE FROM '.sql_table('comment').' WHERE citem=' . $itemid;
		sql_query($query);	  
		
		// delete all associated plugin options
		NucleusPlugin::_deleteOptionValues('item', $itemid);
		
		$manager->notify('PostDeleteItem', array('itemid' => $itemid));		
	}
	
	// returns true if there is an item with the given ID (static)
	function exists($id,$future,$draft) {
		global $manager;
		
		$id = intval($id);
		
		$r = 'select * FROM '.sql_table('item').' WHERE inumber='.$id;
		if (!$future) {
			$bid = getBlogIDFromItemID($id);
			if (!$bid) return 0;
			$b =& $manager->getBlog($bid);
			$r .= ' and itime<='.mysqldate($b->getCorrectTime());
		}
		if (!$draft) {
			$r .= ' and idraft=0';
		}
		$r = sql_query($r);

		return (mysql_num_rows($r) != 0);
	}	
	
}

?>