<?php

/**
 * Snac SameAs File
 *
 * Contains the data class for sameAs or otherRecordID information
 *
 * License:
 *
 *
 * @author Robbie Hott
 * @license http://opensameAs or otherRecordID.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */
namespace snac\data;

/**
 * SameAs data storage class
 *
 * @author Robbie Hott
 *        
 */
class SameAs extends AbstractData {

    /**
     * @var string text of this sameAs or otherRecordID
     */
    private $text;

    /**
     * @var string URI of this sameAs or otherRecordID 
     */
    private $uri;

    /**
     * @var \snac\data\Term Type of this sameAs or otherRecordID
     */
    private $type;
    

    /**
     * Constructor
     */
    public function __construct($data = null) {
        $this->setMaxDateCount(0);
        parent::__construct($data);
    }

    /**
     * Get the text of this sameAs or otherRecordID
     *
     * @return string The description text/xml
     *
     */
    public function getText()
    {
        return $this->text;
    }

    /**
     * Get the URI of this sameAs or otherRecordID
     *
     * @return string The uri of this sameAs or otherRecordID
     */
    public function getURI() {
        return $this->uri;
    }

    /**
     * Get the type of this sameAs or otherRecordID
     *
     * @return \snac\data\Term The type of this sameAs or otherRecordID
     */
    public function getType() {
        return $this->type;
    }

    /**
     * Returns this object's data as an associative array
     *
     * @param boolean $shorten optional Whether or not to include null/empty components
     * @return string[][] This objects data in array form
     */
    public function toArray($shorten = true) {
        $return = array(
            "dataType" => "SameAs",
            "type" => $this->type == null ? null : $this->type->toArray($shorten),
            "text" => $this->text,
            "uri" => $this->uri
        );
            
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
        if (!isset($data["dataType"]) || $data["dataType"] != "SameAs")
            return false;

        parent::fromArray($data);

        if (isset($data["type"]) && $data["type"] != null)
            $this->type = new Term($data["type"]);
        else
            $this->type = null;

        if (isset($data["uri"]))
            $this->uri = $data["uri"];
        else
            $this->uri = null;

        if (isset($data["text"]))
            $this->text = $data["text"];
        else
            $this->text = null;

        return true;

    }

    /**
     * Set the text/xml of this SameAs
     * 
     * @param string $text The full text/xml of this sameAs or otherRecordID
     */
    public function setText($text) {

        $this->text = $text;
    }

    /**
     * Set the URI of this sameAs or otherRecordID
     *
     * @param string $uri The uri
     */
    public function setURI($uri) {
        $this->uri = $uri;
    }

    /**
     * Set the type of this sameAs or otherRecordID
     *
     * @param \snac\data\Term $type the type of this sameAs or otherRecordID
     */
    public function setType($type) {
        $this->type = $type;
    }
}