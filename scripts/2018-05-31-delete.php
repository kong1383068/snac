#!/usr/bin/env php
<?php
/**
 * Delete additional relations 
 *
 * @author Robbie Hott
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */
// Include the global autoloader generated by composer
include "../vendor/autoload.php";

use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;

// Set up the global log stream
$log = new StreamHandler("2018-05-31-update.log", Logger::WARNING);

// SNAC Database Connectior
$db = new snac\server\database\DatabaseConnector();

$count = 0;

$res = $db->query("select id from resource_cache where (href is null or href = '') and type = 697;", array());
echo "prepare delete_data(int) as delete from related_resource where resource_id = $1;\n";
echo "begin;\n";
while (($row = $db->fetchRow($res)) != null) {
    echo "execute delete_data({$row["id"]});\n";
    if ($count != 0 && $count++ % 50000 == 0) {
        echo "commit;\n";
        echo "begin;\n";
    }
}
echo "commit;\n";
