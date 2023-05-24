<?php namespace Vanderbilt\MarksTestModule;

use Exception;
use ExternalModules\ExternalModules;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function redcap_every_page_top(){
        if(!isset($_GET['enable-datacore-customizations-module-troubleshooting'])){
            return;
        }

        if(!\System::useReadReplicaDB()){
            $result = 'replica not being used!';
        }
        else{
            $result = \ExternalModules\ExternalModules::getDiscoverableModules();
        }

        ?>
        <script>
            console.log('Datacore Customizations Module Troubleshooting:', <?=json_encode($result)?>)
        </script>
        <?php
    }
}