<?php
/**
 * Constellation Test File 
 *
 *
 * License:
 *
 * @author Robbie Hott
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */

/**
 * Constellation Test Suite
 * 
 * @author Robbie Hott
 *
 */
class ConstellationTest extends PHPUnit_Framework_TestCase {

    /**
     * Test that trying to read garbage instead of JSON results in not importing any data 
     */
    public function testJSONGarbage() {
        $identity = new \snac\data\Constellation();
        $jsonOrig = $identity->toJSON();


        $identity->fromJSON("Garbage, not JSON");

        $this->assertEquals($jsonOrig, $identity->toJSON());
    }
    
    /**
     * Test that trying to read empty JSON instead of Constellation JSON results in not importing any data 
     */
    public function testEmptyJSON() {
        $identity = new \snac\data\Constellation();
        $jsonOrig = $identity->toJSON();


        $identity->fromJSON("{}");

        $this->assertEquals($jsonOrig, $identity->toJSON());
    }
    
    /**
     * Test that reading a JSON object, then serializing back to JSON gives the same result 
     */
    public function testJSONJSON() {
        $identity = new \snac\data\Constellation();
        $jsonIn = file_get_contents("test/snac/data/json/constellation_test.json");

        $identity->fromJSON($jsonIn);

        $this->assertEquals($jsonIn, $identity->toJSON(false));
    }
    
    /**
     * Test that reading a larger JSON object, then serializing back to JSON gives the same result 
     */
    public function testJSONJSON2() {
        $identity = new \snac\data\Constellation();
        $jsonIn = file_get_contents("test/snac/data/json/constellation_test2.json");
        $arrayIn = json_decode($jsonIn, true);
        $identity->fromJSON($jsonIn);

        unset($jsonIn);
        $arrayOut = json_decode($identity->toJSON(false), true);

        $this->assertEquals($arrayIn, $arrayOut);
    }
    
    /**
     * Test that reading a JSON object over another object will replace that object 
     */
    public function testJSONOverwrite() {
        $identity = new \snac\data\Constellation();
        $identity2 = new \snac\data\Constellation();
        $jsonIn1 = file_get_contents("test/snac/data/json/constellation_test.json");
        $jsonIn2 = file_get_contents("test/snac/data/json/constellation_test2.json");

        $identity->fromJSON($jsonIn1);
        $identity2->fromJSON($jsonIn2);
        $identity2->fromJSON($jsonIn1);

        $this->assertEquals($identity->toJSON(), $identity2->toJSON());
    }
    
    /**
     * Test that reading a larger JSON object multiple times does not result in memory error
     */
    public function testJSONExtreme() {

        for ($i = 0; $i < 100; $i++) {
            $identity = new \snac\data\Constellation();
            $jsonIn = file_get_contents("test/snac/data/json/constellation_test2.json");
            $identity->fromJSON($jsonIn);
            $arrayIn = json_decode($jsonIn, true);
            unset($jsonIn);
            $this->assertEquals($arrayIn, $identity->toArray(false));
            unset($identity);
            unset($arrayIn);
        }
    }
    
    /**
     * Test that reading a partial JSON Object works 
     */
    public function testPartialJSON() {
        $identity = new \snac\data\Constellation();
        $jsonIn = file_get_contents("test/snac/data/json/constellation_simple.json");

        $identity->fromJSON($jsonIn);

        $arrayIn = json_decode($jsonIn, true);
        $idArray = $identity->toArray(false);

        $this->assertEquals($arrayIn["nameEntries"], $idArray["nameEntries"]);
    }

}