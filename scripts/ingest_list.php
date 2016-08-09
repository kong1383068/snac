#!/usr/bin/env php
<?php
/**
 * Bulk ingest of ARKs given in text input file
 *
 * Given a list of ARKs as input, this script converts them to Constellation objects, and also
 * gets the list of all their Constellation Relation links and imports them.  Then, it sets up the original set's
 * Constellation Relations appropriately inside snac.
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
$log = new StreamHandler(\snac\Config::$LOG_DIR . \snac\Config::$SERVER_LOGFILE, Logger::DEBUG);

// Did we parse a file?
$parsedFile = false;

// SNAC Postgres DB Handler
$dbu = new snac\server\database\DBUtil();

// SNAC Postgres User Handler
$dbuser = new \snac\server\database\DBUser();
$tempUser = new \snac\data\User();
$tempUser->setUserName("system@localhost");
$user = $dbuser->readUser($tempUser);
$user->generateTemporarySession();

// ElasticSearch Handler
$eSearch = null;
if (\snac\Config::$USE_ELASTIC_SEARCH) {
    $eSearch = Elasticsearch\ClientBuilder::create()
        ->setHosts([\snac\Config::$ELASTIC_SEARCH_URI])
        ->setRetries(0)
        ->build();
}

// Create new parser
$e = new \snac\util\EACCPFParser();
$e->setConstellationOperation("insert");
printf("Done creating new parser.\n");

$arks = file($argv[2], FILE_IGNORE_NEW_LINES);

$seenArks = array();

$relationLimit = 350;

foreach ($arks as $ark) {

    // Use a hack to check the number of relations
    // do curl -s $ark  | grep "badge pull-right" | sed 's/^.*">//' | sed 's/<.*//' | awk '{s+=$1}END{print s}'; done

    $rels = trim(shell_exec("curl -s $ark  | grep \"badge pull-right\" | sed 's/^.*\">//' | sed 's/<.*//' | awk '{s+=$1}END{print s}'"));
    if ($rels > $relationLimit) {
        echo "Skipping: $ark (too many relations: $rels)\n";
        continue;
    }

    // Create a full path file name
    //$filename = $argv[1]."/$short_file";
    list($junk, $parts) = explode("ark:/", $ark);
    list($first, $second) = explode("/", $parts);
    $filename = $argv[1] . "/" . $first . "-" . $second . ".xml";

    $parsedFile = true;

    // Print out a message stating that this file is being parsed
    echo "Parsing: $filename (relations: $rels)\n";

    $constellation = $e->parseFile($filename);

    // Make sure it isn't already in the database
    $check = $dbu->readPublishedConstellationByARK($constellation->getArk(), true);

    $written = null;
    if ($check !== false) {
        $written = $dbu->readConstellation($check->getID());
    } else {
        // Write the constellation to the DB
        $written = $dbu->writeConstellation($user, $constellation, "bulk ingest of merged", 'ingest cpf');
    }

    // Update it to be published
    $dbu->writeConstellationStatus($user, $written->getID(), "published");

    // index ES
    indexESearch($written);

    $seenArks[$written->getID()] = $written->getArk();

    echo "   Relations: \n";

    foreach ($constellation->getRelations() as $rel) {
        $relArk = $rel->getTargetArkID();
        list($junk, $parts) = explode("ark:/", $relArk);
        $relArk = "http://socialarchive.iath.virginia.edu/" . "ark:/" . $parts;

        $rels = trim(shell_exec("curl -s $relArk  | grep \"badge pull-right\" | sed 's/^.*\">//' | sed 's/<.*//' | awk '{s+=$1}END{print s}'"));
        if ($rels > $relationLimit) {
            echo "      skipping: $relArk (relations: $rels)\n";
            continue;
        }
        echo "       parsing: $relArk (relations: $rels)\n";

        // If we haven't already seen it, it's not in the initial desired set, and it actually is an ARK
        if (!in_array($relArk, $seenArks) && !in_array($relArk, $arks) && strpos($relArk, "ark") !== false) {
            // Get filename from ARK
            list($junk, $parts) = explode("ark:/", $relArk);
            list($first, $second) = explode("/", $parts);
            $filename = $argv[1] . "/" . $first . "-" . $second . ".xml";

            // Parse the constellation
            $constellation = $e->parseFile($filename);

            // Make sure it isn't already in the database
            $check = $dbu->readPublishedConstellationByARK($constellation->getArk(), true);

            $written = null;
            if ($check !== false) {
                $written = $dbu->readConstellation($check->getID());
            } else {
                try {
                    // Write the constellation to the DB
                    $written = $dbu->writeConstellation($user, $constellation, "bulk ingest of merged", 'ingest cpf');
                } catch (\Exception $e) {
                    echo "         - silently ignoring error...\n";
                }
            }

            $dbu->writeConstellationStatus($user, $written->getID(), "published");
            indexESearch($written);
            //echo ".";

            // Push the ark onto the list of seen arks (so we don't duplicate)
            $seenArks[$written->getID()] = $written->getArk();
        }
    }
    echo "\n";
}

echo "\nFixing up Relations:\n";
// Go back and fix the constellation relations
foreach ($seenArks as $id => $ark) {
    echo "Editing: $ark ($id)\n    Relations: ";
    $constellation = $dbu->readConstellation($id);
    // For each relation, try to do a lookup
    foreach ($constellation->getRelations() as &$rel) {
        foreach ($seenArks as $oid => $oark) {
            if ($rel->getTargetArkID() == $oark) {
                $other = $dbu->readConstellation($oid, null, true);
                $rel->setTargetConstellation($oid);
                $rel->setTargetEntityType($other->getEntityType());
                $rel->setOperation(\snac\data\AbstractData::$OPERATION_UPDATE);
                echo ".";
            }
        }
    }

    // Update the constellation in the database
    try {
        // Write the constellation to the DB
        $written = $dbu->writeConstellation($user, $constellation, "updated Constellation Relations", 'locked editing');
        $dbu->writeConstellationStatus($user, $written->getID(), "published");
        indexESearch($written);
        file_put_contents("log", $written->toJSON(), FILE_APPEND);
        echo "\n    Published\n";
    } catch (\Exception $e) {
        echo "      silently ignoring error...\n";
    }
}


// If no file was parsed, then print the output that something went wrong
if ($parsedFile == false) {
    echo "No arks given\n\n"
        . "Sample usage: ./ingest_list.php /data/merge list.txt\n\n";
}

/**
 * @param \snac\data\Constellation $written
 */
function indexESearch($written) {
    global $eSearch;
    if ($eSearch != null) {
        $params = [
            'index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
            'type' => \snac\Config::$ELASTIC_SEARCH_BASE_TYPE,
            'id' => $written->getID(),
            'body' => [
                'nameEntry' => $written->getPreferredNameEntry()->getOriginal(),
                    'entityType' => $written->getEntityType()->getID(),
                    'arkID' => $written->getArk(),
                    'id' => $written->getID(),
                    'degree' => count($written->getRelations()),
                    'timestamp' => date("c")
                ]
            ];

        $eSearch->index($params);
    }
}
