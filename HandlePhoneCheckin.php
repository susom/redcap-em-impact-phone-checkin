<?php
namespace Stanford\ImpactPhoneCheckin;
/** @var \Stanford\ImpactPhoneCheckin\ImpactPhoneCheckin $module */

// Get the PHP helper library from https://twilio.com/docs/libraries/php

// this line loads the library
//require_once __DIR__ . '/vendor/autoload.php';
//require_once $module->getModulePath().'/vendor/autoload.php';

require_once APP_PATH_DOCROOT . "/Libraries/Twilio/Services/Twilio.php";

//use Twilio\TwiML;
use REDCap;

$module->emDebug('--- Incoming Call to Twilio ---');

/**
 *
 * This is called from Twilio Webhook set up from the calling number
 */

$response = new \Services_Twilio_Twiml();

//$webhook = $module->getUrl("ImpactPhoneCheckin.php", true, true);
//$module->emDebug($webhook);

$phone_field = $module->getProjectSetting('phone-lookup-field');
$phone_field_event = $module->getProjectSetting('phone-lookup-field-event');

// Get the phone number to search REDCap
$from = $_REQUEST['From'];

$module->emDebug($_REQUEST);

if (!isset($from)) {
    $module->emLog("No phone number reported");
    exit;
}
$from_10 = substr($from, -10);


// Get the body of the message
$body = isset($_POST['Body']) ? $_POST['Body'] : '';

//use the phone number to look for the record id
$rec_id = $module->findRecordByPhone($module->formatToREDCapNumber($from_10), $phone_field, $phone_field_event);
$module->emDebug("Rec ID is $rec_id found for phone number $from_10");


//Looks like there is no record affiliated with that phone number
if (!$rec_id) {
    $module->emLog($body, "Received voice call from unknown number: " . $from_10);

    // email coordinator to let them know of text from unaffiliated number
    $to =$module->getProjectSetting('email-to');
    $from = $module->getProjectSetting('email-from');
    $subject = $module->getProjectSetting('forwarding-email-subject');
    $msg = "We have received a call from a phone number that is not in the project: " . $from_10;

    $module->sendEmail($to, $from, $subject, $msg);

    $response->say("This number is not recognized.", array('voice' => 'alice'));
    print $response;

    exit();
}

$module->emDebug("Call received from phone " . $from_10 . ".  Checking in ". $rec_id);

//Check in a new session for today for this record.
$checkin_status = $module->checkInSession($rec_id);

$msg = "We have received phone checkin from: ".
    "<br>PHONE NUMBER: " . $from_10 .
    "<br>RECORD_ID: " . $rec_id ;
$msg .= "\nSTATUS: ".$checkin_status['MESSAGE'];

//If log field is specified, log to REDCap
$log_field = $module->getProjectSetting('log-field');
$log_event = $module->getProjectSetting('log-field-event');
$log_event_name = REDCap::getEventNames(true, false, $log_event);


if (isset($log_field)) {
    $module->logSms($log_field, $log_event_name, $rec_id, $msg );
    $module->emDebug($rec_id . " : ". $msg);
}

if ($checkin_status['STATUS']) {
    $response->say("hello! You have been checked in as $rec_id", array('voice' => 'alice'));
    print $response;
} else {
    $response->say($checkin_status['MESSAGE'], array('voice' => 'alice'));
    print $response;
}

