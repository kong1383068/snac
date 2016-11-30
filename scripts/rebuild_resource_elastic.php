#!/usr/bin/env php
<?php
/**
 * Refresh the Elatic Search Resource Indices
 *
 * This script is more "dirty" to be efficient in rebuilding the Elastic Search index.  It queries
 * the postgres database directly to get required information to build the elastic search indices.
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

// SNAC Postgres DB Connector
$db = new \snac\server\database\DatabaseConnector();

// ElasticSearch Handler
$eSearch = null;

$primaryCount = 0;
$primaryStart = false;
$primaryBody = array();
if (\snac\Config::$USE_ELASTIC_SEARCH) {
    $eSearch = Elasticsearch\ClientBuilder::create()
        ->setHosts([\snac\Config::$ELASTIC_SEARCH_URI])
        ->setRetries(0)
        ->build();

    echo "Trying to delete the Elastic Search Index: " . \snac\Config::$ELASTIC_SEARCH_RESOURCE_INDEX . "\n";
    try {
        $params = array("index" => \snac\Config::$ELASTIC_SEARCH_RESOURCE_INDEX);
        $response = $eSearch->indices()->delete($params);
        echo "   - deleted resource search index\n";
    } catch (\Exception $e) {
        echo "   - could not delete resource search index. It did not exist.\n";
    }
    
    echo "Querying the resources from the database.\n";
    
    $allResources = $db->query("select rc.*, tv.value as document_type from 
                    (select r.id, r.title, r.href, r.abstract, r.type from
                        resource_cache r,
                        (select distinct id, max(version) as version from resource_cache group by id) a
                        where a.id = r.id and a.version = r.version and not r.is_deleted) rc
                        left join vocabulary tv on rc.type = tv.id", array());



    echo "Updating the Elastic Search indices. This may take a while...\n";
    while($resource = $db->fetchrow($allResources))
    {
        indexMain((int) $resource["id"], $resource["title"], $resource["abstract"], $resource["href"], $resource["document_type"], (int) $resource["type"]);
    }

    echo "Cleaning up last bulk updates\n";
    bulkUpdate($primaryBody, $primaryCount);


    echo "Done\n";
} else {
    echo "This version of SNAC does not currently use Elastic Search.  Please check your configuration file.\n";
}

function indexMain($id, $title, $abstract, $url, $type, $typeid) {
    global $eSearch, $primaryBody, $primaryStart, $primaryCount;
    if ($eSearch != null) {
        // do one first to get the index going
        if (!$primaryStart) {
            $params = [
                    'index' => \snac\Config::$ELASTIC_SEARCH_RESOURCE_INDEX,
                    'type' => \snac\Config::$ELASTIC_SEARCH_RESOURCE_TYPE,
                    'id' => $id,
                    'body' => [
                            'id' => $id,
                            'title' => $title,
                            'abstract' => $abstract,
                            'url' => $url,
                            'type' => $type,
                            'type_id' => $typeid,
                            'timestamp' => date("c")
                    ]
            ];

            $eSearch->index($params);
            $primaryStart = true;
        } else {
            if ($primaryCount == 100000) {
                echo "  Running Primary bulk update\n";
                bulkUpdate($primaryBody, $primaryCount);
            }
            $primaryBody['body'][] = [
                'index' => [
                    '_index' => \snac\Config::$ELASTIC_SEARCH_RESOURCE_INDEX,
                    '_type' => \snac\Config::$ELASTIC_SEARCH_RESOURCE_TYPE,
                    '_id' => $id
                ]
            ];
            $primaryBody['body'][] = [
                'id' => $id,
                'title' => $title,
                'abstract' => $abstract,
                'url' => $url,
                'type' => $type,
                'type_id' => $typeid,
                'timestamp' => date("c")
            ];
            $primaryCount++;
        }
    }
}

function bulkUpdate(&$body, &$count) {
    global $eSearch;
    if ($eSearch != null) {
        $count = 0;

        $responses = $eSearch->bulk($body);

        // erase the old bulk request
        $body = array();

        // unset the bulk response when you are done to save memory
        unset($responses);
    }
}

