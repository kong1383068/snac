#!/usr/bin/env php
<?php
/**
 * Refresh the Elatic Search Indices
 *
 * This script is more "dirty" to be efficient in rebuilding the Elastic Search index.  It queries
 * the postgres database directly to get required information to build the elastic search indices.
 *
 * It fills two indices by default: the base search index for UI interaction and the all names index
 * for identity reconciliation.
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

// SNAC Wiki Util
$wikiUtil = new \snac\server\util\WikipediaUtil();

// ElasticSearch Handler
$eSearch = null;

$primaryCount = 0;
$secondaryCount = 0;
$primaryStart = false;
$secondaryStart = false;
$primaryBody = array();
$secondaryBody = array();
if (\snac\Config::$USE_ELASTIC_SEARCH) {
    $eSearch = Elasticsearch\ClientBuilder::create()
        ->setHosts([\snac\Config::$ELASTIC_SEARCH_URI])
        ->setRetries(0)
        ->build();

    echo "Trying to delete the Elastic Search Index: " . \snac\Config::$ELASTIC_SEARCH_BASE_INDEX . "\n";
    try {
        $params = array("index" => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX);
        $response = $eSearch->indices()->delete($params);
        echo "   - deleted search index\n";
    } catch (\Exception $e) {
        echo "   - could not delete search index. It did not exist.\n";
    }

    $vocab = array();
    echo "Querying vocabulary cache from the database.\n";

    $vocQuery = $db->query("select distinct id, value from
                vocabulary where type in ('subject', 'function', 'occupation');", array());
    while($v = $db->fetchrow($vocQuery))
    {
        $vocab[$v["id"]] = $v["value"];
    }

    $counts = array();

    echo "Querying the relation degrees from the database.\n";

    $allRelCount = $db->query("select a.ic_id, count(*) as degree from
                (select r.id, r.ic_id from
                    related_identity r,
                    (select distinct id, max(version) as version from related_identity group by id) a
                    where a.id = r.id and a.version = r.version and not r.is_deleted) a
                    group by ic_id", array());
    while($c = $db->fetchrow($allRelCount))
    {
        $counts[$c["ic_id"]] = array();
        $counts[$c["ic_id"]]["degree"] = $c["degree"];
    }

    echo "Querying the resource relation degrees from the database.\n";

    $allRelCount = $db->query("select a.ic_id, count(*) as degree from
                (select r.id, r.ic_id from
                    related_resource r,
                    (select distinct id, max(version) as version from related_resource group by id) a
                    where a.id = r.id and a.version = r.version and not r.is_deleted) a
                    group by ic_id", array());
    while($c = $db->fetchrow($allRelCount))
    {
        $counts[$c["ic_id"]]["resources"] = $c["degree"];
    }

    echo "Querying the controlled vocabulary terms (sub, fun, occ) from the database:";

    $vocabQuery = $db->query("select a.ic_id, a.term_id from
                (select v.id, v.ic_id, v.term_id from
                    subject v,
                    (select distinct id, max(version) as version from subject group by id) a
                    where a.id = v.id and a.version = v.version and not v.is_deleted) a;", array());

    while($v = $db->fetchrow($vocabQuery))
    {
        if (!isset($counts[$v["ic_id"]]["subject"]))
            $counts[$v["ic_id"]]["subject"] = array();
        array_push($counts[$v["ic_id"]]["subject"], $vocab[$v["term_id"]]);
    }

    echo ".";

    $vocabQuery = $db->query("select a.ic_id, a.term_id from
                (select v.id, v.ic_id, v.occupation_id as term_id from
                    occupation v,
                    (select distinct id, max(version) as version from occupation group by id) a
                    where a.id = v.id and a.version = v.version and not v.is_deleted) a;", array());

    while($v = $db->fetchrow($vocabQuery))
    {
        if (!isset($counts[$v["ic_id"]]["occupation"]))
            $counts[$v["ic_id"]]["occupation"] = array();
        array_push($counts[$v["ic_id"]]["occupation"], $vocab[$v["term_id"]]);
    }

    echo ".";

    $vocabQuery = $db->query("select a.ic_id, a.term_id from
                (select v.id, v.ic_id, v.function_id as term_id from
                    function v,
                    (select distinct id, max(version) as version from function group by id) a
                    where a.id = v.id and a.version = v.version and not v.is_deleted) a where a.term_id is not null;", array());

    while($v = $db->fetchrow($vocabQuery))
    {
        if (!isset($counts[$v["ic_id"]]["function"]))
            $counts[$v["ic_id"]]["function"] = array();
        array_push($counts[$v["ic_id"]]["function"], $vocab[$v["term_id"]]);
    }

    echo ".\n";

    echo "Querying the BiogHists\n";

    $biogHistQuery = $db->query("select a.ic_id, a.text from
                (select v.id, v.ic_id, v.text from
                    biog_hist v,
                    (select distinct id, max(version) as version from biog_hist group by id) a
                    where a.id = v.id and a.version = v.version and not v.is_deleted) a;", array());

    while($v = $db->fetchrow($biogHistQuery))
    {
        if (!isset($counts[$v["ic_id"]]["biogHist"]))
            $counts[$v["ic_id"]]["biogHist"] = array();
        array_push($counts[$v["ic_id"]]["biogHist"], $v["text"]);
    }
    $wikiURLs = array();

    echo "Querying wikipedia URLs from the database.\n";

    $wikiQuery = $db->query("select distinct ic_id, uri from
                otherid where uri ilike '%wikipedia%'", array());
    while($w = $db->fetchrow($wikiQuery))
    {
        $wikiURLs[$w["ic_id"]] = $w["uri"];
    }


    $previousICID = -1;


    echo "Querying the names from the database.\n";

    $allNames = $db->query("select one.ic_id, one.version, one.ark_id, two.id as name_id, two.original, two.preference_score, one.entity_type from
        (select
            aa.is_deleted,aa.id,aa.version, aa.ic_id, aa.original, aa.preference_score
        from
            name as aa,
            (select name.id,max(name.version) as version from name
                left join (select v.id as ic_id, v.version, nrd.ark_id
                        from version_history v
                        left join (select bb.id, max(bb.version) as version from
                        (select id, version from version_history where status in ('published', 'deleted')) bb
                        group by id order by id asc) mv
                        on v.id = mv.id and v.version = mv.version
                        left join nrd on v.id = nrd.ic_id
                        where
                        v.status = 'published'
                        order by v.id asc, v.version desc) vh
                    on name.version<=vh.version and
                    name.ic_id=vh.ic_id
                group by name.id) as bb
        where
            aa.id = bb.id and
            not aa.is_deleted and
            aa.version = bb.version
        order by ic_id asc, preference_score desc, id asc) two,
        (select v.id as ic_id, v.version, n.ark_id, etv.value as entity_type
        from
            version_history v,
            (select bb.id, max(bb.version) as version from
                (select id, version from version_history where status in ('published', 'deleted')) bb
                group by id order by id asc) mv,
            vocabulary etv,
            nrd n
        where
            v.id = mv.id and
            v.version = mv.version and
            v.status = 'published' and
            v.id = n.ic_id and
            n.ark_id is not null and
            n.entity_type = etv.id) one
    where
        two.ic_id = one.ic_id
    order by
        one.ic_id asc, two.preference_score desc, two.id asc;", array());


    echo "Updating the Elastic Search indices. This may take a while...\n";
    while($name = $db->fetchrow($allNames))
    {
        // The data is ordered by ic_id and then preference score.  We will currently say the preferred name
        // is the one with the highest preference score for each ic_id.  So, if we haven't ever seen this ic_id
        // before, this is the preferred name entry for this ic.
        if (isset($counts[$name["ic_id"]]) && isset($counts[$name["ic_id"]]["degree"])) {
            $name["degree"] = (int) $counts[$name["ic_id"]]["degree"];
        } else {
            $name["degree"] = 0;
        }
        if (isset($counts[$name["ic_id"]]) && isset($counts[$name["ic_id"]]["resources"])) {
            $name["resources"] = (int) $counts[$name["ic_id"]]["resources"];
        } else {
            $name["resources"] = 0;
        }

        if ($previousICID != $name["ic_id"]) {
            indexMain($name["original"], $name["ark_id"], (int) $name["ic_id"], $name["entity_type"], $name["degree"], $name["resources"]);
        }
        indexSecondary($name["original"], $name["ark_id"], (int) $name["ic_id"], (int) $name["name_id"], $name["entity_type"], $name["degree"], $name["resources"]);
        $previousICID = $name["ic_id"];
    }

    echo "Cleaning up last bulk updates\n";
    bulkUpdate($primaryBody, $primaryCount);
    bulkUpdate($secondaryBody, $secondaryCount);


    echo "Done\n";
} else {
    echo "This version of SNAC does not currently use Elastic Search.  Please check your configuration file.\n";
}


function indexMain($nameText, $ark, $icid, $entityType, $degree, $resources) {
    global $eSearch, $primaryBody, $primaryStart, $primaryCount, $wikiURLs, $wikiUtil, $counts;

    // When adding to the main index, also ask Wikipedia for the image if they have one and add it.
    $hasImage = false;
    $imgURL = null;
    $imgMeta = null;
    if (isset($wikiURLs[$icid])) {
        list($hasImage, $imgURL, $imgMeta) = $wikiUtil->getWikiImage($ark);
    }

    if ($eSearch != null) {
        // do one first to get the index going
        if (!$primaryStart) {
            $params = [
                    'index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
                    'type' => \snac\Config::$ELASTIC_SEARCH_BASE_TYPE,
                    'id' => $icid,
                    'body' => [
                            'nameEntry' => $nameText,
                            'entityType' => $entityType,
                            'arkID' => $ark,
                            'id' => $icid,
                            'degree' => $degree,
                            'resources' => $resources,
                            'subject' => isset($counts[$icid]["subject"]) ? $counts[$icid]["subject"] : [],
                            'occupation' => isset($counts[$icid]["occupation"]) ? $counts[$icid]["occupation"] : [],
                            'function' => isset($counts[$icid]["function"]) ? $counts[$icid]["function"] : [],
                            'biogHist' => isset($counts[$icid]["biogHist"]) ? $counts[$icid]["biogHist"] : [],
                            'hasImage' => $hasImage,
                            'imageURL' => $imgURL,
                            'imageMeta' => $imgMeta,
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
                    '_index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
                    '_type' => \snac\Config::$ELASTIC_SEARCH_BASE_TYPE,
                    '_id' => $icid
                ]
            ];
            $primaryBody['body'][] = [
                'nameEntry' => $nameText,
                'entityType' => $entityType,
                'arkID' => $ark,
                'id' => $icid,
                'degree' => $degree,
                'resources' => $resources,
                'subject' => isset($counts[$icid]["subject"]) ? $counts[$icid]["subject"] : [],
                'occupation' => isset($counts[$icid]["occupation"]) ? $counts[$icid]["occupation"] : [],
                'function' => isset($counts[$icid]["function"]) ? $counts[$icid]["function"] : [],
                'biogHist' => isset($counts[$icid]["biogHist"]) ? $counts[$icid]["biogHist"] : [],
                'hasImage' => $hasImage,
                'imageURL' => $imgURL,
                'imageMeta' => $imgMeta,
                'timestamp' => date("c")
            ];
            $primaryCount++;
        }
    }
}


function indexSecondary($nameText, $ark, $icid, $nameid, $entityType, $degree, $resources) {
    global $eSearch, $secondaryBody, $secondaryStart, $secondaryCount;
    if ($eSearch != null) {
        // do one first to get the index going
        if (!$secondaryStart) {
            $params = [
                    'index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
                    'type' => \snac\Config::$ELASTIC_SEARCH_ALL_TYPE,
                    'id' => $nameid,
                    'body' => [
                            'nameEntry' => $nameText,
                            'entityType' => $entityType,
                            'arkID' => $ark,
                            'id' => $icid,
                            'name_id' => $nameid,
                            'degree' => $degree,
                            'resources' => $resources,
                            'timestamp' => date("c")
                    ]
            ];

            $eSearch->index($params);
            $secondaryStart = true;
        } else {
            if ($secondaryCount == 100000) {
                echo "  Running Secondary bulk update\n";
                bulkUpdate($secondaryBody, $secondaryCount);
            }
            // elasticsearch api = array with "index" => array(information), followed by array of data, then repeated
            $secondaryBody['body'][] = [
                'index' => [
                    '_index' => \snac\Config::$ELASTIC_SEARCH_BASE_INDEX,
                    '_type' => \snac\Config::$ELASTIC_SEARCH_ALL_TYPE,
                    '_id' => $nameid
                ]
            ];
            $secondaryBody['body'][] = [
                'nameEntry' => $nameText,
                'entityType' => $entityType,
                'arkID' => $ark,
                'id' => $icid,
                'name_id' => $nameid,
                'degree' => $degree,
                'resources' => $resources,
                'timestamp' => date("c")
            ];
            $secondaryCount++;
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
