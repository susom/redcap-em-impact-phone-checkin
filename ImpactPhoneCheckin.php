<?php

namespace Stanford\ImpactPhoneCheckin;

use Plugin;
use REDCap;
use Services_Twilio;
use Exception;
use Message;
use DateTime;

class ImpactPhoneCheckin extends \ExternalModules\AbstractExternalModule
{

    /**
     * Convert phone nubmer to E.164 format before handing off to Twilio
     * @param $phoneNumber
     * @return mixed|string
     */
    public static function formatNumber($phoneNumber)
    {
        // If number contains an extension (denoted by a comma between the number and extension), then separate here and add later
        $phoneExtension = "";
        if (strpos($phoneNumber, ",") !== false) {
            list ($phoneNumber, $phoneExtension) = explode(",", $phoneNumber, 2);
        }
        // Remove all non-numerals
        $phoneNumber = preg_replace("/[^0-9]/", "", $phoneNumber);
        // Prepend number with + for international use cases
        $phoneNumber = (isPhoneUS($phoneNumber) ? "+1" : "+") . $phoneNumber;
        // If has an extension, re-add it
        if ($phoneExtension != "") $phoneNumber .= ",$phoneExtension";
        // Return formatted number
        return $phoneNumber;
    }

    /**
     * The filter in the REDCap::getData expects the phone number to be in
     * this format (###) ###-####
     *
     * @param $number
     * @return
     */
    public static function formatToREDCapNumber($number)
    {
        $formatted = preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1) $2-$3', $number);
        return trim($formatted);

    }

    public function findRecordByPhone($phone, $phone_field, $phone_field_event)
    {

        $this->emDebug("Locate record for this phone: " . $phone);
        $get_fields = array(
            REDCap::getRecordIdField(),
            $phone_field
        );
        $event_name = REDCap::getEventNames(true, false, $phone_field_event);
        $filter = "[" . $event_name . "][" . $phone_field . "] = '$phone'";

        //$this->emDebug("FILTER IS ".$filter);
        //$records = REDCap::getData('array', null, $get_fields, null, null, false, false, false, $filter);
        //$this->emDebug($filter, $records, $project_id, $pid, $filter, $event_name);

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format'=>'array',
            'events'=>$event_name,
            'fields'=>array( REDCap::getRecordIdField(),$phone_field),
            'filterLogic'=>$filter
        );

        $records = REDCap::getData($params);
        //$this->emDebug($filter, $records, $project_id, $pid, $filter, $event_name);

        // return record_id or false
        reset($records);
        $first_key = key($records);
        return ($first_key);
    }


    function checkInSession($rec_id) {
        //infer the new record id from rec_id and date: recid-yyyy-mm-dd
        $today = new DateTime();
        $candidate_id = $rec_id."-".$today->format('Y-m-d');

        //$this->emDebug('candidate exercise id is $candidate_id'.$candidate_id); exit;

        //event for this form
        $checkin_fk_field = $this->getProjectSetting('exercise-fk-field');
        $checkin_event = $this->getProjectSetting('exercise-checkin-event');
        $checkin_date_field = $this->getProjectSetting('exercise-date-field');
        $checkin_timestamp_field = $this->getProjectSetting('exercise-checkin-timestamp-field');
        $checkin_phone_checkin_field = $this->getProjectSetting('exercise-phone-checkin-field');

        $event_name = REDCap::getEventNames(true, false, $checkin_event);
        $filter = "[" . $event_name . "][" . REDCap::getRecordIdField() . "] = '$candidate_id'";


        //check that it already hasn't been created for today
        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format'=>'json',
            'events'=>$checkin_event,
            'fields'=>array( REDCap::getRecordIdField(),$checkin_date_field,$checkin_timestamp_field ),
            'filterLogic'=>$filter
        );

        $json = REDCap::getData($params);
        $records = json_decode($json);

        //$this->emDebug($records, $project_id, $pid, $filter, $event_name); exit;
        $msg = "Checked in.";
        $checked_in = true;

        if (empty($records)) {
            //checkin
            $this->emDebug("Checking in this candidate: ".$candidate_id);

            $save_params = array(
                REDCap::getRecordIdField()       => $candidate_id,
                $checkin_fk_field                => $rec_id,
                $checkin_date_field              => $today->format('Y-m-d'),
                $checkin_timestamp_field         => $today->format('Y-m-d h:i:s'),
                $checkin_phone_checkin_field.'___1' => 1,
                'redcap_event_name'              => $event_name
            );

            $status = REDCap::saveData('json', json_encode(array($save_params)));

            //$this->emDebug($status, $save_params, 'STATUS');

            if ($status['errors']) {
                $msg = "Checkin Failed: ".$this->emError($status['errors']);
                $checked_in = false;
            }


        } else {
            $msg = "Checkin Failed: "."Check in already exists for ". $candidate_id;
            $checked_in = false;
            $this->emDebug($msg);

        }

        return(
            array(
                'STATUS'=>$checked_in,
                'MESSAGE'=>$msg
            )
        );

        //check that check hasn't happened already

        //create the form for meta data:

    }



    /**
     * Log the STOP text to the sms_log
     *
     * @param $rec_id
     */
    function logSms($log_field, $log_event, $rec_id, $msg_info)
    {
        $msg = array();
        $msg[] = "---- " . date("Y-m-d H:i:s") . " ----";
        $msg[] = $msg_info;

        $data = array(
            REDCap::getRecordIdField() => $rec_id,
            'redcap_event_name' => $log_event,
            $log_field => implode("\n", $msg),

        );

        REDCap::saveData($data);
        $response = REDCap::saveData('json', json_encode(array($data)));
        //$this->emDebug($response,  "Save Response for count");


        if (!empty($response['errors'])) {
            $msg = "Error creating record - ask administrator to review logs: " . json_encode($response);
            $this->emDebug($msg);
            return ($response);
        }

    }

    function sendEmail($to, $from, $subject, $msg)
    {

        // Prepare message
        $email = new Message();
        $email->setTo($to);
        $email->setFrom($from);
        $email->setSubject($subject);
        $email->setBody($msg);

        //logIt("about to send " . print_r($email,true), "DEBUG");

        // Send Email
        if (!$email->send()) {
            $this->emLog('Error sending mail: ' . $email->getSendError() . ' with ' . json_encode($email));
            return false;
        }

        return true;
    }


    /**
     *
     * emLogging integration
     *
     */
    function emLog()
    {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug()
    {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || (!empty($_GET['pid']) && $this->getProjectSetting('enable-project-debug-logging'))) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError()
    {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }

}