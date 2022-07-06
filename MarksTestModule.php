<?php namespace Vanderbilt\MarksTestModule;

use Exception;
use ExternalModules\ExternalModules;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    public static function downloadModule($module_id=null, $bypass=false, $sendUserInfo=false){
		$module_id = '1539';

        // Set modules directory path
		$modulesDir = dirname(APP_PATH_DOCROOT).DS.'modules'.DS;
		// Validate module_id
		if (empty($module_id) || !is_numeric($module_id)) return "0a";
		$module_id = (int)$module_id;
		// Also obtain the folder name of the module
		$moduleFolderName = http_get(APP_URL_EXTMOD_LIB . "download.php?module_id=$module_id&name=1");
        echo "1 - $moduleFolderName\n";
		if(empty($moduleFolderName) || $moduleFolderName == "ERROR"){
			//= The request to retrieve the name for module {0} from the repo failed: {1}.
			throw new Exception(ExternalModules::tt("em_errors_165", 
				$module_id, 
				$moduleFolderName)); 
		}

		// The following concurrent download detect was added to prevent a download/delete loop that we believe
		// brought the production server & specific modules down a few times:
		// https://github.com/vanderbilt/redcap-external-modules/issues/136
		$tempDir = $modulesDir . $moduleFolderName . '_tmp';
        try{
            if(file_exists($tempDir)){
                if(filemtime($tempDir) > time()-30){
                    // The temp dir was just created.  Assume another process is still actively downloading this module
                    // Simply tell the user to retry if this request came from the UI.
                    return '4a - Please wait a minute and try again.';
                }
                else{
                    // The last download process likely failed.  Removed the folder and try again.
                    var_dump('The last download process likely failed.  Removed the folder and try again');
                    self::removeModuleDirectory($tempDir);
                }
            }

            if(!mkdir($tempDir)){
                // Another process just created this directory and is actively downloading the module.
                // Simply tell the user to retry if this request came from the UI.
                return '4b';
            }

            // The temp dir was created successfully.  Open a `try` block so we can ensure it gets removed in the `finally`.

            // Send user info?
            if ($sendUserInfo) {
                $postParams = array('user'=>self::getUsername(), 'name'=>$GLOBALS['user_firstname']." ".$GLOBALS['user_lastname'], 
                                    'email'=>$GLOBALS['user_email'], 'institution'=>$GLOBALS['institution'], 'server'=>SERVER_NAME);
            } else {
                $postParams = array('institution'=>$GLOBALS['institution'], 'server'=>SERVER_NAME);
            }
            var_dump(['$postParams', $postParams]);

            $download = function($mixed) use ($postParams){
                $client = new \GuzzleHttp\Client(['verify' => false ]);

                if(is_numeric($mixed)){
                    $response = $client->request('POST', APP_URL_EXTMOD_LIB . "download.php?module_id=$mixed", [
                        'form_params' => $postParams
                    ]);

                    
                }
                else{
                    $response = $client->request('GET', $mixed);
                }

                $moduleZipContents = $response->getBody()->getContents();

                echo "download size $mixed - " . strlen($moduleZipContents) . "\n";
                if(strlen($moduleZipContents) < 1000){
                    var_dump(['$moduleZipContents', $moduleZipContents]);
                }

                return $moduleZipContents;
            };

            // Call the module download service to download the module zip
            $moduleZipContents = $download($module_id);

            $download(1542);
            $download(1544);
            $download('https://github.com/vanderbilt-redcap/flight-tracker/archive/refs/tags/4.12.2.zip');
            $download('https://redcap.vanderbilt.edu/plugins/mark-test/mycap-module-1.15.0.zip');
            $download('https://victr.vumc.org/wp-content/mycap-module-1.15.0.zip');

            return;

            // Errors?
            if ($moduleZipContents == 'ERROR') {
                // 0 = Module does not exist in library
                return "0b";
            }
            // Place the file in the temp directory before extracting it
            $filename = APP_PATH_TEMP . date('YmdHis') . "_externalmodule_" . substr(sha1(rand()), 0, 6) . ".zip";
            if (file_put_contents($filename, $moduleZipContents) === false) {
                // 1 = Module zip couldn't be written to temp
                return "1";
            }

            echo "zip size 1 - " . filesize($filename) . "\n";

            // Extract the module to /redcap/modules
            $zip = new \ZipArchive;
            $openStatus = $zip->open($filename);
            if ($openStatus !== TRUE) {
                return "2 $openStatus";
            }

            $showZip = function($zip){
                echo 'numFiles - ' . $zip->numFiles . "\n";
                for( $i = 0; $i < $zip->numFiles; $i++ ){
                    $stat = $zip->statIndex( $i );
                    $parts = explode('/', $stat['name']);
        
                    if(count($parts) < 3){
                        echo 'file: ' . $stat['name'] . "\n";
                    }
                }
            };

            $showZip($zip);

            // First, we need to rename the parent folder in the zip because GitHub has it as something else
            ExternalModules::normalizeModuleZip($moduleFolderName, $zip);
            $zip->close();

            echo "zip size 2 - " . filesize($filename) . "\n";

            // Now extract the zip to the modules folder
            $zip = new \ZipArchive;
            $openStatus = $zip->open($filename);
            if ($openStatus === TRUE) {
                if(!$zip->extractTo($tempDir)){
                    return 'extract failed';
                }

                $showZip($zip);

                $zip->close();
            }
            else{
                return "openStatus: $openStatus";
            }
            // Remove temp file
            unlink($filename);

            echo 'file count 1 - ' . iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
                )
            ) . "\n";

            ExternalModules::removeEditorDirectories($tempDir.DS.$moduleFolderName);

            echo 'file count 2 - ' . iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
                )
            ) . "\n";

            // Move the extracted directory to it's final location
            $moduleFolderDir = $modulesDir . $moduleFolderName . DS;

            var_dump([
                'source' => $tempDir.DS.$moduleFolderName,
                'destination' => $moduleFolderDir,
                'children' => glob($tempDir.DS.'*'),
            ]);

            if(file_exists($moduleFolderDir)){
                return 'destination already exists';
            }

            if(!rename($tempDir.DS.$moduleFolderName, $moduleFolderDir)){
                return 'rename failed';
            };

            echo 'file count 3 - old - ' . iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
                )
            ) . "\n";

            echo 'file count 4 - new - ' . iterator_count(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($moduleFolderDir, \FilesystemIterator::SKIP_DOTS)
                )
            ) . "\n";

            // Now double check that the new module directory got created
            if (!(file_exists($moduleFolderDir) && is_dir($moduleFolderDir))) {
                var_dump([
                    '3a' => file_exists($moduleFolderDir),
                    '3b' => is_dir($moduleFolderDir)
                ]);
                return "3";
            }
            
            self::removeModuleDirectory($tempDir);
            if(file_exists($tempDir)){
                return 'temp dir was not deleted!';
            }

            self::removeModuleDirectory($moduleFolderDir);
            if(file_exists($moduleFolderDir)){
                return 'module dir was not deleted!';
            }

            return 'success';
        }
        finally{
            self::removeModuleDirectory($tempDir);
        }
	}

    private static function removeModuleDirectory($path){
		$modulesDir = dirname(APP_PATH_DOCROOT).DS.'modules'.DS;
		$path = ExternalModules::getSafePath($path, $modulesDir);
		ExternalModules::rrmdir($path);
	}
}