<?php
/*
 * Nucleus: PHP/MySQL Weblog CMS (http://nucleuscms.org/)
 * Copyright (C) 2002-2006 The Nucleus Group
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * (see nucleus/documentation/index.html#license for more info)
 */
/**
 * Scripts to create/restore a backup of the Nucleus database
 *
 * Based on code in phpBB (http://phpBB.sourceforge.net)
 *
 * @license http://nucleuscms.org/license.txt GNU General Public License
 * @copyright Copyright (C) 2002-2006 The Nucleus Group
 * @version $Id: backup.php,v 1.6 2006-12-11 22:23:49 kmorimatsu Exp $
 * $NucleusJP: backup.php,v 1.5 2006/07/17 20:03:44 kimitake Exp $
 */


/**
  * This function creates an sql dump of the database and sends it to
  * the user as a file (can be gzipped if they want)
  *
  * @requires
  *		no output may have preceded (new headers are sent)
  * @param gzip
  *		1 = compress backup file, 0 = no compression (default)
  */
function do_backup($gzip = 0) {
	global $manager;

	// tables of which backup is needed
	$tables = array(
					sql_table('actionlog'),
					sql_table('ban'),
					sql_table('blog'),
					sql_table('comment'),
					sql_table('config'),
					sql_table('item'),
					sql_table('karma'),
					sql_table('member'),
					sql_table('skin'),
					sql_table('skin_desc'),
					sql_table('team'),
					sql_table('template'),
					sql_table('template_desc'),
					sql_table('plugin'),
					sql_table('plugin_event'),
					sql_table('plugin_option'),
					sql_table('plugin_option_desc'),
					sql_table('category'),
					sql_table('activation'),
					sql_table('tickets'),
			  );

	// add tables that plugins want to backup to the list
	// catch all output generated by plugins
	ob_start();
	$res = sql_query('SELECT pfile FROM '.sql_table('plugin'));
	while ($plugName = mysql_fetch_object($res)) {
		$plug =& $manager->getPlugin($plugName->pfile);
		if ($plug) $tables = array_merge($tables, (array) $plug->getTableList());
	}
	ob_end_clean();

	// remove duplicates
	$tables = array_unique($tables);

	// make sure browsers don't cache the backup
	header("Pragma: no-cache");

	// don't allow gzip compression when extension is not loaded
	if (($gzip != 0) && !extension_loaded("zlib"))
		$gzip = 0;



	if ($gzip) {
		// use an output buffer
		@ob_start();
		@ob_implicit_flush(0);

		// set filename
		$filename = 'nucleus_db_backup_'.strftime("%Y%m%d", time()).".sql.gz";
	} else {
		$filename = 'nucleus_db_backup_'.strftime("%Y%m%d", time()).".sql";
	}


	// send headers that tell the browser a file is coming
	header("Content-Type: text/x-delimtext; name=\"$filename\"");
	header("Content-disposition: attachment; filename=$filename");

	// dump header
	echo "#\n";
	echo "# This is a backup file generated by Nucleus \n";
	echo "# http://www.nucleuscms.org/\n";
	echo "#\n";
	echo "# backup-date: " .  gmdate("d-m-Y H:i:s", time()) . " GMT\n";
	global $nucleus;
	echo "# Nucleus CMS version: " . $nucleus['version'] . "\n";
	echo "#\n";
	echo "# WARNING: Only try to restore on servers running the exact same version of Nucleus\n";
	echo "#\n";

	// dump all tables
	reset($tables);
	array_walk($tables, '_backup_dump_table');

	if($gzip)
	{
		$Size = ob_get_length();
		$Crc = crc32(ob_get_contents());
		$contents = gzcompress(ob_get_contents());
		ob_end_clean();
		echo "\x1f\x8b\x08\x00\x00\x00\x00\x00".substr($contents, 0, strlen($contents) - 4).gzip_PrintFourChars($Crc).gzip_PrintFourChars($Size);
	}

	exit;

}


/**
  * Creates a dump for a single table
  * ($tablename and $key are filled in by array_walk)
  */
function _backup_dump_table($tablename, $key) {

	echo "#\n";
	echo "# TABLE: " . $tablename . "\n";
	echo "#\n";

	// dump table structure
	_backup_dump_structure($tablename);

	// dump table contents
	_backup_dump_contents($tablename);
}

function _backup_dump_structure($tablename) {

	// add command to drop table on restore
	echo "DROP TABLE IF EXISTS $tablename;\n";
	echo "CREATE TABLE $tablename(\n";

	//
	// Ok lets grab the fields...
	//
	$result = mysql_query("SHOW FIELDS FROM $tablename");
	$row = mysql_fetch_array($result);
	while ($row) {

		echo '	' . $row['Field'] . ' ' . $row['Type'];

		if(isset($row['Default']))
			echo ' DEFAULT \'' . $row['Default'] . '\'';

		if($row['Null'] != "YES")
			echo ' NOT NULL';

		if($row['Extra'] != "")
			echo ' ' . $row['Extra'];

		$row = mysql_fetch_array($result);

		// add comma's except for last one
		if ($row)
			echo ",\n";
	}

	//
	// Get any Indexed fields from the database...
	//
	$result = mysql_query("SHOW KEYS FROM $tablename");
	while($row = mysql_fetch_array($result)) {
		$kname = $row['Key_name'];

		if(($kname != 'PRIMARY') && ($row['Non_unique'] == 0))
			$kname = "UNIQUE|$kname";
		if(($kname != 'PRIMARY') && ($row['Index_type'] == 'FULLTEXT'))
			$kname = "FULLTEXT|$kname";

		if(!is_array($index[$kname]))
			$index[$kname] = array();

		$index[$kname][] = $row['Column_name'];
	}

	while(list($x, $columns) = @each($index)) {
		echo ", \n";

		if($x == 'PRIMARY')
			echo '	PRIMARY KEY (' . implode($columns, ', ') . ')';
		elseif (substr($x,0,6) == 'UNIQUE')
			echo '	UNIQUE KEY ' . substr($x,7) . ' (' . implode($columns, ', ') . ')';
		elseif (substr($x,0,8) == 'FULLTEXT')
			echo '	FULLTEXT KEY ' . substr($x,9) . ' (' . implode($columns, ', ') . ')';
		elseif (($x == 'ibody') || ($x == 'cbody'))			// karma 2004-05-30 quick and dirty fix. fulltext keys were not in SQL correctly.
			echo '	FULLTEXT KEY ' . substr($x,9) . ' (' . implode($columns, ', ') . ')';
		else
			echo "	KEY $x (" . implode($columns, ', ') . ')';
	}

	echo "\n);\n\n";
}

/**
 * Returns the field named for the given table in the 
 * following format:
 *
 * (column1, column2, ..., columnn)
 */
function _backup_get_field_names($result, $num_fields) {

	if (function_exists('mysqli_fetch_fields') ) {
		
		$fields = mysqli_fetch_fields($result);
		for ($j = 0; $j < $num_fields; $j++)
			$fields[$j] = $fields[$j]->name;

	} else {

		$fields = array();
		for ($j = 0; $j < $num_fields; $j++) {
			$fields[] = mysql_field_name($result, $j);
		}

	}
	
	return '(' . implode(', ', $fields) . ')';	
}

function _backup_dump_contents($tablename) {
	//
	// Grab the data from the table.
	//
	$result = mysql_query("SELECT * FROM $tablename");

	if(mysql_num_rows($result) > 0)
		echo "\n#\n# Table Data for $tablename\n#\n";
		
	$num_fields = mysql_num_fields($result);
	
	//
	// Compose fieldname list
	//
	$tablename_list = _backup_get_field_names($result, $num_fields);
		
	//
	// Loop through the resulting rows and build the sql statement.
	//
	while ($row = mysql_fetch_array($result))
	{
		// Start building the SQL statement.

		echo "INSERT INTO $tablename $tablename_list VALUES(";

		// Loop through the rows and fill in data for each column
		for ($j = 0; $j < $num_fields; $j++) {
			if(!isset($row[$j])) {
				// no data for column
				echo ' NULL';
			} elseif ($row[$j] != '') {
				// data
				echo " '" . addslashes($row[$j]) . "'";
			} else {
				// empty column (!= no data!)
				echo "''";
			}

			// only add comma when not last column
			if ($j != ($num_fields - 1))
				echo ",";
		}

		echo ");\n";

	}


	echo "\n";

}

// copied from phpBB
function gzip_PrintFourChars($Val)
{
	for ($i = 0; $i < 4; $i ++)
	{
		$return .= chr($Val % 256);
		$Val = floor($Val / 256);
	}
	return $return;
}

function do_restore() {

	$uploadInfo = postFileInfo('backup_file');

	// first of all: get uploaded file:
	if (empty($uploadInfo['name']))
		return 'No file uploaded';
	if (!is_uploaded_file($uploadInfo['tmp_name']))
		return 'No file uploaded';

	$backup_file_name = $uploadInfo['name'];
	$backup_file_tmpname = $uploadInfo['tmp_name'];
	$backup_file_type = $uploadInfo['type'];

	if (!file_exists($backup_file_tmpname))
		return 'File Upload Error';

	if (!preg_match("/^(text\/[a-zA-Z]+)|(application\/(x\-)?gzip(\-compressed)?)|(application\/octet-stream)$/is", $backup_file_type) )
		return 'The uploaded file is not of the correct type';



	if (preg_match("/\.gz/is",$backup_file_name))
		$gzip = 1;
	else
		$gzip = 0;

	if (!extension_loaded("zlib") && $gzip)
		return "Cannot decompress gzipped backup (zlib package not installed)";

	// get sql query according to gzip setting (either decompress, or not)
	if($gzip)
	{
		// decompress and read
		$gz_ptr = gzopen($backup_file_tmpname, 'rb');
		$sql_query = "";
		while( !gzeof($gz_ptr) )
			$sql_query .= gzgets($gz_ptr, 100000);
	} else {
		// just read
		$fsize = filesize($backup_file_tmpname);
		if ($fsize <= 0)
			$sql_query = '';
		else
			$sql_query = fread(fopen($backup_file_tmpname, 'r'), $fsize);
	}

	// time to execute the query
	_execute_queries($sql_query);
}

function _execute_queries($sql_query) {
	if (!$sql_query) return;

	// Strip out sql comments...
	$sql_query = remove_remarks($sql_query);
	$pieces = split_sql_file($sql_query);

	$sql_count = count($pieces);
	for($i = 0; $i < $sql_count; $i++)
	{
		$sql = trim($pieces[$i]);

		if(!empty($sql) and $sql[0] != "#")
		{
			// DEBUG
//			debug("Executing: " . htmlspecialchars($sql) . "\n");

			$result = mysql_query($sql);
			if (!$result) debug('SQL Error: ' . mysql_error());

		}
	}

}

//
// remove_remarks will strip the sql comment lines out of an uploaded sql file
//
function remove_remarks($sql)
{
	$lines = explode("\n", $sql);

	// try to keep mem. use down
	$sql = "";

	$linecount = count($lines);
	$output = "";

	for ($i = 0; $i < $linecount; $i++)
	{
		if (($i != ($linecount - 1)) || (strlen($lines[$i]) > 0))
		{
			if ($lines[$i][0] != "#")
			{
				$output .= $lines[$i] . "\n";
			}
			else
			{
				$output .= "\n";
			}
			// Trading a bit of speed for lower mem. use here.
			$lines[$i] = "";
		}
	}

	return $output;

}


//
// split_sql_file will split an uploaded sql file into single sql statements.
// Note: expects trim() to have already been run on $sql.
//
// taken from phpBB
//
function split_sql_file($sql)
{
	// Split up our string into "possible" SQL statements.
	$tokens = explode( ";", $sql);

	// try to save mem.
	$sql = "";
	$output = array();

	// we don't actually care about the matches preg gives us.
	$matches = array();

	// this is faster than calling count($tokens) every time thru the loop.
	$token_count = count($tokens);
	for ($i = 0; $i < $token_count; $i++)
	{
		// Don't wanna add an empty string as the last thing in the array.
		if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0)))
		{

			// even number of quotes means a complete SQL statement
			if (_evenNumberOfQuotes($tokens[$i]))
			{
				$output[] = $tokens[$i];
				$tokens[$i] = ""; 	// save memory.
			}
			else
			{
				// incomplete sql statement. keep adding tokens until we have a complete one.
				// $temp will hold what we have so far.
				$temp = $tokens[$i] .  ";";
				$tokens[$i] = "";	// save memory..

				// Do we have a complete statement yet?
				$complete_stmt = false;

				for ($j = $i + 1; (!$complete_stmt && ($j < $token_count)); $j++)
				{
					// odd number of quotes means a completed statement
					// (in combination with the odd number we had already)
					if (!_evenNumberOfQuotes($tokens[$j]))
					{
						$output[] = $temp . $tokens[$j];

						// save memory.
						$tokens[$j] = "";
						$temp = "";

						// exit the loop.
						$complete_stmt = true;
						// make sure the outer loop continues at the right point.
						$i = $j;
					}
					else
					{
						// even number of unescaped quotes. We still don't have a complete statement.
						// (1 odd and 1 even always make an odd)
						$temp .= $tokens[$j] .  ";";
						// save memory.
						$tokens[$j] = "";
					}

				} // for..
			} // else
		}
	}

	return $output;
}


function _evenNumberOfQuotes($text) {
		// This is the total number of single quotes in the token.
		$total_quotes = preg_match_all("/'/", $text, $matches);
		// Counts single quotes that are preceded by an odd number of backslashes,
		// which means they're escaped quotes.
		$escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $text, $matches);

		$unescaped_quotes = $total_quotes - $escaped_quotes;
//		debug($total_quotes . "-" . $escaped_quotes . "-" . $unescaped_quotes);
		return (($unescaped_quotes % 2) == 0);
}

?>
