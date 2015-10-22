<?php

/**
 * Identity Constellation File
 *
 * Contains the constellation information for an entire entity.
 *
 * License:
 *
 *
 * @author Robbie Hott
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */
namespace snac\data;

/**
 * Identity Constellation
 *
 * Stores all the information related to an identity constellation. Can be built in pieces, or imported
 * from an associative array.
 *
 * @author Robbie Hott
 *        
 */
class Constellation {

    /**
     *
     * @var string ARK identifier
     */
    private $ark = null;

    /**
     *
     * @var string Entity type
     */
    private $entityType = null;

    /**
     *
     * @var string[] Other record IDs by which this constellation may be known
     */
    private $otherRecordIDs = null;

    /**
     *
     * @var string Current maintenance status
     */
    private $maintenanceStatus = null;

    /**
     *
     * @var string Latest maintenance agency
     */
    private $maintenanceAgency = null;

    /**
     *
     * @var \snac\data\MaintenanceEvent[] List of maintenance events performed on this constellation
     */
    private $maintenanceEvents = null;

    /**
     *
     * @var array[][] List of sources, each source is an array of type,value entries
     */
    private $sources = null;

    /**
     *
     * @var string Convention declaration
     */
    private $conventionDeclaration = null;

    /**
     *
     * @var \snac\data\NameEntry[] List of name entries for this constellation
     */
    private $nameEntries = null;

    /**
     *
     * @var string[][] List of occupations with their vocabulary sources (if available)
     */
    private $occupations = null;

    /**
     *
     * @var string[] BiogHist entries for this constellation (in XML strings)
     */
    private $biogHists = null;

    /**
     *
     * @var \snac\data\SNACDate[] Exist dates for the entity
     */
    private $existDates = null;

    /**
     *
     * @var string Note about the exist dates
     */
    private $existDatesNote = null;

    /**
     *
     * @var \snac\data\ConstellationRelation[] Constellation relations
     */
    private $relations = null;

    /**
     *
     * @var \snac\data\ResourceRelation[] Resource relations
     */
    private $resourceRelations = null;

    /**
     *
     * @var \snac\data\SNACFunction Functions
     */
    private $functions = null;

    /**
     *
     * @var \snac\data\Place[] Places
     */
    private $places = null;

    /**
     * Constructor
     *
     * Initializes arrays.
     */
    public function __construct() {

        $this->otherRecordIDs = array ();
        $this->sources = array ();
        $this->maintenanceEvents = array ();
        $this->nameEntries = array ();
        $this->biogHists = array ();
        $this->occupations = array ();
        $this->relations = array ();
        $this->resourceRelations = array ();
        $this->existDates = array ();
        $this->functions = array ();
        $this->places = array ();
    }

    /**
     * Set the ARK ID
     *
     * @param string $ark Ark ID for this constellation
     */
    public function setArkID($ark) {

        $this->ark = $ark;
    }

    /**
     * Set Entity type
     *
     * @param string $type Entity type
     */
    public function setEntityType($type) {

        $this->entityType = $type;
    }

    /**
     * Adds an alternate record id
     *
     * @param string $type Type of the alternate id
     * @param string $link Href or other link for the alternate id
     */
    public function addOtherRecordID($type, $link) {

        array_push($this->otherRecordIDs, 
                array (
                        "type" => $type,
                        "href" => $link
                ));
    }

    /**
     * Set maintenance status
     *
     * @param string $status status
     */
    public function setMaintenanceStatus($status) {

        $this->maintenanceStatus = $status;
    }

    /**
     * Set maintenance agency
     *
     * @param string $agency agency
     */
    public function setMaintenanceAgency($agency) {

        $this->maintenanceAgency = $agency;
    }

    /**
     * Adds a source to the list of sources for this constellation
     *
     * @param string $type Type of the source
     * @param string $link Href or other link to source
     */
    public function addSource($type, $link) {

        array_push($this->sources, array (
                "type" => $type,
                "href" => $link
        ));
    }

    /**
     * Add a maintenance event
     *
     * @param \snac\data\MaintenanceEvent $event Event to add
     */
    public function addMaintenanceEvent($event) {

        array_push($this->maintenanceEvents, $event);
    }

    /**
     * Set the convention declaration
     *
     * @param string $declaration Convention Declaration
     */
    public function setConventionDeclaration($declaration) {

        $this->conventionDeclaration = $declaration;
    }

    /**
     * Adds a name entry to the known entries for this constellation
     *
     * @param \snac\data\NameEntry $nameEntry Name entry to add
     */
    public function addNameEntry($nameEntry) {

        array_push($this->nameEntries, $nameEntry);
    }

    /**
     * Add biogHist entry
     *
     * @param string $biog BiogHist to add
     */
    public function addBiogHist($biog) {

        array_push($this->biogHists, $biog);
    }

    /**
     * Add occupation
     *
     * @param \snac\data\Occupation $occupation Occupation to add
     */
    public function addOccupation($occupation) {

        array_push($this->occupations, $occupation);
    }

    /**
     * Add function
     *
     * @param \snac\data\SNACFunction $function Function object
     */
    public function addFunction($function) {

        array_push($this->functions, $function);
    }

    /**
     * Set Language for constellation description
     *
     * @param string $code Short-code for language
     * @param string $value Human-readable language
     */
    public function setLanguage($code, $value) {
        // TODO
    }

    /**
     * Set Script for constellation description
     *
     * @param string $code Short-code for script
     * @param string $value Human-readable script
     */
    public function setScript($code, $value) {
        // TODO
    }

    /**
     * Set Languaged used by constellation's identity
     *
     * @param string $code Short-code for language
     * @param string $value Human-readable language
     */
    public function setLanguageUsed($code, $value) {
        // TODO
    }

    /**
     * Set Script used by constellation's identity
     *
     * @param string $code Short-code for script
     * @param string $value Human-readable script
     */
    public function setScriptUsed($code, $value) {
        // TODO
    }

    /**
     * Add the subject to this Constellation
     *
     * @param string $subject Subject to add.
     */
    public function addSubject($subject) {
        // TODO
    }

    /**
     * Set the nationality of this Constellation
     *
     * @param string $nationality Nationality
     */
    public function setNationality($nationality) {
        // TODO
    }

    /**
     * Set the gender of this Constellation
     *
     * @param string $gender Gender to set
     */
    public function setGender($gender) {
        // TODO
    }

    /**
     * Set the exist dates for this Constellation
     *
     * @param \snac\data\SNACDate $dates Date object
     */
    public function addExistDates($dates) {

        array_push($this->existDates, $dates);
    }

    /**
     * Set the note on the exist dates for this Constellation
     *
     * @param string $note The descriptive note for the dates
     */
    public function setExistDatesNote($note) {

        $this->existDatesNote = $note;
    }

    /**
     * Add relation to another constellation
     *
     * @param \snac\data\ConstellationRelation $relation Relation object defining the relationship
     */
    public function addRelation($relation) {

        array_push($this->relations, $relation);
    }

    /**
     * Add relation to a resource
     *
     * @param \snac\data\ResourceRelation $relation Relation object defining the relationship
     */
    public function addResourceRelation($relation) {

        array_push($this->resourceRelations, $relation);
    }

    /**
     * Add a place to the constellation
     * 
     * @param \snac\data\Place $place Place to add
     */
    public function addPlace($place) {

        array_push($this->places, $place);
    }
}