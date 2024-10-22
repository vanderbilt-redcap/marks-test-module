<?php namespace Vanderbilt\MarksTestModule;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function redcap_every_page_top(){
        if(!isset($_GET['enable-marks-test-module-troubleshooting'])){
            return;
        }

        ?>
        <script>
            console.log("Mark's Test Module Troubleshooting")
        </script>
        <?php

        throw new \Exception("This is a test Exception from Mark's Test Module.");
    }
}