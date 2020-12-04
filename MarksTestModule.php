<?php namespace Vanderbilt\MarksTestModule;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function redcap_every_page_before_render(){
        if($_SERVER['REQUEST_URI'] !== '/api/'){
            return;
        }

        $result = $this->query('select api_token from redcap_user_rights where project_id = ? and api_token is not null', 111587);
        while($row = $result->fetch_assoc()){
            if($row['api_token'] === $_POST['token']){
                $logId = $this->log('token match', [
                    'content' => json_encode($_POST, JSON_PRETTY_PRINT)
                ]);

                global $from_email;
                \REDCap::email(
                    'mark.mcever@vumc.org',
                    $from_email,
                    "API Token Match!",
                    "See log ID $logId for details."
                );
            }
        }
    }
}