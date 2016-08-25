<?php

/**
 * AddressLine Class file
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
 * Address Line Class
 *
 * This class contains the parts of a place's address.
 *
 * @author Robbie Hott
 */
class AddressLine extends AbstractOrderedComponent {

    /**
     * Constructor.
     *
     * @param string[] $data A list of data suitable for fromArray(). This exists for use by internal code to
     * send objects around the system, not for generally creating a new object.
     * 
     */
    public function __construct($data = null) {
        $this->setMaxDateCount(0);
        $this->setDataType("AddressLine");
        parent::__construct($data);
    }
}
