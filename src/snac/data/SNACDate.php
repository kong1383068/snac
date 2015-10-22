<?php
/**
 * SNAC Date File
 *
 * Contains the date storage class.
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
 * SNACDate class
 * 
 * Storage class for dates.
 * 
 * @author Robbie Hott
 *
 */
class SNACDate {

    /**
     * @var string Begin date (if range)
     */
    private $fromDate;

    /**
     * @var string Original string given for the from date
     */
    private $fromDateOriginal;

    /**
     * @var string Type of the from date
     */
    private $fromType;

    /**
     * @var boolean If the from date is BC
     */
    private $fromBC;
    
    /**
     * $var string[] From date range
     */
    private $fromRange = array ("notBefore" => null, "notAfter" => null);

    /**
     * @var string End date (if range)
     */
    private $toDate;

    /**
     * @var string Original string given for the to date
     */
    private $toDateOriginal;

    /**
     * @var string Type of the to date
     */
    private $toType;

    /**
     * @var boolean If the to date is BC
     */
    private $toBC;

    /**
     * $var string[] To date range
     */
    private $toRange = array ("notBefore" => null, "notAfter" => null);

    /**
     * @var boolean If this SNACDate object contains a range or a single date
     */
    private $isRange;

    /**
     * @var string Note about this date
     */
    private $note;
    
    /**
     * Set whether or not this is a date range.
     * 
     * @param boolean $isRange Whether or not this is a range
     */
    public function setRange($isRange) {

        $this->isRange = $isRange;
    }

    /**
     * Set the from date in this object
     * 
     * @param string $original Original date
     * @param string $standardDate Standardized date
     * @param string $type Type of the date
     */
    public function setFromDate($original, $standardDate, $type) {

        list ($this->fromBC, $this->fromDate) = $this->parseBC($standardDate);
        $this->fromDateOriginal = $original;
        $this->fromType = $type;
    }
    
    /**
     * Set the fuzzy range around the from date
     * 
     * @param string $notBefore Beginning of fuzzy range
     * @param string $notAfter End of fuzzy range
     */
    public function setFromDateRange($notBefore, $notAfter) {
        $this->fromRange["notBefore"] = $notBefore;
        $this->fromRange["notAfter"] = $notAfter;
    }

    /**
     * Set the to date in this object
     * 
     * @param string $original Original date
     * @param string $standardDate Standardized date
     * @param string $type Type of the date
     */
    public function setToDate($original, $standardDate, $type) {

        list ($this->toBC, $this->toDate) = $this->parseBC($standardDate);
        $this->toDateOriginal = $original;
        $this->toType = $type;
    }

    /**
     * Set the fuzzy range around the to date
     * 
     * @param string $notBefore Beginning of fuzzy range
     * @param string $notAfter End of fuzzy range
     */
    public function setToDateRange($notBefore, $notAfter) {
        $this->toRange["notBefore"] = $notBefore;
        $this->toRange["notAfter"] = $notAfter;
    }

    /**
     * Set the single date in this object
     * 
     * @param string $original Original date
     * @param string $standardDate Standardized date
     * @param string $type Type of the date
     */
    public function setDate($original, $standardDate, $type) {

        $this->setFromDate($original, $standardDate, $type);
        $this->isRange = false;
    }
    
    /**
     * Set the fuzzy range around the date
     * 
     * @param string $notBefore Beginning of fuzzy range
     * @param string $notAfter End of fuzzy range
     */
    public function setDateRange($notBefore, $notAfter) {
        $this->setFromDateRange($notBefore, $notAfter);
    }

    /**
     * Set note about this date
     * 
     * @param string $note Note about this date
     */
    public function setNote($note) {
        $this->note = $note;
    }
    
    /**
     * Parse the given standard date string and determine if the date is BC and strip the date out if possible
     * 
     * @param string $standardDate The standard date
     * @return [boolean, string] Whether is BC or not and the standard date without negative.
     */
    public function parseBC($standardDate) {

        $tmp = $standardDate;
        $isBC = false;
        // If the standardDate starts with a minus sign, it is BC
        if (mb_substr($standardDate, 0, 1) == "-") {
            $isBC = true;
            $tmp = mb_substr($standardDate, 1);
        }
        return array (
                $isBC,
                $tmp
        );
    }
}