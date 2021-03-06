#!/usr/bin/env php
<?php
/**
 * Clean the Vocabulary
 *
 * @author Robbie Hott
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */
// Include the global autoloader generated by composer
include "../vendor/autoload.php";
include "clean_vocabulary_sub.php";

use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;

// Set up the global log stream
$log = new StreamHandler(\snac\Config::$LOG_DIR . \snac\Config::$SERVER_LOGFILE, Logger::DEBUG);

// SNAC Postgres DB Connector
$db = new \snac\server\database\DatabaseConnector();

$vocab = array();
echo "Querying vocabulary cache from the database.\n";

$vocQuery = $db->query("select id, type, value from
            vocabulary where type in ('subject', 'function', 'occupation');", array());
while($v = $db->fetchrow($vocQuery))
{
    if (!isset($vocab[$v["type"]]))
        $vocab[$v["type"]] = array();
    $vocab[$v["type"]][$v["id"]] = $v["value"];
}

echo "Current counts:\n  Subject: ".count($vocab["subject"])."\n  Functn:  ".count($vocab["function"])."\n  Occptn:  ".count($vocab["occupation"])."\n";
echo "  Total:   ". (count($vocab["subject"]) + count($vocab["function"]) + count($vocab["occupation"])) ."\n\n";

$clean = array(
    "subject" => [],
    "function" => [],
    "occupation" => []);

foreach ($vocab["subject"] as $k => $v) {
    fixup($v, $k, $clean["subject"]);
}

foreach ($vocab["function"] as $k => $v) {
    fixup($v, $k, $clean["function"]);
}

foreach ($vocab["occupation"] as $k => $v) {
    fixup($v, $k, $clean["occupation"]);
}


echo "Cleaned counts:\n  Subject: ".count($clean["subject"])."\n  Functn:  ".count($clean["function"])."\n  Occptn:  ".count($clean["occupation"])."\n";
echo "  Total:   ".(count($clean["subject"]) + count($clean["function"]) + count($clean["occupation"]))."\n\n";

usort($clean["subject"], function($a, $b) {
    return (count($a["originals"]) < count($b["originals"])) ? 1 : -1;
});
usort($clean["function"], function($a, $b) {
    return (count($a["originals"]) < count($b["originals"])) ? 1 : -1;
});

usort($clean["occupation"], function($a, $b) {
    return (count($a["originals"]) < count($b["originals"])) ? 1 : -1;
});

vote($clean["subject"]);
vote($clean["function"]);
vote($clean["occupation"]);


$sample = array("subject" => array(), "function" => array(), "occupation" => array());
for( $i = 0; $i < 10; $i++) {
    array_push($sample["subject"], $clean["subject"][$i]);
    array_push($sample["function"], $clean["function"][$i]);
    array_push($sample["occupation"], $clean["occupation"][$i]);
}

//echo json_encode($sample, JSON_PRETTY_PRINT);
echo "\n\n";

update_database($clean["subject"], "subject", "term_id");
update_database($clean["occupation"], "occupation", "occupation_id");
update_database($clean["function"], "function", "function_id");


function update_database($arr, $table, $field) {
    global $db;

    $db->prepare("updatetable", "update $table set $field = $2 where $field = $1;");
    $db->prepare("deletevocab", "delete from vocabulary where id = $1;");
    $db->prepare("updatevocab", "update vocabulary set value = $2 where id = $1;");
    // loop over each term group
    foreach ($arr as $v) {
        $id = $v["id"];
        $term = $v["chosen"];

        foreach ($v["originals"] as $o) {
            if ($id != $o["id"]) {
                $query = "update $table set $field = {$id} where $field = {$o["id"]};";
                $db->execute("updatetable", array($o["id"], $id));
                echo $query."\n";
                
                $query = "delete from vocabulary where id = {$o["id"]};";
                $db->execute("deletevocab", array($o["id"]));
                echo $query."\n";
            }
        }
        $query = "update vocabulary set value = $term where id = $id;";
        $db->execute("updatevocab", array($id, $term));
        echo $query."\n";
    }
    $db->deallocate("updatetable");
    $db->deallocate("deletevocab");
    $db->deallocate("updatevocab");

    $db->prepare("deletetable", "delete from $table where id = $1;");
    $res = $db->query("select b.ic_id, b.$field, b.count from 
                    (select a.ic_id,a.$field, count(*) from 
                        (select id, ic_id, $field, max(version) from $table
                           group by id, ic_id, $field) as a 
                        group by a.ic_id,a.$field) as b
                    where b.count > 1 order by b.count desc;", array());
    $all = $db->fetchAll($res);

    foreach ($all as $entry) {
        $res = $db->query("select id, ic_id, version, $field, is_deleted from $table where ic_id = $1 and $field = $2 order by ic_id asc, id asc, version asc;",
           array($entry["ic_id"], $entry[$field]));
        $tmp = $db->fetchAll($res);

        $info = array();
        foreach ($tmp as $t) {
            if (!isset($info[$t["id"]]))
                $info[$t["id"]] = array();
            $info[$t["id"]][$t["version"]] = ["value" => $t[$field], "del" => $t["is_deleted"]];
        }

        $deleted = array();
        foreach ($info as $k => $i) {
            foreach ($i as $vi) {    
                if ($vi["del"] == "t") {
                    array_push($deleted, $k);
                    break;
                } 
            }
        }

        // Remove all those that have already been deleted
        foreach ($deleted as $k)
            unset($info[$k]);

        $keep = true;
        foreach ($info as $k => $i) {
            if ($keep) {
                // keep the first one, delete all others
                $keep = false;
                continue;
            }

            echo "Deleting: IC {$entry["ic_id"]} => $k \n";
            $db->execute("deletetable", array($k));

        }
    }
    $db->deallocate("deletetable");
}



