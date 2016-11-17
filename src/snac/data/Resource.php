<?php

/**
 * Resource File
 *
 * Contains the data class for the resources.
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
 * Resource 
 *
 * Data storage class for external (archival) Resources.
 *
 * @author Robbie Hott
 *        
 */
class Resource extends AbstractData {

    /**
     * Document Type
     * 
     * From EAC-CPF tag(s):
     * 
     * * resourceRelation/@role
     * 
     * @var \snac\data\Term Document type
     */
    private $documentType = null;

    /**
     * Link Type
     * 
     * From EAC-CPF tag(s):
     *
     * Daniel says this is only a hard coded 'simple' and we don't need to store it, but we will hard code it
     * in the export template.
     *
     * (old comment:) resourceRelation/@type
     * Actually: resourceRelation@xlink:type
     *
     * 'linkType' => 'simple',
     * 
     * @var \snac\data\Term Link type
     */
    private $linkType = null;

    /**
     * Link URI
     * 
     * From EAC-CPF tag(s):
     * 
     * * resourceRelation/@href
     * 
     * @var string Link to external resource
     */
    private $link = null;

    /**
     * XML source
     * 
     * From EAC-CPF tag(s):
     * 
     * * resourceRelation/objectXMLWrap
     * 
     * @var string XML source of the resource relation
     */
    private $source = null;

    /**
     * Title of the archival resource
     *
     * @var string Resource title
     */
    private $title = null;
    
    /**
     * Abstract of the archival resource
     *
     * 
     * @var string Abstract describing the resource
     */
    private $abstract = null;


    /**
     * Extent of the resource
     *
     * @var string Extent of the materials, for example "1 box", "3 linear feet"
     */ 
    private $extent = null;


    /**
     * Repository ic_id
     *
     * @var integer The ic_id of the constellation that is the repository holding this related archival
     * resource.
     */ 
    private $repoIcId = null;


    /**
     * Origination (creator) of the resource
     * @var string[] List of origination names (names of the creators) of this resource.
     */
    private $relatedResourceOriginationName = null;

    /**
     * Constructor
     *
     * Now that ResourceRelation has a property that is an array, we need a constructor that can initialize it.
     *
     */ 
    public function __construct($data = null) {
        $this->setMaxDateCount(0);
        if ($data == null) {
            $this->relatedResourceOriginationName = array();
        }
        // always call the parent constructor
        parent::__construct($data);
    }
    
    /**
     * Get title of the archival resource
     *
     * @return string Resource title
     */
    public function getTitle() {
        return $this->title;
    }

    /**
     * Set title of the archival resource
     *
     * @param string Resource title
     */
    public function setTitle($title) {
        $this->title = $title;
    }

    /**
     * Get abstract of the archival resource
     *
     * 
     * @return string Abstract describing the resource
     */
    public function getAbstract() {
        return $this->abstract;
    }
    
    /**
     * Set abstract of the archival resource
     *
     * 
     * @param string Abstract describing the resource
     */
    public function setAbstract($abstract) {
        $this->abstract = $abstract;
    }

    /**
     * Get extent of the resource
     *
     * @return string Extent of the materials, for example "1 box", "3 linear feet"
     */ 
    public function getExtent() {
        return $this->extent;
    }
    
    /**
     * Set extent of the resource
     *
     * @param string Extent of the materials, for example "1 box", "3 linear feet"
     */ 
    public function setExtent($extent) {
        $this->extent = $extent;
    }

    /**
     * Get repository ic_id
     *
     * @return integer The ic_id of the constellation that is the repository holding this related archival
     * resource.
     */ 
    public function getRepoIcId() {
        return $this->repoIcId;
    }
    
    /**
     * Set repository ic_id
     *
     * @param integer The ic_id of the constellation that is the repository holding this related archival
     * resource.
     */ 
    public function setRepoIcId($repoIcId) {
        $this->repoIcId = $repoIcId;
    }

    /**
     * Get list of origination (creator) of the resource
     *
     * @return \snac\data\RROriginationName[] List of origination names (names of the creators) of this resource.
     */
    public function getRelatedResourceOriginationName() {
        return $this->relatedResourceOriginationName;
    }

    /**
     * Add an origination (creator) of the resource
     *
     * @param \snac\data\RROriginationName[] List of origination names (names of the creators) of this resource.
     */
    public function AddRelatedResourceOriginationName($relatedResourceOriginationName) {
        array_push($this->relatedResourceOriginationName, $relatedResourceOriginationName);
    }


    /**
     * Get the document type
     * 
     *  Get the document type for the document pointed to by this relation, such as "ArchivalResource" 
     *
     * * resourceRelation/@role
     * 
     * @return \snac\data\Term Document type
     *
     */
    function getDocumentType()
    {
        return $this->documentType;
    }

    /**
     * Get the xlink type
     * 
     * This should not be used, as it is always "simple" 
     *
     * Daniel says this is only a hard code 'simple' and we don't need to store it, but we will hard code it
     * in the export template.
     *
     * (old comment:) resourceRelation/@type
     * Actually: resourceRelation@xlink:type
     *
     * 'linkType' => 'simple',
     * 
     * @return \snac\data\Term Link type
     * @deprecated
     *
     */
    function getLinkType()
    {
        return $this->linkType;
    }

    /**
     * Get URI Link
     * 
     * Get the URI link for the document pointed to by this relation
     *
     * * resourceRelation/@href
     * 
     * @return string Link to external resource
     *
     */
    function getLink()
    {
        return $this->link;
    }

    /**
     * Get the source XML of this relation 
     *
     * * resourceRelation/objectXMLWrap
     * 
     * @return string XML source of the resource relation
     *
     */
    function getSource()
    {
        return $this->source;
    }

    /**
     * Returns this object's data as an associative array
     *
     * @param boolean $shorten optional Whether or not to include null/empty components
     * @return string[][] This objects data in array form
     */
    public function toArray($shorten = true) {
        $return = array(
            "dataType" => "Resource",
            "relatedResourceOriginationName" => array(),            
            "documentType" => $this->documentType == null ? null : $this->documentType->toArray($shorten),
            "linkType" => $this->linkType == null ? null : $this->linkType->toArray($shorten),
            "link" => $this->link,
            "source" => $this->source,
            "title" => $this->title,
            "abstract" => $this->abstract,
            "extent" => $this->extent,
            "repoIcId" => $this->repoIcId
        );
            
        foreach ($this->relatedResourceOriginationName as $vv) {
            array_push($return['relatedResourceOriginationName'], $vv->toArray($shorten));
        }

        $return = array_merge($return, parent::toArray($shorten));

        // Shorten if necessary
        if ($shorten) {
            $return2 = array();
            foreach ($return as $i => $v)
                if ($v != null && !empty($v))
                    $return2[$i] = $v;
            unset($return);
            $return = $return2;
        }

        return $return;
    }

    /**
     * Replaces this object's data with the given associative array
     *
     * @param string[][] $data This objects data in array form
     * @return boolean true on success, false on failure
     */
    public function fromArray($data) {
        if (!isset($data["dataType"]) || $data["dataType"] != "ResourceRelation")
            return false;

        parent::fromArray($data);

        unset($this->relatedResourceOriginationName);
        $this->relatedResourceOriginationName = array();
        if (isset($data['relatedResourceOriginationName'])) {
            foreach ($data['relatedResourceOriginationName'] as $entry) {
                if ($entry != null) {
                    array_push($this->relatedResourceOriginationName, new \snac\data\RROriginationName($entry));
                }
            }
        }

        if (isset($data["documentType"]) && $data["documentType"] != null)
            $this->documentType = new \snac\data\Term($data["documentType"]);
        else
            $this->documentType = null;

        if (isset($data["linkType"]) && $data["linkType"] != null)
            $this->linkType = new \snac\data\Term($data["linkType"]);
        else
            $this->linkType = null;

        if (isset($data["link"]))
            $this->link = $data["link"];
        else
            $this->link = null;

        if (isset($data["content"]))
            $this->content = $data["content"];
        else
            $this->content = null;

        if (isset($data["source"]))
            $this->source = $data["source"];
        else
            $this->source = null;
        
        if (isset($data["title"]))
            $this->title = $data["title"];
        else
            $this->title = null;

        if (isset($data["abstract"]))
            $this->abstract = $data["abstract"];
        else
            $this->abstract = null;

        if (isset($data["extent"]))
            $this->extent = $data["extent"];
        else
            $this->extent = null;

        if (isset($data["repoIcId"]))
            $this->repoIcId = $data["repoIcId"];
        else
            $this->repoIcId = null;

        return true;
    }


    /**
     * Set the document type for this relation
     *
     * @param \snac\data\Term $type Document type
     */
    public function setDocumentType($type) {

        $this->documentType = $type;
    }

    /**
     * Set the HREF link for this resource relation
     *
     * @param string $href Link
     */
    public function setLink($href) {

        $this->link = $href;
    }

    /**
     * Set the link type for this relation
     * 
     * @param \snac\data\Term $type Link type
     */
    public function setLinkType($type) {

        $this->linkType = $type;
    }

    /**
     * Set the XML source of this resource relation
     *
     * @param string $xml XML content for the resource relation
     */
    public function setSource($xml) {

        $this->source = $xml;
    }

    /**
     *
     * {@inheritDoc}
     *
     * @param \snac\data\ResourceRelation $other Other object
     * @param boolean $strict optional Whether or not to check id, version, and operation
     * @return boolean true on equality, false otherwise
     *       
     * @see \snac\data\AbstractData::equals()
     */
    public function equals($other, $strict = true) {

        if ($other == null || ! ($other instanceof \snac\data\Resource))
            return false;
        
        if (! parent::equals($other, $strict))
            return false;

        if ($this->getTitle() != $other->getTitle())
            return false;
        if ($this->getAbstract() != $other->getAbstract())
            return false;
        if ($this->getExtent() != $other->getExtent())
            return false;
        if ($this->getRepoIcId() != $other->getRepoIcId())
            return false;
        if (!$this->checkArrayEqual($this->getRelatedResourceOriginationName(), $other->getRelatedResourceOriginationName(), $strict)) {
            return false;
        }
        

        if ($this->getSource() != $other->getSource())
            return false;
        if ($this->getLink() != $other->getLink())
            return false;
        
        if (($this->getDocumentType() != null && ! $this->getDocumentType()->equals($other->getDocumentType())) ||
                 ($this->getDocumentType() == null && $other->getDocumentType() != null))
            return false;
        if (($this->getLinkType() != null && ! $this->getLinkType()->equals($other->getLinkType())) ||
                 ($this->getLinkType() == null && $other->getLinkType() != null))
            return false;
        
        return true;
    }
}