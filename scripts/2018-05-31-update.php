#!/usr/bin/env php
<?php
/**
 * Fix the vocabulary 
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

echo "STEP 1: Delete empty resources\n=====================\n";
echo " DONE MANUALLY\n";


echo "\nSTEP 2: Fix holding repositories\n=====================\n";

$row = 1;
if (($handle = fopen("2018-05-31-resource-updates.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Skip the headers row
        if ($row++ == 1)
            continue;
        $url = $data[0];
        $icid = $data[2];

        if (is_numeric($icid)) {
            echo "Updating $url to $icid\n";
            $res = $db->query("update resource_cache set repo_ic_id = $1 where href like $2 and (repo_ic_id is null) returning *", array($icid, $url . "%")); 
            while (($dbrow = $db->fetchRow($res)) != null) {
                echo "    {$dbrow["id"]} :\t{$dbrow["title"]}\n";
            }
        } else {
            echo "Skipping $url\n";
        }
    }
    fclose($handle);
}


echo "\nSTEP 3: update NYSED links\n=====================\n";

$res = $db->query("select id, href from resource_cache where href like 'http://iarchives.nysed.gov/xtf/view?docId=%'", array()); 
$all = $db->fetchAll($res);
foreach ($all as $row) {
    $badurl = $row["href"];
    $id = $row["id"];

    $fn = str_replace('http://iarchives.nysed.gov/xtf/view?docId=', '', $badurl);
    $url = 'http://iarchives.nysed.gov/xtf/view?docId=ead/findingaids/' . $fn;

    $res = $db->query("update resource_cache set href = $1 where id = $2;", array( $url, $id));
    echo "Updating $id: $badurl to $url\n";
}

echo "\nSTEP 4: update LDS links\n=====================\n";

$res = $db->query("select id, href from resource_cache where href like 'http://eadview.lds.org/%'", array()); 
$all = $db->fetchAll($res);
foreach ($all as $row) {
    $badurl = $row["href"];
    $id = $row["id"];

    $fn = str_replace('http://eadview.lds.org', '', $badurl);
    $url = 'https://eadview.lds.org' . $fn;

    $res = $db->query("update resource_cache set href = $1 where id = $2;", array( $url, $id));
    echo "Updating $id: $badurl to $url\n";
}

