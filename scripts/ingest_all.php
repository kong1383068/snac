#!/usr/bin/env php
<?php
/**
 * Bulk ingest of files given on standard input
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

// Disable blocking stdout. Not quite the same as unbuffering.
stream_set_blocking(STDOUT, 0);

// Did we parse a file?
$parsedFile = false;

// SNAC Postgres DB Handler
$dbu = new snac\server\database\DBUtil();

// ElasticSearch Handler
$eSearch = null;
if (\snac\Config::$USE_ELASTIC_SEARCH) {
    $eSearch = Elasticsearch\ClientBuilder::create()
        ->setHosts([\snac\Config::$ELASTIC_SEARCH_URI])
        ->setRetries(0)
        ->build();
}

list($appUserID, $role) = $dbu->getAppUserInfo('system');
printf("appUserID: %s role: %s\n", $appUserID, $role);

if (is_dir($argv[1])) {
    printf("Opening dir: $argv[1]\n");
    $dh = opendir($argv[1]);
    printf("Done.\n");
    
    // Create new parser 
    $e = new \snac\util\EACCPFParser();
    $e->setConstellationOperation("insert");
    
    while (($short_file = readdir($dh))) {
        
        if ($short_file == '.' or $short_file == '..') {
            continue;
        }
        
        // Create a full path file name
        $filename = $argv[1]."/$short_file";

        $parsedFile = true;

        // Print out a message stating that this file is being parsed
        echo "Parsing: $filename\n";

        $constellation = $e->parseFile($filename);
        
        // Write the constellations to the DB
        $written = $dbu->writeConstellation($constellation, "bulk ingest of merged");
        
        // Update them to be published
        $dbu->writeConstellationStatus($written->getID(), "published");
        
        indexESearch($written);

        // try to help memory by freeing up the constellation
        unset($written);
    }

    echo "\nCompleted input of sample data.\n\n"; 
}

// If no file was parsed, then print the output that something went wrong
if ($parsedFile == false) {
    echo "No files in directory\n\n"
        . "Reads files from the snac merged cpf directory (1st argument),\n"
        . "then parses the files into Identity Constellations and adds them\n"
        . "to the database using standard DBUtil calls (as if it were the server).\n"
        . "Sample usage: ./ingest_all.php /path/to/directory\n\n";
}

function indexESearch($written) {
    global $eSearch;
    if ($eSearch != null) {
        $params = [
                'index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
                'type' => \snac\Config::$ELASTIC_SEARCH_BASE_TYPE,
                'id' => $written->getID(),
                'body' => [
                        'nameEntry' => $written->getPreferredNameEntry()->getOriginal(),
                        'arkID' => $written->getArk(),
                        'id' => $written->getID(),
                        'timestamp' => date("c")
                ]
        ];
    
        $eSearch->index($params);
    }
}
