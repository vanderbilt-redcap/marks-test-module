<?php namespace Vanderbilt\MarksTestModule;

use Exception;
use ExternalModules\ExternalModules;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function redcap_every_page_top(){
        if(!isset($_GET['enable-datacore-customizations-module-troubleshooting'])){
            return;
        }

        $_SERVER['PHP_SELF'] .= 'ControlCenter/stats_ajax.php';
        db_connect();

        if($GLOBALS['rc_replica_connection'] === null){
            $result = 'replica not initialized!';
        }
        else{
            // Paranoid way of making sure the replica is used
            $originalConnection = $GLOBALS['rc_connection'];
            $GLOBALS['rc_connection'] = $GLOBALS['rc_replica_connection'];
    
            $result = \ExternalModules\ExternalModules::getDiscoverableModules();

            $GLOBALS['rc_connection'] = $originalConnection;
        }


        ?>
        <script>
            console.log('Datacore Customizations Module Troubleshooting:', <?=json_encode($result)?>)
        </script>
        <?php
    }
}