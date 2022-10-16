#!/usr/bin/env  php
<?php
// twitter.com/jimaek
// www.jsdelivr.com
// Converts all virtual albums to physical and moves the associated images to galleries/
// Put in Piwigo root and run from console
// BACKUP EVERYTHING BEFORE RUNNING!!!!!!!
// I am not responsible for data loss or any other problems
// amount of photos per iteration

// note 2022-10-17:
// I updated this file, making 2 changes.
//
// I changed this line:
// $fs_dir = preg_replace("/[^\\w-_\ ]+/",'',$virt_dir);
// to
// $fs_dir = preg_replace("/[^\\w\-_\ ]/",'',$virt_dir);
//
// Further, I changed this line:
// $sql="SELECT path FROM images WHERE path='".$dbc->real_escape_string($dupe_file_path)."'";
// to
// $sql="SELECT path FROM {$prefixeTable}images WHERE path='".$dbc->real_escape_string($dupe_file_path)."'";
// due to a missing prefix
//
// Tested to work on php7.2


$limit = 20;
$site_id=1; // id directory with images [http://piwigo/admin.php?page=site_manager]

error_reporting(E_ALL);
header("Content-type: text/plain");
include('local/config/database.inc.php');

$prefixeTable = 'piwigo_';

$dbc = new mysqli($conf['db_host'],$conf['db_user'],$conf['db_password'],$conf['db_base']);

$offset = 0;
do {
	$continue = true;
	$results = array();
	$sql = "SELECT 
		i.*, c.`name`, c.`dir`,
		c.`uppercats`,
		(SELECT GROUP_CONCAT(pc.id,':::',IFNULL(pc.dir,''),':::',IFNULL(pc.name,'') SEPARATOR '///')
		FROM `{$prefixeTable}categories` pc 
		WHERE FIND_IN_SET(pc.id, c.`uppercats`)) AS full_path
		FROM `{$prefixeTable}images` i
		INNER JOIN `{$prefixeTable}image_category` ic ON (i.id = ic.`image_id`)
		INNER JOIN `{$prefixeTable}categories` c ON (ic.`category_id` = c.`id`)
	WHERE path LIKE './upload/%'
	GROUP BY i.id
	LIMIT $offset, $limit";
	//echo $sql;
	if ($result = $dbc->query($sql)) {
		if ($result->num_rows > 0) {
			$continue = true;
		}
		else {
			$continue = false;
		}
                //$results = $result->fetch_all(MYSQLI_ASSOC);
                //for PHP with mysql driver (not mysqlnd) - without fetch_all function
                for ($results = array(); $tmp = $result->fetch_array(MYSQLI_ASSOC);) $results[] = $tmp;
		$result->close();
		// 
		foreach ($results as $image) {
			// echo "\n".print_r($image, true);
			// get category path
			$filename = $image['file'];
			$full_q_path = explode('///', $image['full_path']);
			$real_path = '';
			$real_path_a = array();
			$sorted_q_path = array();
			$storage_category_id = 0;
			foreach ($full_q_path as $el) {
				$sorted_q_path[] = explode(':::', $el);
			};
			asort($sorted_q_path, SORT_ASC);
			foreach ($sorted_q_path as $el) {
				list($id, $fs_dir, $virt_dir) = $el;
				if (empty($fs_dir)) {
					$fs_dir = preg_replace("/[^\\w\-_\ ]/",'',$virt_dir);
					$fs_dir = trim($fs_dir);
					$dbc->query("UPDATE `{$prefixeTable}categories` pc SET pc.dir='".$dbc->real_escape_string($fs_dir)."', site_id = {$site_id} WHERE pc.id = {$id} ");
				}
				$real_path_a[] = $fs_dir;
				$storage_category_id = $id;
			}
			$real_path = './galleries/'. implode('/',$real_path_a);
			echo "\n".$real_path.'/'. $image['file'];
			if(is_dir($real_path)) {
				echo ' ';
			}
			else {
				mkdir($real_path, 0775, true);
				echo '+';
			}
			// check for duplicates
			$new_file_path = $real_path.'/'.$image['file'];
			$dupe_file_path = $new_file_path;
			$dupes = 1;
			while ($dupes > 0) {
				$sql="SELECT path FROM {$prefixeTable}images WHERE path='".$dbc->real_escape_string($dupe_file_path)."'";
				if ($result = $dbc->query($sql)) {
					if ($result->num_rows > 0) {
						$dupe_file_path = pathinfo($new_file_path,PATHINFO_DIRNAME) . "/" . pathinfo($new_file_path,PATHINFO_FILENAME) . $dupes++ . "." . pathinfo($new_file_path,PATHINFO_EXTENSION);
					}
					else {
						$dupes = 0;
						$new_file_path = $dupe_file_path;
						break;
					}
				}
			}
			// copy
			copy($image['path'], $new_file_path);
			echo ' [v]';
			//update path, storage category
			$dbc->query("UPDATE `{$prefixeTable}images` SET `path`='".$dbc->real_escape_string($new_file_path)."' WHERE id = {$image['id']}");
			if ($storage_category_id > 0) {
				$dbc->query("UPDATE `{$prefixeTable}images` SET storage_category_id = $storage_category_id WHERE id = {$image['id']}");
			}
			// remove old thumbnails
			$image_dir = pathinfo($image['path'], PATHINFO_DIRNAME);
			$thumb_dir = "./_data/i" . ltrim($image_dir,".");
			foreach (glob($thumb_dir . "/*") as $filename) {
				unlink($filename);
			}
			foreach (glob($thumb_dir . "/pwg_representative/*") as $filename) {
				unlink($filename);
			}
			// try deleting thumbnail directory too
			@rmdir($thumb_dir);
			@rmdir($image_dir . "/pwg_representative");
			// try deleting two parent directories (month, year)
			@rmdir(dirname($thumb_dir));
			@rmdir(dirname($thumb_dir,1));
		}
	}
	else {
		$continue = false;
	}
}
while ($continue);

$dbc->close();

?>
