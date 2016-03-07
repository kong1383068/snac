<?php
  /**
   * High level database abstraction layer.
   *
   * License:
   *
   *
   * @author Tom Laudeman
   * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
   * @copyright 2015 the Rector and Visitors of the University of Virginia, and
   *            the Regents of the University of California
   */

namespace snac\server\database;

/**
 * High level database class.
 *
 * This is what the rest of the server sees as an interface to the database. There is no SQL here. This knows
 * about data structure from two points of view: constellation php data, and tables in the
 * database. Importantly, this code has no idea where the constellation comes from, nor how data gets into the
 * database. Constellation data classes are elsewhere, and SQL is elsewhere.
 *
 * All "create" here is based on SQL select queries.
 *
 * Functions populateFoo() create an object and add it to an existing object. These functions know about
 * column names from the database (but not how SQL managed to get the column names).
 *
 * Functions saveFoo() are broad wrappers that traverse objects and save to the database via more granular
 * functions.
 *
 * Need: high level "populate", "build", "read" equivalent to saveFoo() like readFoo().
 *
 * Need: lockConstellation()
 *
 * We need a way to select the unlocked, published version. Probably best to get the version number of the
 * published, and call existing functions with the appropriate version number. 
 *
 * Functions buildFoo() create and return an object using data selected from the database
 *
 * Functions selectFoo(), updateFoo(), insertFoo() are defined in SQL.php and return an associative list where
 * the keys are column names.
 *
 * Most (or all?) of the functions in this class could be static, as long as the $db were passed in as an arg,
 * rather than being passed to the constructor.
 *
 * @author Tom Laudeman
 *
 */
class DBUtil
{
    /**
     * SQL object
     *
     * @var \snac\server\database\SQL low-level SQL class
     */
    private $sql = null;

    /**
     * Used by setDeleted() and clearDeleted() to check table name.
     *
     * @var string[] Associative list where keys are table names legal to delete from.
     *
     */
    private $canDelete = null;

    // private $ourVersion = null;
    // private $haveVersion = false;

    /**
     * Database connector object
     *
     * @var \snac\server\database\DatabaseConnector object.
     */
    private $db = null;

    /**
     * Class var to hold the appUserID
     * 
     * @var integer $appUserID holds the numeric application user id, an integer.
     */ 
    private $appUserID = null;

    /**
     * Return the appUserID
     *
     * Probably no reason to use this outside of testing.
     *
     * @return integer $appUserID
     */
    public function getAppUserID()
    {
        return $this->appUserID;
    }

    /**
     * Class var to hold the current user role
     * 
     * @var integer $roleID holds the integer role id, the id of the current user's primary role. Or in the
     * future will be one of the user's roles chosen for the current task.
     */ 
    private $roleID = null;

    /** 
     * Constructor
     *
     * The constructor for the DBUtil class.
     */
    public function __construct()
    {
        $this->db = new \snac\server\database\DatabaseConnector();
        $this->sql = new SQL($this->db);
        /*
         * DBUtil needs user id and role id for the current user. We may perform several reads and writes, and
         * they will all be done using the same user info.
         */ 
        list($this->appUserID, $this->roleID) = $this->getAppUserInfo('system');

        /*
         * Mar 4 2016 Here's a little suprise: we don't have an object for name component. How this will work
         * is not determined, so I guess we're ignoring it for now.
         *
         * 'snac\data\Foo' => 'name_component',
         *
         */ 

        /*
         * This is a list of php class and SQL table, but only classes which supported by setDeleted(). All
         * the save* and populate* functions are unique and essentially hard coded. However, setDeleted() and
         * clearDeleted() are generalized so they use this to figure out what table is associated with a given
         * class. See prepOperation(), setDeleted(), and clearDeleted().
         *
         * Table nrd and the constellation have a different mechanism, so they are not listed here.
         */ 
        $this->canDelete = array('snac\data\BiogHist' => 'biog_hist',
                                 'snac\data\ConventionDeclaration' => 'convention_declaration',
                                 'snac\data\SNACDate' => 'date_range',
                                 'snac\data\SNACFunction' => 'function',
                                 'snac\data\Gender' => 'gender',
                                 'snac\data\GeneralContext' => 'general_context',
                                 'snac\data\Language' => 'language',
                                 'snac\data\LegalStatus' => 'legal_status',
                                 'snac\data\Mandate' => 'mandate',
                                 'snac\data\NameEntry' => 'name',
                                 'snac\data\Contributor' => 'name_contributor',
                                 'snac\data\Nationality' => 'nationality',
                                 'snac\data\Occupation' => 'occupation',
                                 'snac\data\SameAs' => 'otherid',
                                 'snac\data\Place' => 'place_link',
                                 'snac\data\ConstellationRelation' => 'related_identity',
                                 'snac\data\ResourceRelation' => 'related_resource',
                                 'snac\data\SNACControlMetadata' => 'scm',
                                 'snac\data\StructureOrGenealogy' => 'structure_genealogy',
                                 'snac\data\Source' => 'source',
                                 'snac\data\Subject' => 'subject');
    }

    /**
     * Table name for a given class.
     *
     * This does two things:
     *
     * 1) return the SQL table for a class
     *
     * 2) return null if the class in question can't be deleted
     *
     * @param object $cObj Some object that we think has an associated SQL table.
     */
    private function deleteOK($cObj)
    {
        if (isset($this->canDelete[get_class($cObj)]))
        {
            return $this->canDelete[get_class($cObj)];
        }
        return null;
    }

    private function prepOperation($vhInfo, $cObj)
    {
        if ($cObj->getOperation() == \snac\data\AbstractData::$OPERATION_DELETE)
        {
            /* 
             * printf("\nhave delete for object main_id %s id: %s o-version: %s v-version: %s\n",
             *        $vhInfo['main_id'],
             *        $cObj->getID(),
             *        $cObj->getVersion(),
             *        $vhInfo['version']);
             */
            $this->setDeleted($vhInfo, $cObj);
            return false;
        }
        else
        {
            return true;
        }
    }

    /**
     * Read published by ARK
     *
     * Read a published constellation by ARK from the database.
     *
     * @param string $arkID An ARK
     *
     * @return \snac\data\Constellation A PHP constellation object.
     *
     */
    public function publishedConstellationByARK($arkID)
    {
        return null;
    }


    /**
     *
     * Read published by ID
     *
     * Read a published constellation by constellation ID (aka main_id, mainID) from the database.
     *
     * @param integer $mainID A constellation id
     *
     * @return \snac\data\Constellation A PHP constellation object.
     */ 
    public function publishedConstellationByID($mainID)
    {
        return null;
    }


    /**
     * List main_id, verion user is editing
     *
     * Build a list of main_id,version user has locked for edit by $appUserID.
     *
     * @return integer[] A list with keys 'main_id', 'version' of constellations locked for edit by $appUserID
     */ 
    private function editList()
    {
        // When you implement this, use $this->appUserID;
        return array();
    }
    
    /**
     * Constellations user is edting
     *
     * Build a list of constellations that the user is editing. That is: user has locked for edit.
     *
     * @return \snac\data\Constellation[] A list of  PHP constellation object.
     * 
     */
    function editConstellationList()
    {
        $idVersionList = editList();
        
        $constellationList = array();
        foreach ($idVersionList as $iver)
        {
            $cObj = readConstellation($iver['main_id'], $iver['version']);
            array_push($constellationList, $cObj);              
        }
        return $constellationList;
    }



    /**
     * Safely call object getID method
     *
     * Call this so we don't have to sprinkle ternary ops throughout our code. The alternative to using this
     * is for every call to getID() from a Language, Term, or Source to be made in the same ternary that is
     * inside this.  Works for any class that has a getID() method. Intended to use with Language, Term,
     * Source,
     * 
     * @param mixed $thing Some object that when not null has a getID() method.
     *
     * @return integer The record id of the thing
     */
    private function thingID($thing)
    {
        return $thing==null?null:$thing->getID();
    }


    /**
     * Get the SQL object
     *
     * Utility function to return the SQL object for this DBUtil instance. Currently only used for testing,
     * and that may be the only valid use.
     *
     * @return \snac\server\database\SQL Return the SQL object of this DBUtil instance.
     */
    public function sqlObj()
    {
        return $this->sql;
    }

    /**
     * Get entire vocabulary
     *
     * Get all the vocabulary from the database in tabular form.
     *
     * @return string[][] array of vocabulary terms and associated information
     */
    public function getAllVocabulary() {
        return $this->sql->selectAllVocabulary();
    }

    /**
     * Get user info
     *
     * This is a short term helper function which will be removed once we have real user/account
     * management. Call this as your first step after creating a new DBUtil object. See DBUtilTest.php for
     * examples.
     * 
     * Access some system-wide authentication and/or current user info.
     *
     * Hard coded for now to return id and role.
     *
     * @param string $userString The text user identifier corresponds to sql table appuser.userid, used to get
     * the appuser.id and the user's primary role.
     *
     * @return integer[] A flat list of two integers, appuser.id and role.id.
     */
    public function getAppUserInfo($userString)
    {
        $uInfo = $this->sql->selectAppUserInfo($userString);
        return $uInfo;
    }


    /**
     * Get a demo constellation
     *
     * A helper function to get a constellation from the db for testing purposes.
     *
     * @return string[] Return the standard vh_info associative list with the keys 'version' and 'main_id'
     * from the constellation.
     *
     */
    public function demoConstellation()
    {
        list($version, $mainID) = $this->sql->randomConstellationID();
        if (! $version  || ! $mainID)
        {
            printf("Error: got null(s) from randomConstellation() version: $version mainID: $mainID\n");
        }
        return array('version' => $version, 'main_id' => $mainID);
    }

    /**
     * Fill in a Constellation.
     *
     * This is private. Use readConstellation() as the public API 
     *
     * @param integer $appUserID The internal id of the user from appuser.id. Used for locking records, and checking locks.
     *
     * @return \snac\data\Constellation A PHP constellation object.
     * 
     */
    private function selectConstellation($vhInfo, $appUserID)
    {
        $cObj = new \snac\data\Constellation();
        /*
         * Must call populateNrd() first so that constellation version and id are set.  Most functions use the
         * $vhInfo arg, but populateDate(), populateSource(), and populateLanguage() rely on the internals of
         * the constellation object.
         */ 
        $this->populateNrd($vhInfo, $cObj);
        $this->populateBiogHist($vhInfo, $cObj);
        $this->populateDate($cObj); // "Constellation Date" in SQL these dates are linked to table nrd.
        $this->populateSource($cObj); // "Constellation Source" in the order of statements here
        $this->populateConventionDeclaration($vhInfo, $cObj);
        $this->populateFunction($vhInfo, $cObj);
        $this->populateGender($vhInfo, $cObj);
        $this->populateGeneralContext($vhInfo, $cObj);
        $this->populateLanguage($cObj, $cObj->getID()); // getID only works here because nrd.id=nrd.main_id
        $this->populateLegalStatus($vhInfo, $cObj);
        $this->populateMandate($vhInfo, $cObj);
        $this->populateNameEntry($vhInfo, $cObj);
        $this->populateNationality($vhInfo, $cObj);
        $this->populateOccupation($vhInfo, $cObj);
        $this->populateOtherRecordID($vhInfo, $cObj);
        $this->populatePlace($vhInfo, $cObj, $cObj->getID()); // getID only works here because nrd.id=nrd.main_id
        $this->populateStructureOrGenealogy($vhInfo, $cObj);
        $this->populateSubject($vhInfo, $cObj);
        $this->populateRelation($vhInfo, $cObj); // aka cpfRelation
        $this->populateResourceRelation($vhInfo, $cObj); // resourceRelation
        /* 
         * todo: maintenanceEvents and maintenanceStatus added to version history and managed from there.
         */
        return $cObj;
    } // end selectConstellation

    /**
     * Populate Constellation properties
     * 
     * Populate the Constellation's 1:1 properties. An existing (empty) constellation is changed in place.
     *
     * Get a constellation from the database
     *
     * Select a given constellation from the database based on version and main_id.
     * Create an empty constellation by calling the constructor with no args. Then used the setters to add
     * individual properties of the class(es).
     *
     * | php                                                    | sql                    |
     * |--------------------------------------------------------+------------------------|
     * | setArkID                                               | ark_id                 |
     * | setEntityType                                          | entity_type            |
     * |                                                        |                        |
     *
     * @param string[] $vhInfo associative list with keys 'version', 'main_id', 'id'. The version and main_id you
     * want. Note that constellation component version numbers are the max() <= version requested.  main_id is
     * the unique id across all tables in this constellation. This is not the nrd.id, but is
     * version_history.main_id which is also nrd.main_id, etc.
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */ 
    private function populateNrd($vhInfo, &$cObj)
    {
        $row = $this->sql->selectNrd($vhInfo);
        $cObj->setArkID($row['ark_id']);
        $cObj->setEntityType($this->populateTerm($row['entity_type']));
        $cObj->setID($vhInfo['main_id']); // constellation ID, $row['main_id'] has the same value.
        $cObj->setVersion($vhInfo['version']);
        $this->populateMeta($cObj);
    }

    /**
     * Populate OtherRecordID
     * 
     * Populate the OtherRecordID object(s), and add it/them to an existing Constellation object.
     *
     * OtherRecordID is an array of SameAs \snac\data\SameAs[]
     *
     * Other record id can be found in the SameAs class.
     *
     * Here $otherID is a SameAs object. SameAs->setType() is a Term object and thus it takes populateTerm()
     * as an argument. SameAs->setURI() takes a string. Term->setTerm() takes a string. SameAs->setText()
     * takes a string.
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */ 
    private function populateOtherRecordID($vhInfo, &$cObj)
    {
        $oridRows = $this->sql->selectOtherID($vhInfo); 
        foreach ($oridRows as $rec)
        {
            $gObj = new \snac\data\SameAs();
            $gObj->setText($rec['text']); // the text of this sameAs or otherRecordID
            $gObj->setURI($rec['uri']); // the URI of this sameAs or otherRecordID
            $gObj->setType($this->populateTerm($rec['type'])); // \snac\data\Term Type of this sameAs or otherRecordID
            $gObj->setDBInfo($rec['version'], $rec['id']);
            $this->populateMeta($gObj);
            $cObj->addOtherRecordID($gObj);
        }
    }

    /**
     * Populate Place object
     * 
     * Build class Place objects for this constellation, selecting from the database. Place gets data from
     * place_link, scm, and geo_place.
     *
     *
     * | php            | sql      |
     * |----------------+----------|
     * | setID()        | id       |
     * | setVersion()   | version  |
     * | setType() Term | type     |
     * | setOriginal()  | original |
     * | setNote()      | note     |
     * | setRole() Term | role     |
     *
     * | php                                             | sql                         | geonames.org         |
     * |-------------------------------------------------+-----------------------------+----------------------|
     * | setID()                                         | id                          |                      |
     * | setVersion()                                    | version                     |                      |
     * | setLatitude()                                   | geo_place.latitude          | lat                  |
     * | setLongitude()                                  | geo_place.longitude         | lon                  |
     * | setAdminCode() renamed from setAdmistrationCode | geo_place.admin_code        | adminCode            |
     * | setCountryCode()                                | geo_place.country_code      | countryCode          |
     * | setName()                                       | geo_place.name              | name                 |
     * | setGeoNameId()                                  | geo_place.geonamed_id       | geonameId            |
     * | setSource()                                     | scm.source_data             |                      |
     *
     * @param string[] $vhInfo associative list with keys 'version', 'main_id'.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populatePlace($vhInfo, &$cObj, $fkID)
    {
        /*
         * $gRows where g is for generic. As in "a generic object". Make this as idiomatic as possible.
         */
        $gRows = $this->sql->selectPlace($fkID, $vhInfo['version']);
        foreach ($gRows as $rec)
        {
            $gObj = new \snac\data\Place();
            $gObj->setOriginal($rec['original']);
            $gObj->setType($this->populateTerm($rec['type']));
            $gObj->setRole($this->populateTerm($rec['role']));
            $gObj->setGeoTerm($this->buildGeoTerm($rec['geo_place_id']));
            $gObj->setScore($rec['score']);
            $gObj->setConfirmed($rec['confirmed']);
            $gObj->setNote($rec['note']);
            $gObj->setDBInfo($rec['version'], $rec['id']);
            $this->populateMeta($gObj);
            /*
             * Feb 11 2016 At some point, probably in the last few days, setSource() disappeared from class
             * Place. This is probably due to all AbstractData getting SNACControlMetadata (SCM) properties.
             * 
             * $metaObj = $this->buildMeta($rec['id'], $vhInfo['version']);
             * $gObj->setSource($metaObj);
             *
             * A whole raft of place related properties have been moved from Place to GeoTerm.
             */
            $this->populateDate($gObj);
            $cObj->addPlace($gObj);
        }
    }

    /**
     * Populate the SNACControlMetadata (SCM)
     *
     * Read the SCM from the database and add it to the object in &$cObj.
     *
     * Don't be confused by setSource() that uses a Source object and setSource() that uses a
     * SNACControlMetadata object.
     *
     * The convention for related things like date, place, and meta is args ($id, $version) so we're
     * following that.
     *
     * @param integer $tid Table id, aka row id akd object id
     *
     * @param integer $version Constellation version number
     *
     */
    private function populateMeta(&$cObj)
    {
        /*
         * $gRows where g is for generic. As in "a generic object". Make this as idiomatic as possible.
         */
        if( $recList = $this->sql->selectMeta($cObj->getID(), $cObj->getVersion()))
        {
            foreach($recList as $rec)
            {
                $gObj = new \snac\data\SNACControlMetadata();
                $gObj->setSubCitation($rec['sub_citation']);
                $gObj->setSourceData($rec['source_data']);
                $gObj->setDescriptiveRule($this->populateTerm($rec['rule_id']));
                $gObj->setNote($rec['note']);
                $gObj->setDBInfo($rec['version'], $rec['id']);
                /*
                 * Prior to creating the Language object, language was strange and not fully functional. Now
                 * language is a related record that links back here via our record id as a foreign key.
                 */ 
                $this->populateLanguage($gObj, $rec['id']);
                /*
                 * populateSource() will call setCitation() for SNACControlMetadata objects
                 */ 
                $this->populateSource($gObj); // ?Why was there: $rec['id'], 
                $cObj->addSNACControlMetadata($gObj);
            }
        }
    }
    

    /**
     * Populate LegalStatus
     *
     * Populate the LegalStatus object(s), and add it/them to an existing Constellation object.
     *
     * Extends AbstracteTermData
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateLegalStatus($vhInfo, &$cObj)
    {
        /*
         * $gRows where g is for generic. As in "a generic object". Make this as idiomatic as possible.
         */
        $gRows = $this->sql->selectLegalStatus($vhInfo);
        foreach ($gRows as $rec)
        {
            $gObj = new \snac\data\LegalStatus();
            $gObj->setTerm($this->populateTerm($rec['term_id']));
            $gObj->setDBInfo($rec['version'], $rec['id']);
            $this->populateMeta($gObj);
            $cObj->addLegalStatus($gObj);
        }
    }


    /**
     * Populate the Subject object(s)
     *
     * Select subjects from db, create objects, add them to an existing Constellation.
     *
     * Extends AbstracteTermData
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateSubject($vhInfo, &$cObj)
    {
        /*
         * $gRows where g is for generic. As in "a generic object". Make this as idiomatic as possible.
         */
        $gRows = $this->sql->selectSubject($vhInfo);
        foreach ($gRows as $rec)
        {
            $gObj = new \snac\data\Subject();
            $gObj->setTerm($this->populateTerm($rec['term_id']));
            $gObj->setDBInfo($rec['version'], $rec['id']);
            $this->populateMeta($gObj);
            $cObj->addSubject($gObj);
        }
    }


    /** 
     * Populate nameEntry objects
     *
     * test with: scripts/get_constellation_demo.php 2 10
     *
     * That constellation has 3 name contributors.
     *
     * | php                                        | sql table name   |
     * |--------------------------------------------+------------------|
     * | setOriginal                                | original         |
     * | setPreferenceScore                         | preference_score |
     * | setLanguage                                | language         |
     * | setScriptCode                              | script_code      |
     * | setDBInfo                                  | version, id      |
     * | addContributor(string $type, string $name) |                  |
     *
     * | php                              | sql table name_contributor |
     * |----------------------------------+----------------------------|
     * |                                  | name_id                    |
     * | getContributors()['contributor'] | short_name                 |
     * | getContributors()['type']        | name_type                  |
     * |                                  |                            |
     *
     * @param string[] $vhInfo associative list with keys 'version', 'main_id'.
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateNameEntry($vhInfo, &$cObj)
    {
        $neRows = $this->sql->selectName($vhInfo);
        foreach ($neRows as $oneName)
        {
            $neObj = new \snac\data\NameEntry();
            $neObj->setOriginal($oneName['original']);
            /*
             * $neObj->setLanguage($oneName['language']);
             * $neObj->setScriptCode($oneName['script_code']);
             */
            $neObj->setPreferenceScore($oneName['preference_score']);
            $neObj->setDBInfo($oneName['version'], $oneName['id']); 
            $this->populateMeta($neObj);
            $this->populateLanguage($neObj, $oneName['id']);
            /*
             * This line works because $oneName['id'] == $neObj->getID(). Both are record id, not
             * constellation id. Both are non-null when reading from the database.
             */ 
            $cRows = $this->sql->selectContributor($neObj->getID(), $vhInfo['version']);
            foreach ($cRows as $contrib)
            {
                $ctObj = new \snac\data\Contributor();
                $ctObj->setType($this->populateTerm($contrib['name_type']));
                $ctObj->setName($contrib['short_name']);
                $ctObj->setDBInfo($contrib['version'], $contrib['id']);
                $neObj->addContributor($ctObj);
            }
            $this->populateDate($neObj);
            $cObj->addNameEntry($neObj);
        }
    }

    /**
     * Populate dates
     * 
     * Select date range(s) from db, foreach create SNACDate object, add to the object $cObj, which may be any
     * kind of object that extends AbstractData.
     *
     * Currently, we call insertDate() for: nrd, occupation, function, relation,
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param int $rowID the nrd.id actual row id from table nrd.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateDate(&$cObj)
    {
        /*
         * Sanity check the number of dates allowed for this object $cObj. If zero, then immediately
         * return. If one then set a flag to break the foforeach after the first iteration. Else we are
         * allowed >=1 dates, and we won't exit the foreach after the first interation.
         */
        $breakAfterOne = false;
        if ($cObj->getMaxDateCount() == 0)
        {
            return;
        }
        elseif ($cObj->getMaxDateCount() == 1)
        {
            $breakAfterOne = true;
        }
        $dateRows = $this->sql->selectDate($cObj->getID(), $cObj->getVersion());

        foreach ($dateRows as $singleDate)
        {
            

            $dateObj = new \snac\data\SNACDate();
            $dateObj->setRange($singleDate['is_range']);
            $dateObj->setFromDate($singleDate['from_original'],
                                  $singleDate['from_date'],
                                  $this->populateTerm($singleDate['from_type'])); // $type
            $dateObj->setFromDateRange($singleDate['from_not_before'], $singleDate['from_not_after']);
            $dateObj->setToDate($singleDate['to_original'],
                                $singleDate['to_date'],
                                $this->populateTerm($singleDate['to_type']));
            $dateObj->setToDateRange($singleDate['to_not_before'], $singleDate['to_not_after']);
            $dateObj->setDBInfo($singleDate['version'], $singleDate['id']);
            $this->populateMeta($dateObj);

            $cObj->addDate($dateObj);
            if ($breakAfterOne)
            {
                break;
            }
        }
    }

    /**
     * Populate Term
     *
     * Return a vocabulary term object selected from database using vocabulary id key. \src\snac\data\Term
     * which is used by many objects for controlled vocabulary "terms". We use "term" broadly in the sense of
     * an object that meets all needs of the the user interface.
     *
     * Most of the populate* functions build an object and add it to another existing object. This returns the
     * object, so it might better be called buildTerm() since we have already used that nameing convention.
     *
     * You might be searching for new Term(). This is the only place we create Terms here.
     *
     * @param integer $termID A unique integer record id from the database table vocabulary.
     *
     */
    private function populateTerm($termID)
    {
        $newObj = new \snac\data\Term();
        $row = $this->sql->selectTerm($termID);
        $newObj->setID($row['id']);
        $newObj->setType($row['type']); // Was setDataType() but this is a vocaulary type. See Term.php.
        $newObj->setTerm($row['value']);
        $newObj->setURI($row['uri']);
        $newObj->setDescription($row['description']);
        /*
         * Class Term has no SNACControlMetadata
         */ 
        return $newObj;
    }

    /**
     * Build a GeoTerm
     *
     * Return a GeoTerm object selected from database. Outside code can (and will, sometimes) call this, but
     * primarily this is used to build GeoTerm objects as part of Place in a Constellation.
     *
     * @param integer $termID A unique integer record id from the database table geo_place.
     *
     * @return \snac\data\GeoTerm $gObj A GeoTerm object.
     */ 
    public function buildGeoTerm($termID)
    {
        $gObj = new \snac\data\GeoTerm();
        $rec = $this->sql->selectGeoTerm($termID);
        $gObj->setID($rec['id']);
        $gObj->setURI($rec['uri']);
        $gObj->setName($rec['name']);
        $gObj->setLatitude($rec['latitude']);
        $gObj->setLongitude($rec['longitude']);
        $gObj->setAdministrationCode($rec['admin_code']);
        $gObj->setCountryCode($rec['country_code']);
        /*
         * Class GeoTerm has no SNACControlMetadata
         */ 
        return $gObj;
    }

    /**
     * Save a GeoTerm
     *
     * Insert a GeoTerm object into the database. This is a public function that outside code is expected to
     * call.
     *
     * @param \snac\data\GeoTerm $term A GeoTerm object
     *
     * @param integer $version A version number, defaults to 1
     *
     * The ID may be null or the empty string in which case the database will assign a new value.
     */ 
    public function saveGeoTerm(\snac\data\GeoTerm $term, $version)
    {
        if (! $version)
        {
            $version = 1;
        }
        $id = insertGeo($term->getID(),
                        $version,
                        $term->getURI(),
                        $term->getName(),
                        $term->getLatitude(),
                        $term->getLongitude(),
                        $term->getAdministrationCode(),
                        $term->getCountryCode());
        return $id;
    }



    /**
     * Select (populate) ConventionDeclaration
     *
     * Build an appropriate object which is added to Constellation.
     *
     * Extends AbstractTextData.
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateConventionDeclaration($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectConventionDeclaration($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\ConventionDeclaration();
            $newObj->setText($item['text']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addConventionDeclaration($newObj);
        }
    }


    /**
     * Save StructureOrGenealogy to database
     *
     * Extends AbstractTextData.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object
     */ 
    private function saveStructureOrGenealogy($vhInfo, &$cObj)
    {
        if ($gList = $cObj->getStructureOrGenealogies())
        {
            foreach ($gList as $item)
            {
                $rid = $this->sql->insertStructureOrGenealogy($vhInfo,
                                                              $item->getID(),
                                                              $item->getText());
                $item->setID($rid);
                $item->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $item, 'structure_genealogy', $rid);
            }
        }
    }

    /**
     * Select StructureOrGenealogy from database
     *
     * Create object, add the object to Constellation
     *
     * Extends AbstractTextData.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */ 
    private function populateStructureOrGenealogy($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectStructureOrGenealogy($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\StructureOrGenealogy();
            $newObj->setText($item['text']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addStructureOrGenealogy($newObj);
        }
    }


    /**
     * Select GeneralContext from database
     *
     * Create object, add the object to Constellation. Support multiples per constellation.
     *
     * Extends AbstractTextData
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateGeneralContext($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectGeneralContext($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\GeneralContext();
            $newObj->setText($item['text']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addGeneralContext($newObj);
        }
    }

    /**
     * Save GeneralContext to database
     *
     * Extends AbstractTextData
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object
     */
    private function saveGeneralContext($vhInfo, &$cObj)
    {
        if ($gList = $cObj->getGeneralContexts())
        {
            foreach ($gList as $item)
            {
                $rid = $this->sql->insertGeneralContext($vhInfo,
                                                        $item->getID(),
                                                        $item->getText());
                $item->setID($rid);
                $item->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $item, 'general_context', $rid);
            }
        }
    }


    /**
     * Select nationality from database
     *
     * Create object, add the object to Constellation. Support multiples per constellation.
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateNationality($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectNationality($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\Nationality();
            $newObj->setTerm($this->populateTerm($item['term_id']));
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addNationality($newObj);
        }
    }

    /**
     * Save nationality to database
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object
     */
    private function saveNationality($vhInfo, &$cObj)
    {
        if ($gList = $cObj->getNationalities())
        {
            foreach ($gList as $item)
            {
                $rid = $this->sql->insertNationality($vhInfo,
                                                     $item->getID(),
                                                     $this->thingID($item->getTerm()));
                $item->setID($rid);
                $item->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $item, 'nationality', $rid);
            }
        }
    }

    /*
     * Select language from the database, create a language object, add the language to the object referenced
     * by $cObj.
     *
     * We have two term ids, language_id and script_id, so they need unique names (keys) and not the usual
     * "term_id".
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateLanguage(&$cObj, $fkID)
    {
        // getID() is the constellation ID. In reality, these tables are related on table.id
        // $rows = $this->sql->selectLanguage($cObj->getID(), $cObj->getVersion());

        // This reflects reality table.id=language.fk_id
        $rows = $this->sql->selectLanguage($fkID, $cObj->getVersion());

        foreach ($rows as $item)
        {
            $newObj = new \snac\data\Language();
            $newObj->setLanguage($this->populateTerm($item['language_id']));
            $newObj->setScript($this->populateTerm($item['script_id']));
            $newObj->setVocabularySource($item['vocabulary_source']);
            $newObj->setNote($item['note']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $class = get_class($cObj);
            /*
             * Class specific method for setting/adding a language.
             */
            if ($class == 'snac\data\SNACControlMetadata' ||
                $class == 'snac\data\Source' ||
                $class == 'snac\data\BiogHist')
            {
                $cObj->setLanguage($newObj);
            }
            else if ($class == 'snac\data\Constellation')
            {
                $cObj->addLanguage($newObj);
            }
        }
    }

    /**
     * Create a source object from the database
     *
     * Select source from the database, create a source object and return it. The api isn't consisten with how
     * Source objects are added to other objects, so we're best off to build and return. This is different
     * than the populate* functions that rely on a consistent api to add theirself to the parent object.
     *
     * This is a bit exciting because Constellation will have a list of Source, but SNACControlMetadata only
     * has a single Source.
     *
     * Two options for setCitaion()
     * 1) call a function to build a Source object, call setCitation()
     *  $sourceArrayOrSingle = $this->buildSource($cObj);
     *  $gObj->setCitation($sourceOjb)
     *
     * 2) Call populateSource(), which is smart enough to know that SNACControlMetadata uses setCitation()
     * for its Source object and Constellation uses addSource().
     *
     * Option 2 is better because Constellation needs an array and SNACControlMetadata needs a single
     * Source object. It would be very odd for a function to return an array sometimes, and a single
     * object other times. The workaround for that is two functions, which is awkard.
     *
     * Best to just take our medicine and encapsulate the complexity inside here populateSource().
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateSource(&$cObj)
    {
        $rows = $this->sql->selectSource($cObj->getID(), $cObj->getVersion());
        foreach ($rows as $rec)
        {
            $newObj = new \snac\data\Source();
            $newObj->setText($rec['text']);
            $newObj->setNote($rec['note']);
            $newObj->setURI($rec['uri']);
            $newObj->setType($this->populateTerm($rec['type_id']));
            $newObj->setDBInfo($rec['version'], $rec['id']);
            $this->populateMeta($newObj);
            /*
             * setLanguage() is a Language object.
             */
            $this->populateLanguage($newObj, $rec['id']);

            $class = get_class($cObj);
            if ($class == 'snac\data\Constellation')
            {
                $cObj->addSource($newObj);
            }
            else if ($class == 'snac\data\SNACControlMetadata')
            {
                $cObj->setCitation($newObj);
                // There is only one Source in the citation, so best that we break now.
                break;
            }
            else
            {
                $msg = sprintf("Cannot add Source to class: %s\n", $class);
                die($msg);
            }
        }
    }

    /**
     * Save nrd
     *
     * Unlike other insert functions, insertNrd() does not return the id value. The id for nrd is the
     * constellation id, aka $vhInfo['main_id'] aka main_id aka version_history.main_id, and as always,
     * $id->getID() once the Constellation has been saved to the database. The $vhInfo arg is created by
     * accessing the database, so it is guaranteed to be "new" or at least, up-to-date.
     *
     * The entityType may be null because toArray() can't tell the differnce between an empty class and a
     * non-empty class, leading to empty classes littering the JSON with empty json. To avoid that, we use
     * null for an empty class, and test with the ternary operator.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveNrd($vhInfo, &$cObj)
    {
        $this->sql->insertNrd($vhInfo,
                              $cObj->getArk(),
                              $this->thingID($cObj->getEntityType()));
        $cObj->setID($vhInfo['main_id']);
        $cObj->setVersion($vhInfo['version']);
        /*
         * Table nrd is special, and the id is main_id.
         */ 
        $this->saveMeta($vhInfo, $cObj, 'nrd', $vhInfo['main_id']);
    }

    /**
     * Select mandate from database
     *
     * Create object, add the object to Constellation. Support multiples per constellation.
     *
     * Extends AbstractTextData
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateMandate($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectMandate($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\Mandate();
            $newObj->setText($item['text']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addMandate($newObj);
        }
    }

    /**
     * Save mandate to database
     *
     * Extends AbstractTextData
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param $cObj snac\data\Constellation object
     */
    private function saveMandate($vhInfo, &$cObj)
    {
        if ($gList = $cObj->getMandates())
        {
            foreach ($gList as $term)
            {
                $rid = $this->sql->insertMandate($vhInfo,
                                                 $term->getID(),
                                                 $term->getText());
                $term->setID($rid);
                $term->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $term, 'mandate', $rid);
            }
        }
    }

    /**
     * Save conventionDeclaration to database
     *
     * Extends AbstractTextData
     * 
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveConventionDeclaration($vhInfo, &$cObj)
    {
        if ($gList = $cObj->getConventionDeclarations())
        {
            foreach ($gList as $term)
            {
                $rid = $this->sql->insertConventionDeclaration($vhInfo,
                                                               $term->getID(),
                                                               $term->getText());
                $term->setID($rid);
                $term->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $term, 'convention_declaration', $rid);
            }
        }
    }

    /**
     * Save gender data
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveGender($vhInfo, &$cObj)
    {
        foreach ($cObj->getGenders() as $fdata)
        {
            $rid = $this->sql->insertGender($vhInfo,
                                            $fdata->getID(),
                                            $this->thingID($fdata->getTerm()));
            $fdata->setID($rid);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'gender', $rid);
        }
    }

    /**
     * Save date list of constellation
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveConstellationDate($vhInfo, &$cObj)
    {
        foreach ($cObj->getDateList() as $date)
        {
            $this->saveDate($vhInfo, $date, 'nrd', $vhInfo['main_id']);
            /*
             * We don't saveMeta() after save functions, only after insert functions. saveDate() calls
             * saveMeta() internally.
             */ 
        }
    }

    /**
     * Save date object to database
     * 
     * Save a date to the database, relating it to the table and foreign key id in $tableName and $tableID.
     *
     * $date is a SNACDate object.
     * getFromType() must be a Term object
     * getToType() must be a Term object
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     *
     * @param \snac\data\SNACDate $date A single date object
     *
     * @param string $tableName Name of the related table
     *
     * @param integer $tableID Record id of the related table
     *
     * What does it mean to have a date with no fromType? Could be an unparseable date, I guess.
     */
    private function saveDate($vhInfo, &$date, $tableName,  $tableID)
    {
        $rid = $this->sql->insertDate($vhInfo,
                                      $date->getID(),
                                      $this->db->boolToPg($date->getIsRange()),
                                      $date->getFromDate(),
                                      $this->thingID($date->getFromType()),
                                      $this->db->boolToPg($date->getFromBc()),
                                      $date->getFromRange()['notBefore'],
                                      $date->getFromRange()['notAfter'],
                                      $date->getFromDateOriginal(),
                                      $date->getToDate(),
                                      $this->thingID($date->getToType()),
                                      $this->db->boolToPg($date->getToBc()),
                                      $date->getToRange()['notBefore'],
                                      $date->getToRange()['notAfter'],
                                      $date->getToDateOriginal(),
                                      $tableName,
                                      $tableID);
        $date->setID($rid);
        $date->setVersion($vhInfo['version']);
        /*
         * We decided that DBUtil doesn't know (much) about dates as first order data, so write the SCM if
         * there is any. If no SCM, nothing will happen in saveMeta().
         */
        $this->saveMeta($vhInfo, $date, 'date_range', $rid);
    }


    /**
     * Save language
     *
     * Constellation getLanguage() returns a list of Language objects. That's very reasonable in this
     * context.
     *
     * Typical confusion over table.main_id and table.id. What is necessary here is the table.id values which
     * is not part of the constellation. It is managed here in DBUtil via a return value from an insert
     * function, and passed to saveLanguage() as $fkID.
     * 
     * Old (wrong) $vhInfo['main_id'] New: $fkID
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param snac\data\Constellation $cObj snac\data\Constellation object
     *
     * @param string $table Table name of the related table
     *
     * @param integer $fkID Foreign key row id aka table.id from the related table.
     */
    private function saveLanguage($vhInfo, &$cObj, $table, $fkID)
    {
        /*
         * Classes are not consistent in whether language is returned as a list or scalar, so we need to
         * change them all to a list. If only one language, then we make a list of one element. If we didn't
         * do this, we would have to copy/paste the insertLanguage() call or otherwise wrap it. Class
         * Constellation already has a wrapper function getLanguage() which calls the "read" function
         * getLanguagesUsed(). That wrapper function was created so this code didn't have to do that.
         */ 
        $langList = array();
        $scalarOrList = $cObj->getLanguage();
        if (! $scalarOrList)
        {
            // Be lazy and return from the middle of the function if there is no language info.
            return;
        }
        elseif (is_object($scalarOrList))
        {
            array_push($langList, $scalarOrList);
        }
        else
        {
            $langList = $scalarOrList;
        }
        
        foreach ($langList as $lang)
        {
            $rid = $this->sql->insertLanguage($vhInfo,
                                              $lang->getID(),
                                              $this->thingID($lang->getLanguage()),
                                              $this->thingID($lang->getScript()),
                                              $lang->getVocabularySource(),
                                              $lang->getNote(),
                                              $table,
                                              $fkID);
            $lang->setID($rid);
            $lang->setVersion($vhInfo['version']);
            /*
             * Try saving meta data, even though some language objects are not first order data and have no
             * meta data. If there is no meta data, nothing will happen.
             */ 
            $this->saveMeta($vhInfo, $lang, 'language', $rid);
        }
    }

    /**
     * Save otherRecordID
     *
     * Other record id can be found in the SameAs class.
     *
     * Here $otherID is a SameAs object. SameAs->getType() is a Term object. SameAs->getURI() is a string.
     * Term->getTerm() is a string. SameAs->getText() is a string.
     * 
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveOtherRecordID($vhInfo, &$cObj)
    {
        foreach ($cObj->getOtherRecordIDs() as $otherID)
        {
            if ($otherID->getType()->getTerm() != 'MergedRecord' and
                $otherID->getType()->getTerm() != 'viafID')
            {
                $msg = sprintf("Warning: unexpected otherRecordID type: %s for ark: %s\n",
                               $otherID->getType()->getTerm(),
                               $otherID->getURI());
                // TODO: Throw warning or log
            }
            $rid = $this->sql->insertOtherID($vhInfo,
                                             $otherID->getID(),
                                             $otherID->getText(),
                                             $this->thingID($otherID->getType()),
                                             $otherID->getURI());
            $otherID->setID($rid);
            $otherID->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $otherID, 'otherid', $rid);
        }
    }

    /**
     * Save Source of constellation
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveConstellationSource($vhInfo, &$cObj)
    {
        foreach ($cObj->getSources() as $fdata)
        {
            $this->saveSource($vhInfo, $fdata, 'nrd', $vhInfo['main_id']);
            /*
             * No saveMeta() here, because saveSource() calls saveMeta() internally. This particular Source
             * may be first order data, but that is not a concern of DBUtil.
             */ 
        }
    }

    /**
     * Save legalStatus
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveLegalStatus($vhInfo, &$cObj)
    {
        foreach ($cObj->getLegalStatuses() as $fdata)
        {
            $rid = $this->sql->insertLegalStatus($vhInfo,
                                                 $fdata->getID(),
                                                 $this->thingID($fdata->getTerm()));
            $fdata->setID($rid);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'legal_status', $rid);
        }
    }

    /**
     * Save Occupation
     *
     * Insert an occupation. If this is a new occupation, or a new constellation we will get a new
     * occupation id which we save in $occID and use for the related dates.
     *
     * fdata is foreach data. Just a notation that the generic variable is for local use in this loop. 
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveOccupation($vhInfo, &$cObj)
    {
        foreach ($cObj->getOccupations() as $fdata)
        {
            $occID = $this->sql->insertOccupation($vhInfo,
                                                  $fdata->getID(),
                                                  $this->thingID($fdata->getTerm()),
                                                  $fdata->getVocabularySource(),
                                                  $fdata->getNote());
            $fdata->setID($occID);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'occupation', $occID);
            foreach ($fdata->getDateList() as $date)
            {
                $this->saveDate($vhInfo, $date, 'occupation', $occID);
            }
        }
    }

    /**
     * Save Function
     *
     *  | php function        | sql               | cpf                             |
     *  |---------------------+-------------------+---------------------------------|
     *  | getType             | function_type     | function/@localType             |
     *  | getTerm             | function_id       | function/term                   |
     *  | getVocabularySource | vocabulary_source | function/term/@vocabularySource |
     *  | getNote             | note              | function/descriptiveNote        |
     *  | getDateList         | table date_range  | function/dateRange              |
     *
     *
     * I considered adding keys for the second arg, but is not clear that using them for sanity checking
     * would gain anything. The low level code would become more fragile, and would break "separation of
     * concerns". The sanity check would require that the low level code have knowledge about the
     * structure of things that aren't really low level. Remember: SQL code only knows how to put data in
     * the database. Any sanity check should happen up here.
     *
     *
     * Functions have a type (Term object) derived from function/@localType. The function/term is a Term object.
     *
     * prototype: insertFunction($vhInfo, $id, $type, $vocabularySource, $note, $term)
     *
     * Example files: /data/extract/anf/FRAN_NP_050744.xml
     * 
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveFunction($vhInfo, &$cObj)
    {
        foreach ($cObj->getFunctions() as $fdata)
        {
            $funID = $this->sql->insertFunction($vhInfo,
                                                $fdata->getID(), // record id
                                                $this->thingID($fdata->getType()), // function type, aka localType, Term object
                                                $fdata->getVocabularySource(),
                                                $fdata->getNote(),
                                                $this->thingID($fdata->getTerm())); // function term id aka vocabulary.id, Term object
            $fdata->setID($funID);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'function', $funID);
            /*
             * getDateList() always returns a list of SNACDate objects. If no dates then list is empty, but it
             * is still a list that we can foreach on without testing for null and count>0. All of which
             * should go without saying.
             */ 
            foreach ($fdata->getDateList() as $date)
            {
                $this->saveDate($vhInfo, $date, 'function', $funID);
            }
        }
    }


    /**
     * Save subject
     *
     * Save subject term
     *
     * getID() is the subject object record id.
     *
     * $this->thingID($term->getTerm()) more robust form of $term->getTerm()->getID() is the vocabulary id
     * of the Term object inside subject.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveSubject($vhInfo, &$cObj)
    {
        foreach ($cObj->getSubjects() as $term)
        {
            $rid = $this->sql->insertSubject($vhInfo, 
                                             $term->getID(),
                                             $this->thingID($term->getTerm())); 
            $term->setID($rid);
            $term->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $term, 'subject', $rid);
        }
        
    }
    
    
    /**
     * Save Relation aka  ConstellationRelation
     *
     * "ConstellationRelation" has had many names: cpfRelation related_resource, relation,
     * related_identity. We're attempting to make that more consistent, although the class is
     * ConstellationRelation and the SQL table is related_identity.
     * 
     * ignored: we know our own id value: sourceConstellation, // id fk
     * ignored: we know our own ark: sourceArkID,  // ark why are we repeating this?
     * ignored: always 'simple', altType, cpfRelation@xlink:type vocab source_type, .type
     * 
     * | placeholder | php                 | what                                                       | sql               |
     * |-------------+---------------------+------------------------------------------------------------+-------------------|
     * |           1 | $vhInfo['version']  |                                                            | version           |
     * |           2 | $vhInfo['main_id']  |                                                            | main_id           |
     * |           3 | targetConstellation | id fk to version_history                                   | .related_id       |
     * |           4 | targetArkID         | ark                                                        | .related_ark      |
     * |           5 | targetEntityType    | cpfRelation@xlink:role, vocab entity_type, Term object     | .role             |
     * |           6 | type                | cpfRelation@xlink:arcrole vocab relation_type, Term object | .arcrole          |
     * |           7 | cpfRelationType     | AnF only, so far                                           | .relation_type    |
     * |           8 | content             | cpfRelation/relationEntry, usually a name                  | .relation_entry   |
     * |           9 | dates               | cpfRelation/date (or dateRange)                            | .date             |
     * |          10 | note                | cpfRelation/descriptiveNote                                | .descriptive_note |
     * 
     * New convention: when there are dates, make them the second arg. Final arg is a list of all the
     * scalar values that will eventually be passed to execute() in the SQL function. This convention
     * is already in use in a couple of places, but needs to be done for some existing functions.
     * 
     * Ignore ConstellationRelation->$altType. It was always "simple".
     * 
     * altType is cpfRelationType, at least in the CPF.
     *
     * Don't save the source info, because we are the source and have already saved the source data as
     * part of ourself.
     *
     * getRelations() returns \snac\data\ConstellationRelation[]
     * $fdata is \snac\data\ConstellationRelation
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveRelation($vhInfo, $cObj)
    {
        foreach ($cObj->getRelations() as $fdata)
        {
            /* 
             * $cpfRelTypeID = null;
             * if ($cr = $fdata->getcpfRelationType())
             * {
             *     $cpfRelTypeID = $cr->getID();
             * }
             */
            $relID = $this->sql->insertRelation($vhInfo,
                                                $fdata->getTargetConstellation(),
                                                $fdata->getTargetArkID(),
                                                $this->thingID($fdata->getTargetEntityType()),
                                                $this->thingID($fdata->getType()),
                                                $this->thingID($fdata->getcpfRelationType()), // $cpfRelTypeID,
                                                $fdata->getContent(),
                                                $fdata->getNote(),
                                                $fdata->getID());
            $fdata->setID($relID);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'related_identity', $relID);
            foreach ($fdata->getDateList() as $date)
            {
                $this->saveDate($vhInfo, $date, 'related_identity', $relID);
            }
        }
    }

    /**
     * Save resourceRelation
     * 
     * ignored: $this->linkType, @xlink:type always 'simple', vocab source_type, .type
     * 
     * | placeholder | php                 | what, CPF                                        | sql                  |
     * |-------------+---------------------+--------------------------------------------------+----------------------|
     * |           1 | $vhInfo['version']  |                                                  | .version             |
     * |           2 | $vhInfo['main_id']  |                                                  | .main_id             |
     * |           3 | documentType        | @xlink:role id fk to vocab document_type         | .role                |
     * |           4 | entryType           | relationEntry@localType, AnF, always 'archival'? | .relation_entry_type |
     * |           5 | link                | @xlink:href                                      | .href                |
     * |           6 | role                | @xlink:arcrole vocab document_role               | .arcrole             |
     * |           7 | content             | relationEntry, usually a name                    | .relation_entry      |
     * |           8 | source              | objectXMLWrap                                    | .object_xml_wrap     |
     * |           9 | note                | descriptiveNote                                  | .descriptive_note    |
     * 
     * Final arg is a list of all the scalar values that will eventually be passed to execute() in the SQL
     * function. This convention is already in use in a couple of places, but needs to be done for some
     * existing functions.  
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object
     */
    private function saveResourceRelation($vhInfo, &$cObj)
    {
        foreach ($cObj->getResourceRelations() as $fdata)
        {
            $rid = $this->sql->insertResourceRelation($vhInfo,
                                                      $fdata->getDocumentType()->getID(), // xlink:role
                                                      $this->thingID($fdata->getEntryType()), // relationEntry@localType
                                                      $fdata->getLink(), // xlink:href
                                                      $this->thingID($fdata->getRole()), // xlink:arcrole
                                                      $fdata->getContent(), // relationEntry
                                                      $fdata->getSource(), // objectXMLWrap
                                                      $fdata->getNote(), // descriptiveNote
                                                      $fdata->getID());
            $fdata->setID($rid);
            $fdata->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $fdata, 'related_resource', $rid);
        }
    }

    /**
     * Select gender from database
     *
     * Create object, add the object to Constellation. Support multiples per constellation.
     *
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateGender($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectGender($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\Gender();
            $newObj->setTerm($this->populateTerm($item['term_id']));
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $cObj->addGender($newObj);
        }
    }

    /**
     * Select GeneralContext from database
     *
     * Create object, add the object to Constellation. Support multiples per constellation.  Get BiogHist from
     * database, create relevant object and add to the constellation object passed as an argument.
     * 
     * Note: $cObj passed by reference and changed in place.
     *
     * @param integer[] $vhInfo list with keys version, main_id.
     * 
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateBiogHist($vhInfo, &$cObj)
    {
        $rows = $this->sql->selectBiogHist($vhInfo);
        foreach ($rows as $item)
        {
            $newObj = new \snac\data\BiogHist();
            $newObj->setText($item['text']);
            $newObj->setDBInfo($item['version'], $item['id']);
            $this->populateMeta($newObj);
            $this->populateLanguage($newObj, $item['id']);
            $cObj->addBiogHist($newObj);
        }
    }


    /**
     * Get Occupation from the db
     *
     * Populate occupation object(s), add to Constellation object passed by
     * reference.
     *
     * Need to add date range
     * Need to add vocabulary source
     * 
     * | php                 | sql               |
     * |---------------------+-------------------|
     * | setDBInfo           | id                |
     * | setDBInfo           | version           |
     * | setDBInfo           | main_id           |
     * | setTerm             | occupation_id     |
     * | setNote             | note              |
     * | setVocabularySource | vocabulary_source |
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     * 
     */
    private function populateOccupation($vhInfo, &$cObj)
    {
        $occRows = $this->sql->selectOccupation($vhInfo);
        foreach ($occRows as $oneOcc)
        {
            $occObj = new \snac\data\Occupation();
            $occObj->setTerm($this->populateTerm($oneOcc['occupation_id']));
            $occObj->setVocabularySource($oneOcc['vocabulary_source']);
            $occObj->setNote($oneOcc['note']);
            $occObj->setDBInfo($oneOcc['version'], $oneOcc['id']);
            $this->populateMeta($occObj);
            $this->populateDate($occObj);
            $cObj->addOccupation($occObj);
        }
    }

    /**
     * Populate relation object(s)
     *
     * Select from db then add to existing Constellation object.
     *
     * test with: scripts/get_constellation_demo.php 2 10
     *
     *
     * | php                                    | sql              |
     * |----------------------------------------+------------------|
     * | setDBInfo                              | id               |
     * | setDBInfo                              | version          |
     * | setDBInfo                              | main_id          |
     * | setTargetConstellation                 | related_id       |
     * | setTargetArkID                         | related_ark      |
     * | setTargetEntityType  was setTargetType | role             |
     * | setType                                | arcrole          |
     * | setCPFRelationType                     | relation_type    |
     * | setContent                             | relation_entry   |
     * | setDates                               | date             |
     * | setNote                                | descriptive_note |
     *
     * cpfRelation/@type cpfRelation@xlink:type
     *
     * Note:
     * setsourceConstellation() is parent::getID()
     * setSourceArkID() is parent::getARK()
     *
     * Unclear why those methods (and their properties) exist, but fill them in regardless.
     *
     * php: $altType setAltType() getAltType()
     *
     * The only value this ever has is "simple". Daniel says not to save it, and implicitly hard code when
     * serializing export.
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     */
    private function populateRelation($vhInfo, &$cObj)
    {
        $relRows = $this->sql->selectRelation($vhInfo);
        foreach ($relRows as $oneRel)
        {
            $relatedObj = new \snac\data\ConstellationRelation();
            $relatedObj->setSourceConstellation($cObj->getID());
            $relatedObj->setSourceArkID($cObj->getARK());
            $relatedObj->setTargetConstellation($oneRel['related_id']);
            $relatedObj->setTargetArkID($oneRel['related_ark']);
            $relatedObj->setTargetEntityType($this->populateTerm($oneRel['role']));
            $relatedObj->setType($this->populateTerm($oneRel['arcrole']));
            /* Not using setAltType(). It is never used. See ConstellationRelation.php */ 
            $relatedObj->setCPFRelationType($this->populateTerm($oneRel['relation_type']));
            $relatedObj->setContent($oneRel['relation_entry']);
            $relatedObj->setNote($oneRel['descriptive_note']);
            $relatedObj->setDBInfo($oneRel['version'], $oneRel['id']);
            $this->populateMeta($relatedObj);
            $this->populateDate($relatedObj);
            $cObj->addRelation($relatedObj);
        }
    }


    /**
     * Populate the ResourceRelation
     *
     * Populate object(s), and add it/them to an existing Constellation object.
     *
     * | php                  | sql                      | CPF                                       |
     * |----------------------+--------------------------+-------------------------------------------|
     * | setDBInfo            | id                       |                                           |
     * | setDBInfo            | version                  |                                           |
     * | setDBInfo            | main_id                  |                                           |
     * | setDocumentType      | role                     | resourceRelation/@role                    |
     * | setRelationEntryType | relation_entry_type      | resourceRelation/relationEntry/@localType |
     * | setLinkType          | always "simple", ignored | resourceRelation@xlink:type               |
     * | setLink              | href                     | resourceRelation/@href                    |
     * | setRole              | arcrole                  | resourceRelation/@arcrole                 |
     * | setContent           | relation_entry           | resourceRelation/resourceEntry            |
     * | setSource            | object_xml_wrap          | resourceRelation/objectXMLWrap            |
     * | setNote              | descriptive_note         | resourceRelation/descriptiveNote          |
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateResourceRelation($vhInfo, &$cObj)
    {
        $rrRows = $this->sql->selectResourceRelation($vhInfo);
        foreach ($rrRows as $oneRes)
        {
            $rrObj = new \snac\data\ResourceRelation();
            $rrObj->setDocumentType($this->populateTerm($oneRes['role']));
            $rrObj->setRelationEntryType($oneRes['relation_entry_type']);
            /* setLinkType() Not used. Always "simple" See ResourceRelation.php */ 
            $rrObj->setLink($oneRes['href']);
            $rrObj->setRole($this->populateTerm($oneRes['arcrole']));
            $rrObj->setContent($oneRes['relation_entry']);
            $rrObj->setSource($oneRes['object_xml_wrap']);
            $rrObj->setNote($oneRes['descriptive_note']);
            $rrObj->setDBInfo($oneRes['version'], $oneRes['id']);
            $this->populateMeta($rrObj);
            $cObj->addResourceRelation($rrObj);
        }
    }


    /**
     * Populate the SNACFunction object(s)
     *
     * Select, create object, then add to an existing Constellation object.
     *
     * @param string[] $vhInfo associative list with keys 'version' and 'main_id'.
     * @param $cObj snac\data\Constellation object, passed by reference, and changed in place
     *
     */
    private function populateFunction($vhInfo, &$cObj)
    {
        $funcRows = $this->sql->selectFunction($vhInfo);
        foreach ($funcRows as $oneFunc)
        {
            $fObj = new \snac\data\SNACFunction();
            $fObj->setType($oneFunc['function_type']);
            $fObj->setTerm($this->populateTerm($oneFunc['function_id']));
            $fObj->setVocabularySource($oneFunc['vocabulary_source']);
            $fObj->setNote($oneFunc['note']);
            $fObj->setDBInfo($oneFunc['version'], $oneFunc['id']);
            $this->populateMeta($fObj);

            /*
             * Must call $fOjb->setDBInfo() before calling populateDate()
             *
             * Why is $fDate assigned but never used?
             */
            $fDate = $this->populateDate($fObj);
            $cObj->addFunction($fObj);
        }
    }

    private function saveVersionHistory($vhInfo, $status, $note, $appUserID, $roleID)
    {
        insertIntoVH($vhInfo, $appUserID, $roleID, $status, $note);
    }

    /**
     * Write a constellation to the database. 
     *
     * Both insert and update are "write". Insert is "do not yet have a version number." Update is "have
     * version number."
     *
     * We get a new version number for every write.
     *
     * The returned constellation is what was passed in, but with any null id and version filled. So, if a
     * constellation with only a new inserted name is written to the db, that is what is returned: an empty
     * constellation with nothing but a name. This was decided on Mar 3 2016 after much discussion.
     *
     * The web UI will send a partial constellation with appropriate operation set. Only the modified parts of
     * the constellation are send from the web UI to the server.
     *
     * There is only one instance where we mint a new constellation id: Constellation insert.
     *
     * When doing component insert for an existing constellation, all new components use the constellation
     * ID, thus no new ID is minted.
     *
     * We assume that something will happen so we always mint a new version number, as well as writing
     * $status and $note to the version_history.
     *
     * As of php 5 objects are passed by reference. It is therefore redundant for a function prototype to say
     * foo(&$cObj). It is necessary to clone() the object if you want to mess with it and not have it changed
     * in place.
     * 
     * @param \snac\data\Constellation $cObj A constellation object
     *
     * @param string $status The status of this version.
     *
     * @param string $note Human written note from the person who edit this data. This is a version commit
     * message.
     *
     * @return \snac\data\Constellation $cObj the original constellation object modified to include id and version.
     *
     */
    public function writeConstellation($argObj, $status, $note)
    {
        $cObj = clone($argObj);
        $mainID = null;
        $op = $cObj->getOperation();
        if ($op == \snac\data\AbstractData::$OPERATION_UPDATE)
        {
            /*
             * Both update and delete use the existing constellation ID
             */ 
            // printf("\nwrite doing update\n");
            $mainID = $cObj->getID();
        }
        elseif ($op == \snac\data\AbstractData::$OPERATION_DELETE)
        {
            /*
             * Both update and delete use the existing constellation ID
             */ 
            // printf("\nwrite doing delete\n");
            $mainID = $cObj->getID();
        }
        elseif ($op == \snac\data\AbstractData::$OPERATION_INSERT)
        {
            /*
             * Insert require a new ID. Passing a null mainID (aka main_id) to insertVersionHistory() will
             * cause a new mainID to be minted.
             */ 
            // printf("\nwrite doing insert\n");
            $mainID = null;
        }
        elseif ($op == null)
        {
            /*
             * This must be an update. That is: an existing constellation with no change at the top, but some
             * operation(s) inside. Since the constellation exists, we assume the ID is good, and there's no
             * need to mint a new ID.
             *
             * Question: why isn't this simply part of the update branch above?
             *
             * A new constellation must have operation insert, and is handled above.
             */
            // printf("\nNo operation at top; we assume there are internal operations.\n");
            $mainID = $cObj->getID();
        }
        else
        {
            $json = $cObj->toJSON();
            printf("\nError: Bad operation: $op\n%s\n", $json);
            die();
        }

        /*
         * Version, status, and note are used only for this write. If at some future time you create private
         * vars for version, main_id, status, and note here in DBUtil, then you must clear the main_id, version,
         * status, and note before returning. Always set all version info explicitly.
         *
         * What won't happen here is two records edited simultaneously being saved. We assume that is
         * impossible. And if it were possible, both updates would (logically?) have the same status, and share
         * the same note.
         *
         * Even on bulk ingest version numbers are not reused for constellations ingested in the same
         * "transaction". A new version_history record is created for each write. It is (sort of) a
         * coincidence that status and note are the same in one or more version_history records.
         *
         * A single version_history record does (and must) apply to all new/modified components of a single
         * constellation.
         *
         * If $mainID is null, insertVersionHistory() is smart enough to mint a new one.
         *
         * A reminder: the structure of $vhInfo is array('version' => 123, 'main_id' => 456);
         *
         */
        $vhInfo = $this->sql->insertVersionHistory($mainID, $this->appUserID, $this->roleID, $status, $note);

        /*
         * $cObj is passed by reference, and changed in place.
         *
         * The only changes to $cObj are adding id and version as necessary. 
         */
        $this->coreWrite($vhInfo, $cObj);
        return $cObj;
    }

    /**
     * Middle layer write constellation to db (new)
     *
     * We already have a version and main_id, but must write a version_history record. We got the new version
     * from selectNewVersion() and the new main_id from selectNewID().
     *
     * @param int[] $vhInfo A list with keys 'main_id' and 'version'
     *
     * @param \snac\data\Constellation &$cObj a constellation object passed by reference.
     *
     * No return value. $cObj is passed by reference, and is changed in place by the save functions, as
     * necessary to update/populate id and version.
     * 
     */ 
    private function coreWrite($vhInfo, &$cObj)
    {
        $this->saveBiogHist($vhInfo, $cObj);
        $this->saveConstellationDate($vhInfo, $cObj);
        $this->saveConstellationSource($vhInfo, $cObj);
        $this->saveConventionDeclaration($vhInfo, $cObj);
        $this->saveFunction($vhInfo, $cObj);
        $this->saveGender($vhInfo, $cObj);
        $this->saveGeneralContext($vhInfo, $cObj);
        $this->saveLegalStatus($vhInfo, $cObj);
        $this->saveLanguage($vhInfo, $cObj, 'nrd', $vhInfo['main_id']);
        $this->saveMandate($vhInfo, $cObj);
        $this->saveName($vhInfo, $cObj);
        $this->saveNationality($vhInfo, $cObj);
        $this->saveNrd($vhInfo, $cObj);
        $this->saveOccupation($vhInfo, $cObj);
        $this->saveOtherRecordID($vhInfo, $cObj);
        $this->savePlace($vhInfo, $cObj, 'nrd', $vhInfo['main_id']);
        $this->saveStructureOrGenealogy($vhInfo, $cObj);
        $this->saveSubject($vhInfo, $cObj);
        $this->saveRelation($vhInfo, $cObj); // aka cpfRelation, constellationRelation, related_identity
        $this->saveResourceRelation($vhInfo, $cObj);
    }

    /**
     * Read a constellation from the database.
     *
     * This is intended to be the public exposed interface function to read data from the db. Perhaps not
     * necessary. Currently just a wrapper for selectConstellation(). The word "select" is reserved for SQL
     * functions, so selectConstellation() really should be renamed.
     *
     * If we need to do any read related bookkeeping, do it here, and not in the wrapped code.
     *
     */
    public function readConstellation($mainID, $version)
    {
        $vhInfo = array('version' => $version,
                        'main_id' => $mainID);
        $cObj = $this->selectConstellation($vhInfo, $this->appUserID);
        return $cObj;
    }


    /**
     * Insert a constellation
     * 
     * Public exposed function to write a new PHP Constellation object to the database. 
     *
     * This is a new constellation, and will get new version and main_id values. Calls saveConstellation() to
     * call a sql function to do the actual writing.
     *
     * @param integer $appUserID The user appuser.id value from the db. 
     *
     * @param integer $roleID The current role.id value of the user. Comes from role.id and table appuser_role_link.
     *
     * @param string $icstatus One of the allowed status values from icstatus. This becomes the new status of the inserted constellation.
     *
     * @param string $note A user-created note for what was done to the constellation. A check-in note.
     *
     * @return string[] An associative list with keys 'version', 'main_id'. There might be a more useful
     * return value such as true for success, and false for failure. This function might need to call into the
     * system-wide user message class that we haven't written yet.
     *
     */
    public function old_insertConstellation($id, $appUserID, $roleID, $icstatus, $note)
    {
        // Quick hack for backward compatibility, even though this function is being deprecated. Pass a null
        // $mainID to insertVersionHistory().
        $mainID = null;
        $vhInfo = $this->sql->insertVersionHistory($mainID, $appUserID, $roleID, $icstatus, $note);
        $this->saveConstellation($id, $appUserID, $roleID, $icstatus, $note, $vhInfo);
        return $vhInfo;
    } // end insertConstellation


    /**
     * Update a constellation
     * 
     * Public, exposed function to update a php constellation that is already in the database. Calls
     * saveConstellation() to call lower level code to update the database.
     *  
     * @param \snac\data\Constellation $id A PHP Constellation object.
     *
     * @param integer $appUserID The user's appuser.id value from the db. 
     *
     * @param integer $roleID The current role.id value of the user. Comes from role.id and table appuser_role_link.
     *
     * @param string $icstatus One of the allowed status values from icstatus. This becomes the new status of the inserted constellation.
     *
     * @param string $note A user-created note for what was done to the constellation. A check-in note.
     *
     * @param int $main_id The main_id for this constellation.
     *
     * @return string[] An associative list with keys 'version', 'main_id'. There might be a more useful
     * return value such as true for success, and false for failure. This function might need to call into the
     * system-wide user message class that we haven't written yet.
     *
     */
    public function updateConstellation($id, $appUserID, $roleID, $icstatus, $note, $main_id)
    {
        $newVersion = $this->sql->updateVersionHistory($appUserID, $roleID, $icstatus, $note, $main_id);
        $vhInfo = array('version' => $newVersion, 'main_id' => $main_id);
        $this->saveConstellation($id, $appUserID, $roleID, $icstatus, $note, $vhInfo);
        return $vhInfo;
    }

    /**
     * Save place object
     *
     * Save a list of places to place_link, including meta data.
     *
     * The only way to know the related table is for it to be passed in via $relatedTable.
     *
     * @param string[] $vhInfo Array with keys 'version', 'main_id' for this constellation.
     *
     * @param snac\data\AbstractData Object $id An object that might have a place, and that extends
     * AbstractData.
     *
     * @param string $relatedTable Name of the related table for this place.
     *
     */
    private function savePlace($vhInfo, &$cObj, $relatedTable, $fkID)
    {
        if ($placeList = $cObj->getPlaces())
        {
            foreach($placeList as $gObj)
            {
                $pid = $this->sql->insertPlace($vhInfo,
                                               $gObj->getID(),
                                               $this->db->boolToPg($gObj->getConfirmed()),
                                               $gObj->getOriginal(),
                                               $this->thingID($gObj->getGeoTerm()),
                                               $this->thingID($gObj->getType()),
                                               $this->thingID($gObj->getRole()),
                                               $gObj->getNote(),
                                               $gObj->getScore(),
                                               $relatedTable,
                                               $fkID);
                $gObj->setID($pid);
                $gObj->setVersion($vhInfo['version']);
                $this->saveMeta($vhInfo, $gObj, 'place_link', $pid);
                if ($dObj = $gObj->getDateList())
                {
                    foreach ($ndata->getDateList() as $date)
                    {
                        $this->saveDate($vhInfo, $date, 'place_link', $pid);
                    }
                }
            }
        }
    }
    
    /**
     * Save SNACControlMetadata to database
     *
     * Might have been called saveSCM().
     *
     * Save the metadata to table scm in the database. Saved record is related to table $fkTable, and record id $fkID.
     *
     * @param string[] $vhInfo Array with keys 'version', 'main_id' for this constellation.
     *
     * @param \snac\data\SNACControlMetadata[] $metaObjList List of SNAC control meta data
     *
     * @param string $fkTable Name of the table to which this meta data relates
     *
     * @param integer $fkID Record id aka table.id of the record to which this meta data relates.
     *
     */ 
    private function saveMeta($vhInfo, &$gObj, $fkTable, $fkID)
    {
        if (! $metaObjList = $gObj->getSNACControlMetadata())
        {
            return;
        }
        /*
         * Citation is a Source object. Source objects are like dates: each one is specific to the
         * related record. Source is not a controlled vocabulary. Therefore, like date, Source has
         * an fk back to the original table.
         *
         * Note: this depends on an existing Source, DescriptiveRule, and Language, each in its
         * appropriate table in the database. Or if not existing they can be null.
         */
        foreach ($metaObjList as $metaObj)
        {
            $metaID = $this->sql->insertMeta($vhInfo,
                                             $metaObj->getID(),
                                             $metaObj->getSubCitation(),
                                             $metaObj->getSourceData(),
                                             $this->thingID($metaObj->getDescriptiveRule()),
                                             $metaObj->getNote(),
                                             $fkTable,
                                             $fkID);
            $metaObj->setID($metaID);
            $metaObj->setVersion($vhInfo['version']);
            $this->saveLanguage($vhInfo, $metaObj, 'scm', $metaID);
            $citeID = null;
            if ($cite = $metaObj->getCitation())
            {
                $this->saveSource($vhInfo, $cite, 'scm', $metaID);
            }
        }
    }

    /**
     * Save a Source
     *
     * Source objects are written to table source, and their related language (if one exists) is written to
     * table Language with a reverse foreign key as usual. Related on source.id=language.fk_id.
     *
     *
     * 'type' is always simple, and Daniel says we can ignore it. It was used in EAC-CPF just to quiet
     * validation.
     *
     * SNACControlMetadata is part of a source, so calling saveMeta() here would recursion until there was a
     * null SCM. I'm not sure we an call Source first order data, but I'm also not sure DBUtil should care. If
     * the upstream code puts an SCM on an object, we can write the SCM to the db.
     *
     * Or if we have one case of Source as first order, we need an additional argument to control that. Source
     * extends AbstractData, so source can have a SNACControlMetadata object.
     *
     * Note: saveSource() is a primitive called by saveConstellationSource() which probably does need
     * SNACControlMetadata.
     *
     * Is Source first order data? It is a non-authority description of a source. Each source is not a
     * shared authority and is singular to the record to which it is attached. That is: each Source is
     * related back to a record. There can be multiple sources all related back to a single record, as
     * is the case here in Constellation (nrd).
     *
     * @param Object $gObj The object containing this source
     *
     * @param string $fkTable The name of the containing object's table.
     *
     * @param integer $fkID The record id of the containing table.
     *
     */
    private function saveSource($vhInfo, &$gObj, $fkTable, $fkID)
    {
        $genericRecordID = $this->sql->insertSource($vhInfo,
                                                    $gObj->getID(),
                                                    $gObj->getText(),
                                                    $gObj->getNote(),
                                                    $gObj->getURI(),
                                                    $this->thingID($gObj->getType()),
                                                    $fkTable,
                                                    $fkID);
        $gObj->setID($genericRecordID);
        $gObj->setVersion($vhInfo['version']);
        /*
         * Some non-first-order Source objects won't have meta data, but that is not really our concern.
         */  
        $this->saveMeta($vhInfo, $gObj, 'source', $genericRecordID);
        $this->saveLanguage($vhInfo, $gObj, 'source', $genericRecordID);
    }


    /**
     * Update a php constellation
     *
     * This core function writes a constellation to the database. Insert and update both do SQL insert, so
     * there is not difference at this level. However, the calling code will do different things prior. Prior
     * to insert, the calling code gets a new version_history record. Prior to update, the alling code gets a
     * new version number for an existing version_history record.
     *
     * This is called from insertConstellation() or updateConstellation().
     *
     * The id->getID() has been populated by the calling code, whether this is new or exists in the
     * database. This is due to constellation id values coming out of table version_history, unlike all other
     * tables. For this reason, insertNrd() does not return the nrd.id value.
     *
     * nrd (ark, entityType), gender, date, language, bioghist, otherrecordid, nameentry, source, legalstatus, occupation,
     * function, subject, relation, resourceRelation
     *
     * ?? conventionDeclaration, place, nationality, generalContext, structureOrGenealogy,
     * mandate
     *
     * @param \snac\data\Constellation $id A PHP Constellation object.
     *
     * @param integer $appUserID The user's appuser.id value from the db. 
     *
     * @param integer $roleID The current role.id value of the user. Comes from role.id and table appuser_role_link.
     *
     * @param string $icstatus One of the allowed status values from icstatus. This becomes the new status of the inserted constellation.
     *
     * @param string $note A user-created note for what was done to the constellation. A check-in note.
     *
     * @param string[] $vhInfo Array with keys 'version', 'main_id' for this constellation.
     *
     * @return string[] An associative list with keys 'version', 'main_id'. There might be a more useful
     * return value such as true for success, and false for failure. This function might need to call into the
     * system-wide user message class that we haven't written yet.
     *
     */
    private function old_saveConstellation($id, $appUserID, $roleID, $icstatus, $note, $vhInfo)
    {
        $this->saveBiogHist($vhInfo, $id);
        $this->saveConstellationDate($vhInfo, $id);
        $this->saveConstellationSource($vhInfo, $id);
        $this->saveConventionDeclaration($vhInfo, $id);
        $this->saveFunction($vhInfo, $id);
        $this->saveGender($vhInfo, $id);
        $this->saveGeneralContext($vhInfo, $id);
        $this->saveLegalStatus($vhInfo, $id);
        $this->saveLanguage($vhInfo, $id, 'nrd', $vhInfo['main_id']);
        $this->saveMandate($vhInfo, $id);
        $this->saveName($vhInfo, $id);
        $this->saveNationality($vhInfo, $id);
        $this->saveNrd($vhInfo, $id);
        $this->saveOccupation($vhInfo, $id);
        $this->saveOtherRecordID($vhInfo, $id);
        $this->savePlace($vhInfo, $id, 'nrd', $vhInfo['main_id']);
        $this->saveStructureOrGenealogy($vhInfo, $id);
        $this->saveSubject($vhInfo, $id);
        $this->saveRelation($vhInfo, $id); // aka cpfRelation, constellationRelation, related_identity
        $this->saveResourceRelation($vhInfo, $id);
        return $vhInfo;
    } // end saveConstellation


    public function searchVocabulary($type, $query) {

        return $this->sql->searchVocabulary($type, $query);
    }
    /**
     * Save the biogHist
     *
     * Constellation biogHist is currently a list, although the expectation is that it only has a single
     * element.
     *
     * biogHist language, and biogHist date(s?). This is a private function that exists to
     * keep the code organized. It is probably only called from saveConstellation().
     *
     * @param array[] $vhInfo Associative list with keys version, main_id
     *
     * @param \snac\data\BiogHist A single BiogHist object.
     */ 
    private function saveBiogHist($vhInfo, &$cObj)
    {
        foreach ($cObj->getBiogHistList() as $biogHist)
        {
            $bid = $this->sql->insertBiogHist($vhInfo,
                                              $biogHist->getID(),
                                              $biogHist->getText());
            $biogHist->setID($bid);
            $biogHist->setVersion($vhInfo['version']);
            $this->saveMeta($vhInfo, $biogHist, 'biog_hist', $bid);
            if ($lang = $biogHist->getLanguage())
            {
                $this->saveLanguage($vhInfo, $biogHist, 'biog_hist', $bid);
            }
        }
    }

    /**
     * Get ready to update
     *
     * Only used for testing. This creates a new version history record, and returns the $vhInfo for something
     * else to use in doing an update. This calls updateVersionHistory() just like updateConstellation(), but
     * doesn't do the saving part. This function will be deprecated by the setOperation() feature.
     * 
     * Create a new version_history record, and getting the new version number back. The constellation id
     * (main_id) is unchanged. Each table.id is also unchanged. Both main_id and table.id *must* not change.
     *
     * @param snac\data\Constellation $pObj object that we are preparing to write all or part of back to the database.
     *
     * @param integer $appUserID Application user integer id.
     *
     * @param integer $roleID User numeric role id, from the appuser table.
     *
     * @param string $icstatus A version history status string.
     *
     * @param string $note User created note explaining this update.
     *
     * @return string[] Associative list with keys 'version', 'main_id'
     *
     */
    public function updatePrepare($pObj,
                                  $appUserID,
                                  $roleID,
                                  $icstatus,
                                  $note)
    {
        $mainID = $pObj->getID(); // Note: constellation id is the main_id
        $newVersion = $this->sql->updateVersionHistory($appUserID, $roleID, $icstatus, $note, $mainID);
        $vhInfo = array('version' => $newVersion, 'main_id' => $mainID);
        return $vhInfo;
    }

    /**
     * Save a name
     *
     * Once we have AbstractData->$operation implemented, make this method private, and fix DBUtilTest to use
     * setOperation() to update only the name of a constellation. In the meantime, saveName() needs to be public.
     *
     * In the declarative sense "name" is all name data, here a list of name objects, as well as related
     * contributor data, language data, date data.
     *
     * This exists primarily to make the code here in DBUtil more legible.
     *
     * Note about \snac\data\Language objects. This is the Language of the entry. Language object's
     * getLanguage() returns a Term object. Language getScript() returns a Term object for the script. The
     * database only uses the id of each Term.
     *
     * Constellation name entry data is already an array of name entry data. 
     * getUseDates() returns SNACDate[] (An array of SNACDate objects.)
     *
     * When saving a name, the database assigns it a new id, and returns that id. We must be sure to use
     * $nameID for related dates, etc.
     *
     * @param string[] $vhInfo associative list with keys 'version', 'main_id'.
     *
     * @param \snac\data\NameEntry Name entry object
     *
     */
    public function saveName($vhInfo, &$cObj)
    {
        foreach ($cObj->getNameEntries() as $ndata)
        {
            $nameID = $cObj->getID();
            if ($this->prepOperation($vhInfo, $ndata, 'name_entry'))
            {
                $nameID = $this->sql->insertName($vhInfo, 
                                                 $ndata->getOriginal(),
                                                 $ndata->getPreferenceScore(),
                                                 $ndata->getID());
                $ndata->setID($nameID);
                $ndata->setVersion($vhInfo['version']);
            }
            $this->saveMeta($vhInfo, $ndata, 'name', $nameID);
            if ($contribList = $ndata->getContributors())
            {
                /*
                 * $ndata->getID() is null for inserted name. $nameID is walways non-null.
                 * 
                 * $nameID and $ndata->getID() will be the same for a name that is being updated. getID() will
                 * be null for inserted names since there's no id until after insert. $nameID will always be
                 * non-null.
                 *
                 * Both ids are the record id, not the constellation id.
                 *
                 */ 
                foreach($contribList as $cb)
                {
                    $rid = $this->sql->insertContributor($vhInfo,
                                                         $cb->getID(),
                                                         $nameID,
                                                         $cb->getName(),
                                                         $this->thingID($cb->getType()));
                    $cb->setID($rid);
                    $cb->setVersion($vhInfo['version']);
                }
            }
            $this->saveLanguage($vhInfo, $ndata, 'name', $nameID);
            $dateList = $ndata->getDateList();
            foreach ($ndata->getDateList() as $date)
            {
                $this->saveDate($vhInfo, $date, 'name', $nameID);
            }
        
        }
    }


    /**
     * Return 100 constellations as a json string
     *
     * Only 3 fields are included: version, main_id, formatted name. The idea it to return enough for the UI
     * to allow selection of a record to edit.
     *
     * @return string[] A list of 100 records, each with key: 'version', 'main_id', 'formatted_name'
     */
    public function demoConstellationList()
    {
        $demoData = $this->sql->selectDemoRecs();
        return $demoData;
    }

    /**
     * Return a test constellation object
     *
     * The constellation must have 2 or more non-delted names. This is a helper function for testing purposes
     * only.
     *
     * @param integer $appUserID A user id integer. When testing this comes from getAppUserInfo().
     * 
     * @return \snac\data\Constellation A PHP constellation object.
     *
     */
    public function multiNameConstellation($appUserID)
    {
        $vhInfo = $this->sql->sqlMultiNameConstellationID();
        $mNConstellation = $this->selectConstellation($vhInfo, $appUserID);
        return $mNConstellation;
    }


    /**
     * Delete a single record of a single table.
     *
     * Public for testing until we implement "operation". When we implement operations via
     * AbstractData::setOperation() this will become private.
     *
     * Pass a single record object $cObj. The other code here just gets all the records (keeping their id
     * values) and throws them into an Constellation object. Delete is different and delete has single-record
     * granularity.
     *
     * By calling deleteOK() as we use the associative list $canDelete to associate each class with a table.
     *
     * Name is special because a constellation must have at least one name. Everything else can be zero per constellation.
     *
     * @param integer[] $vhInfo Associative list with keys 'main_id', 'version'. These are the new version of the
     * delete, and the constellation main_id.
     *
     * @param \snac\data\Constellation $cObj An object to be deleted. This is any non-Constellation
     * object. Constellation delete is special and handled elsewhere (or at least that is the plan.)
     *
     * @return string Non-null is success, null is failure. On succeess returns the deleted row id, which
     * should be the same as $id.
     *
     */
    public function setDeleted($vhInfo, $cObj)
    {
        /*
         * If this object is associated with a table that allows delete, then deleteOK() will return a
         * non-null $table, else it returns null and the if() will fail.
         */
        $table = null;
        if ($table = $this->deleteOK($cObj))
        {
            $snCount = $this->sql->siblingNameCount($cObj->getID());
            if (($table == 'name') && ($snCount <= 1))
            {
                // Need a message and logging for this.
                printf("Error: Cannot delete the only name for id: %s count: %s\n",
                       $cObj->getID(),
                       $this->sql->siblingNameCount($cObj->getID()));
                return false;
            }
            $this->sql->sqlSetDeleted($table, $cObj->getID(), $vhInfo['version']);
            $postNCount = $this->sql->siblingNameCount($cObj->getID());
            return true;
        }
        else
        {
            // Hmmm. Need to warn the user and write into the log.
            printf("Error: Cannot set deleted on table: $table\n");
            return false;
        }
    }

    /**
     * Undelete a record.
     *
     * @param integer $appUserID Integer user id
     *
     * @param integer $roleID The current integer role.id value of the user. Comes from role.id and table appuser_role_link.
     * 
     * @param string $icstatus Status of this record. Pass a null if unchanged. Lower level code will preserved the existing setting.
     *
     * @param string $note A user-created note for what was done to the constellation. A check-in note.
     *
     * @param integer $main_id The constellation id.
     *
     * @param string $table Name of the table we are deleting from.
     *
     * @param integer $id The record id of the record being deleted. Corresponds to table.id.
     *
     * @return string Non-null is success, null is failure. On succeess returns the deleted row id, which
     * should be the same as $id.
     *
     */
    public function clearDeleted($appUserID, $roleID, $icstatus, $note, $main_id, $table, $id)
    {
        if (! isset($this->canDelete[$table]))
        {
            // Hmmm. Need to warn the user and write into the log.
            printf("Cannot clear deleted on table: $table\n");
            return null;
        }
        $newVersion = $this->sql->updateVersionHistory($appUserID, $roleID, $icstatus, $note, $main_id);
        $this->sql->sqlClearDeleted($table, $id, $newVersion);
        return $newVersion;
    }

}
