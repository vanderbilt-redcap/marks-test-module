<?php namespace Vanderbilt\MarksTestModule;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function cron(){
        try{
            throw new \Exception('
                Please disregard.  Mark is testing error handling.
            ');
        }
        catch(\Throwable $t){
            $message = str_replace("\n", "<br>", $t->__toString());
            $this->sendErrorEmail($message);
        }
    }

    private function sendErrorEmail($message){
        $to = 'mark.mcever@vumc.org';
        $from = $GLOBALS['from_email'];
        $subject = $this->getModuleName() . ' Module Error';
        
        \REDCap::email($to, $from, $subject, $message);
    }
}