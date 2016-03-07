<?php
/**
 * Landing page of public web interface
 *
 * Creates an instance of the WebUI class and runs it
 *
 * @author Robbie Hott
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 * @copyright 2015 the Rector and Visitors of the University of Virginia, and
 *            the Regents of the University of California
 */


/**
 * Load and instantiate the web ui
 */
include ("../../../vendor/autoload.php");

// Namespace shortcuts
use \snac\client\webui\WebUI as WebUI;
use \Monolog\Logger;
use \Monolog\Handler\StreamHandler;

// Set up the global log stream
$log = new StreamHandler(\snac\Config::$LOG_DIR . \snac\Config::$WEBUI_LOGFILE, Logger::DEBUG);

// Use the REQUEST (GET, POST, COOKIE) variables as input
$input = $_REQUEST;

// Instantiate and run the server
$server = new WebUI($input);
$server->run();

// Return the content type and output of the server
foreach ($server->getResponseHeaders() as $header)
    header($header);
echo $server->getResponse();

// Exit
exit();
