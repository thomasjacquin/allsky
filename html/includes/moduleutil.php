<?php
// TODO: Ensure XHR check
include_once('functions.php');
initialize_variables();		// sets some variables

include_once('authenticate.php');

class MODULEUTIL
{
    private $request;
    private $method;
    private $jsonResponse = false;
    private $allskyModules;
    private $userModules;
	private $extraDataFolder;

    function __construct() {
        $this->allskyModules = ALLSKY_SCRIPTS . '/modules';
        $this->userModules = ALLSKY_MODULE_LOCATION . '/modules';
		$this->extraDataFolder = ALLSKY_OVERLAY . '/extra';
    }

    public function run()
    {
        //$this->checkXHRRequest();
        $this->sanitizeRequest();
        $this->runRequest();
    }

    private function checkXHRRequest()
    {
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
            $this->send404();
        }
    }

    private function sanitizeRequest()
    {
        $this->request = $_GET['request'];
        $this->method = strtolower($_SERVER['REQUEST_METHOD']);

        $accepts = $_SERVER['HTTP_ACCEPT'];
        if (stripos($accepts, 'application/json') !== false) {
            $this->jsonResponse = true;
        }
    }

    private function send404()
    {
        header('HTTP/1.0 404 Not Found');
        die();
    }

    private function send500($error = "Internal Server Error")
    {
        header('HTTP/1.0 500 ' . $error);
        die();
    }

    private function sendResponse($response = 'ok')
    {
        echo ($response);
        die();
    }

    private function runRequest() {
        $action = $this->method . $this->request;

        if (is_callable(array('MODULEUTIL', $action))) {
            call_user_func(array($this, $action));
        } else {
            $this->send404();
        }
    }

    private function getMetaDataFromFile($fileName) {
        $fileContents = file($fileName);
        $metaData = "";
        $found = False;

        foreach ($fileContents as $sourceLine) {
            $line = str_replace(" ", "", $sourceLine);
            $line = str_replace("\n", "", $line);
            $line = str_replace("\r", "", $line);
            $line = strtolower($line);
            if ($line == "metadata={") {
                $found = true;
                $sourceLine = str_ireplace("metadata","", $sourceLine);
                $sourceLine = str_ireplace("=","", $sourceLine);
                $sourceLine = str_ireplace(" ","", $sourceLine);
            }

            if ($found) {
                $metaData .= $sourceLine;
            }

            if (substr($sourceLine,0,1) == "}" && $found) {;
                break;
            }
        }

        return $metaData;
    }

    private function readModuleData($moduleDirectory, $type, $event) {
        $arrFiles = array();
        $handle = opendir($moduleDirectory);

        if ($handle) {
            while (($entry = readdir($handle)) !== FALSE) {
                if (preg_match('/^allsky_/', $entry)) {
                    if ($entry !== 'allsky_shared.py' && $entry !== 'allsky_base.py') {
                        $fileName = $moduleDirectory . '/' . $entry;
                        $metaData = $this->getMetaDataFromFile($fileName);
                        $decoded = json_decode($metaData);
                        if (in_array($event, $decoded->events)) {
                            if (isset($decoded->experimental)) {
                                $experimental = strtolower($decoded->experimental) == "true"? true: false;
                            } else {
                                $experimental = false;
                            }
                            $arrFiles[$entry] = [
                                'module' => $entry,
                                'metadata' => $decoded,
                                'type' => $type
                            ];
                            $arrFiles[$entry]['metadata']->experimental = $experimental;
                        }
                    }
                }
            }
        }

        closedir($handle);

        return $arrFiles;
    }

    private function startsWith ($string, $startString) {
        $len = strlen($startString);
        return (substr($string, 0, $len) === $startString);
    }

    private function endsWith($string, $endString) {
        $len = strlen($endString);
        if ($len == 0) {
            return true;
        }
        return (substr($string, -$len) === $endString);
    }

    private function changeOwner($filename) {
        $user = get_current_user();
        exec("sudo chown " . $user . " " . $filename);
    }

    public function getModulesSettings() {
        $configFileName = ALLSKY_MODULES . '/module-settings.json';
        $rawConfigData = file_get_contents($configFileName);

        $this->sendResponse($rawConfigData);
    }

    public function getRestore() {
        $flow = $_GET['flow'];

        $configFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($flow) . '.json';
        $backupConfigFileName = $configFileName . '-last';
        copy($backupConfigFileName, $configFileName);
        $this->changeOwner($configFileName);
        $this->sendResponse();
    }

    public function postModulesSettings() {
        $configFileName = ALLSKY_MODULES . '/module-settings.json';
        $settings = $_POST['settings'];
        $formattedJSON = json_encode(json_decode($settings), JSON_PRETTY_PRINT);

        $result = file_put_contents($configFileName, $formattedJSON);
        if ($result) {
            $this->sendResponse();
        } else {
            $this->send500('Cannot write to module settings flile');
        }
    }

    public function getModuleBaseData() {
        global $settings_array;		// defined in initialize_variables()
        $angle = $settings_array['angle'];
        $lat = $settings_array['latitude'];
        $lon = $settings_array['longitude'];

        $result['lat'] = $lat;
        $result['lon'] = $lon;
        $imageDir = get_variable(ALLSKY_HOME . '/variables.sh', "IMG_DIR=", 'current/tmp');
        $result['filename'] = $imageDir . '/' . $settings_array['filename'];

        exec("sunwait poll exit set angle $angle $lat $lon", $return, $retval);
        if ($retval == 2) {
            $result['tod'] = 'day';
        } else if ($retval == 3) {
            $result['tod'] = 'night';
        } else {
            $result['tod'] = '';
        }

        $result['version'] = ALLSKY_VERSION;

        $configFileName = ALLSKY_MODULES . '/module-settings.json';
        $rawConfigData = file_get_contents($configFileName);
        $configData = json_decode($rawConfigData);

        $result['settings'] = $configData;
        $formattedJSON = json_encode($result, JSON_PRETTY_PRINT);
        $this->sendResponse($formattedJSON);
    }

    public function getModules() {
        $result = $this->readModules();
        $result = json_encode($result, JSON_FORCE_OBJECT);
        $this->sendResponse($result);
    }

    private function readModules() {
        $configFileName = ALLSKY_MODULES . '/module-settings.json';
        $rawConfigData = file_get_contents($configFileName);
        $moduleConfig = json_decode($rawConfigData);

		$secrets = json_decode(file_get_contents(ALLSKY_ENV));

        $event = $_GET['event'];
        $configFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($event) . '.json';
        $debugFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($event) . '-debug.json';
        $rawConfigData = file_get_contents($configFileName);
        $configData = json_decode($rawConfigData);

        $corrupted = false;
        if ($configData == null) {
            $corrupted = true;
            $configData = array();
        }

        $coreModules = $this->readModuleData($this->allskyModules, "system", $event);
        $userModules = $this->readModuleData($this->userModules, "user", $event);
        $allModules = array_merge($coreModules, $userModules);

        $availableResult = [];
        foreach ($allModules as $moduleData) {
            $module = str_replace('allsky_', '', $moduleData["module"]);
            $module = str_replace('.py', '', $module);

            if (!isset($configData->{$module})) {
                $moduleData["enabled"] = false;
                $availableResult[$module] = $moduleData;
            }
        }

        $selectedResult = [];
        foreach($configData as $selectedName=>$data) {
            $moduleName = "allsky_" . $selectedName . ".py";
            $moduleData = $allModules[$moduleName];

            if (isset($data->metadata->arguments)) {
                if (isset($moduleData['metadata']->arguments)) {
                    foreach ((array)$moduleData['metadata']->arguments as $argument=>$value) {

                        if (!isset($data->metadata->arguments->$argument)) {
							$data->metadata->arguments->$argument = $value;
                        }
						
						# If field is a 'secret' field then get the value from the env file
						if ($moduleData["metadata"]->argumentdetails->$argument->secret !== null) {
							if ($moduleData["metadata"]->argumentdetails->$argument->secret === 'true') {
								$secretKey = strtoupper($data->metadata->module) . '.' . strtoupper($argument);
								if (isset($secrets->$secretKey)) {
									$data->metadata->arguments->$argument = $secrets->$secretKey;
								}
							}
						}


                    }
                }
                $moduleData["metadata"]->arguments = $data->metadata->arguments;
            } else {
                $moduleData["metadata"]->arguments = [];
            }
            if (isset($data->enabled)) {
                $moduleData["enabled"] = $data->enabled;
            } else {
                $moduleData["enabled"] = false;
            }
            if ($selectedName == 'loadimage') {
                $moduleData['position'] = 'first';
            }
            if ($selectedName == 'saveimage') {
                $moduleData['position'] = 'last';
            }

            if (isset($data->lastexecutiontime)) {
                $moduleData['lastexecutiontime'] = $data->lastexecutiontime;
            } else {
                $moduleData['lastexecutiontime'] = '0';
            }
            if (isset($data->lastexecutionresult)) {
                $moduleData['lastexecutionresult'] = $data->lastexecutionresult;
            } else {
                $moduleData['lastexecutionresult'] = '';
            }

            $selectedResult[$selectedName] = $moduleData;
        };

        $restore = false;
        if (file_exists($configFileName . '-last')) {
            $restore = true;
        }

        $debugInfo = null;
        if (file_exists($debugFileName)) {
            $debugInfo = file_get_contents($debugFileName);
            $debugInfo = json_decode($debugInfo);
        }

        $result = [
            'available' => $availableResult,
            'selected'=> $selectedResult,
            'corrupted' => $corrupted,
            'restore' => $restore,
            'debug' => $debugInfo,
            'help' => $this->getModuleHelp()
        ];

        return $result;
    }

    private function getModuleHelpFromFolder($folder): array {
        $result = array();
        $types = ['txt', 'html', 'md'];
        if (file_exists($folder)) {
            $handle = opendir($folder);
            if ($handle) {
                while (($entry = readdir($handle)) !== FALSE) {
                    if ($entry !== '.' && $entry !== '..') {
                        //TODO: Add HTML help or markdown
                        foreach ($types as $key=>$type) {
                            $fileName = $folder . '/' . $entry . '/readme.' . $type;
                            if (file_exists($fileName)) {
                                $text = file_get_contents($fileName);
                                $module = str_replace('allsky_', '', $entry);
                                $module = str_replace('.py', '', $module);
                                if (!isset($result[$module])) {
                                    $result[$module] = [];
                                }
                                if ($type == 'txt') {
                                    $text = nl2br($text);
                                }
                                $result[$module][$type] = $text;
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    private function getModuleHelp() {
        //TODO: Not sure about this location
        $coreHelpFolder = ALLSKY_SCRIPTS . '/modules/info';
        $extraHelpFolder = ALLSKY_MODULE_LOCATION . '/modules/info';

        $help = $this->getModuleHelpFromFolder($coreHelpFolder);
        $extraHelp = $this->getModuleHelpFromFolder($extraHelpFolder);

        $help = array_merge($help, $extraHelp);

        return $help;
    }

    public function postModules() {
        $config = $_POST['config'];
        $configData = $_POST['configData'];
        $configFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($config) . '.json';

        $configFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($config) . '.json';
        $rawConfigData = file_get_contents($configFileName);
        $oldModules = json_decode($rawConfigData);

		$configDataJson = json_decode($configData);
		$envData = null;
		foreach ($configDataJson as $module=>&$moduleConfig) {
			foreach ($moduleConfig->metadata->argumentdetails as $argument=>$argumentSettings) {
				if (isset($argumentSettings->secret)) {
					if ($envData === null) {
						$envData = json_decode(file_get_contents(ALLSKY_ENV));
					}
					$secretKey = strtoupper($moduleConfig->metadata->module) . '.' . strtoupper($argument);
					$envData->$secretKey = $moduleConfig->metadata->arguments->$argument;
					$moduleConfig->metadata->arguments->$argument = '';
				}
			}
		}
		$configData = json_encode($configDataJson, JSON_PRETTY_PRINT);
		if ($envData !== null) {
			file_put_contents(ALLSKY_ENV, json_encode($envData, JSON_PRETTY_PRINT));
		}
		 
        $result = file_put_contents($configFileName, $configData);
        $this->changeOwner($configFileName);
        $backupFilename = $configFileName . '-last';
        copy($configFileName, $backupFilename);
        $this->changeOwner($backupFilename);
        if ($result !== false) {
            $newModules = json_decode($configData);
            $this->CheckForDisabledModules($newModules, $oldModules);
            $this->sendResponse();
        } else {
            $this->send500();
        }
    }

    private function CheckForDisabledModules($newModules, $oldModules) {
        $moduleList = [];

        foreach ($oldModules as $key=>$module) {
            $moduleList[$key] = $module->module;
        }

        foreach ($newModules as $key=>$module) {
            if (isset($moduleList[$key])) {
                if ($oldModules->{$key}->enabled == $module->enabled) {
                    unset($moduleList[$key]);
                } else {
                    if ($oldModules->{$key}->enabled == false && $module->enabled == true) {
                        unset($moduleList[$key]);
                    }
                }
            }
        }

        $disableFile = ALLSKY_TMP . '/disable';
        if (count($moduleList) > 0) {
            if (file_exists($disableFile)) {
                $oldDisableData = file_get_contents($disableFile);
                $oldDisableData = json_decode($oldDisableData, true);
                $moduleList = array_merge($moduleList, $oldDisableData);
            }
            $disableData = json_encode($moduleList);
            $result = file_put_contents($disableFile, $disableData);
        }

    }

    public function deleteModules() {
        $result = False;
        $module = $_GET['module'];

        if ($this->startswith($module, "allsky_") && $this->endswith($module, ".py")) {
            $targetPath = $this->userModules . '/' . $module;
            if (file_exists($targetPath)) {
                $result = unlink($targetPath);
            }
        }

        if ($result) {
            $this->sendResponse();
        } else {
            $this->send500('Failed to delete module ' . $module);
        }
    }

    public function getReset() {
        $flow = $_GET['flow'];

        $sourceConfigFileName = ALLSKY_REPO . '/modules/postprocessing_' . strtolower($flow) . '.json';
        $rawConfigData = file_get_contents($sourceConfigFileName);
        $configFileName = ALLSKY_MODULES . '/' . 'postprocessing_' . strtolower($flow) . '.json';
        file_put_contents($configFileName, $rawConfigData);
        $this->changeOwner($configFileName);

        $this->sendResponse();
    }

	private function runShellCommand($command) {
		$descriptors = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open($command, $descriptors, $pipes);
		
		if (is_resource($process)) {
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
		
			$returnCode = proc_close($process);
			if ($returnCode > 0) {
				$result = [
					'error' => true,
					'message' =>  $stdout . $stderr					
				];
			} else {
				$result = [
					'error' => false,
					'message' => $stdout					
				];				
			}
		}

		return $result;
	}

	private function addSecretsToFlow($configData) {
		$configDataJson = json_decode($configData);
		$envData = null;
		foreach ($configDataJson as $module=>&$moduleConfig) {
			foreach ($moduleConfig->metadata->argumentdetails as $argument=>$argumentSettings) {
				if (isset($argumentSettings->secret)) {
					if ($envData === null) {
						$envData = json_decode(file_get_contents(ALLSKY_ENV));
					}
					$secretKey = strtoupper($moduleConfig->metadata->module) . '.' . strtoupper($argument);
					if (isset($envData->$secretKey)) {
						$moduleConfig->metadata->arguments->$argument = $envData->$secretKey;
					} 
				}
			}
		}
		$configData = json_encode($configDataJson, JSON_PRETTY_PRINT);
		return $configData;
	}

	public function postTestModule() {
        $module=trim(filter_input(INPUT_POST, 'module', FILTER_SANITIZE_STRING));
        $dayNight=trim(filter_input(INPUT_POST, 'dayNight', FILTER_SANITIZE_STRING));        
        $flow = $_POST['flow'];

		$flow = $this->addSecretsToFlow($flow);

        $fileName = ALLSKY_MODULES . '/test_flow.json';
        file_put_contents($fileName,  $flow);

        #TODO add bash to sudoers
        $command = 'sudo bash -c "source /home/pi/allsky/venv/bin/activate; export ALLSKY_HOME=/home/pi/allsky; source /home/pi/allsky/variables.sh; export CURRENT_IMAGE=""; export DAY_OR_NIGHT="' . $dayNight . '"; source /home/pi/allsky/variables.sh; python3 ' . ALLSKY_SCRIPTS . '/flow-runner.py --test"';
        $result = $this->runShellCommand($command);

		$jsonFlow = json_decode($flow, true);
		
		$extraData = '';
		$moduleKey = array_key_first($jsonFlow);
		if (isset($jsonFlow[$moduleKey]['metadata']['extradatafilename'])) {
			$filePath = $this->extraDataFolder . '/' . $jsonFlow[$moduleKey]['metadata']['extradatafilename'];
			if (file_exists($filePath)) {
				$extraData = file_get_contents($filePath);
			}			
		}

        if ($result['error']) {
            die($result['message']);
            $this->send500();
        } else {
			$result = [
				'message' => $result['message'],
				'extradata' => json_decode($extraData)
			];
		    $this->sendResponse(json_encode($result));
        }
	}

    public function getAllskyVariables() {
        $sourceDir = ALLSKY_OVERLAY . '/extra';
        $variables = [];

        $handle = opendir($sourceDir);

        if ($handle) {
            while (($entry = readdir($handle)) !== FALSE) {
                if ($entry !== '.' && $entry !== '..') {
                    $fileName = $sourceDir . '/' . $entry;
                    $data = file_get_contents($fileName);

                    $extension = pathinfo($entry, PATHINFO_EXTENSION);

                    if ($extension == 'json') {
                        $jsonData = json_decode($data);
                        foreach ($jsonData as $key => $value) {
                            $variables[] = [
                                'variable' => $key,
                                'lastvalue' => $value
                            ];
                        }
                    }
                    if ($extension == 'txt') {
                        #TODO - Add code !
                    }
                }
            }
        }

        $this->sendResponse(json_encode($variables));
    }
	
	/*
    private function getdebugVariables() {
        $result = [];
        $fileName = ALLSKY_TMP . '/overlaydebug.txt';

        if (file_exists($fileName)) {
            $fields = file($fileName);

            if ($fields !== false) {
                $fieldData = [];
                $count = 0;
                foreach ($fields as $field) {
                    $fieldSplit = explode(" ", $field, 2);
                    // Fields that have \n in them will be split and
                    // the line after \n may not have any spaces.
                    // Silently ignore these lines since they aren't errors.

                    // TODO: Whatever creates this file should handle fields with \n.
                    // If the line(s) after the \n have spaces in them,
                    // they will be treated as fields, which they aren't.
                    if (count($fieldSplit) > 1) {
                        $value = trim($fieldSplit[1]);
                        $value = iconv("UTF-8","ISO-8859-1//IGNORE",$value);
                        $value = iconv("ISO-8859-1","UTF-8",$value);
                        if (substr($fieldSplit[0],0,3) == "AS_") {
                            $result[$fieldSplit[0]] = $value;
                            $count++;
                        }
                    }
                }
            }
        }

        return $result;
     
    }

    private function getModulevariableList($folder, $module='', $isExtra=false) {
        $variables = new stdClass();

        $handle = opendir($folder);
        if ($handle) {
            while (($entry = readdir($handle)) !== FALSE) {
                if ($isExtra) {
                    if ($entry !== '.' && $entry !== '..') {
                        $fileName = $folder . '/' . $entry;
                        $data = file_get_contents($fileName);
                        $decoded = json_decode($data);
                        $variables = (object)array_merge((array)$variables, (array)$decoded);
                    }
                } else {
                    if (preg_match('/^allsky_/', $entry)) {
                        if ($entry !== 'allsky_shared.py') {

                            $include = true;
                            if ($module !== '') {
                                $include=false;
                                if ($module === $entry) {
                                    $include = true;
                                }
                            }
                            if ($include) {
                                $fileName = $folder . '/' . $entry;
                                $metaData = $this->getMetaDataFromFile($fileName);
                                $decoded = json_decode($metaData);

                                $extraVars = $decoded->extradata->values;

                                foreach ($extraVars as &$extraVar) {
                                    $extraVar->source = $decoded->module;
                                }

                                if (isset($decoded->extradata)) {
                                    $variables = (object)array_merge((array)$variables, (array)$extraVars);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $variables;
    }
*/
    public function getVariableList() {
        $showEmpty=trim(filter_input(INPUT_GET, 'showempty', FILTER_SANITIZE_STRING));
        if (empty($showEmpty)) {
            $showEmpty = 'no';
        }
        $module=trim(filter_input(INPUT_GET, 'module', FILTER_SANITIZE_STRING));

		//TODO: remove hard coding
		$params = '--empty';
		if ($showEmpty == 'no') {
			$params = '';
		}

		if ($module !== '') {
			$params .= ' --module ' . $module;
		}
		$pythonScript = '/home/pi/allsky/scripts/modules/allskyvariables/allskyvariables.py --print ' . $params . ' --allskyhome ' . ALLSKY_HOME;

		$output = [];
		$returnValue = 0;
		exec("python3 $pythonScript 2>&1", $output, $returnValue);

		//$string = implode('', $output);

		$jsonString = json_encode($output[0], JSON_UNESCAPED_SLASHES);
		$data = json_encode($jsonString);

		$this->sendResponse($output[0]);
        //return $this->getVariableListInternal($showEmpty, $module);
    }
/*
    public function getVariableListInternal($showEmpty='yes', $module='') {

        $baseVariableListFile = ALLSKY_CONFIG . '/variables.json';
        $coreModuleDir = ALLSKY_SCRIPTS . '/modules';
        $extraModulesDir = '/opt/allsky/modules';
        $extraFiles = ALLSKY_OVERLAY . '/extra';

        $tempVariableList = [];
        if ($module === '') {
            $tempVariableList = json_decode(file_get_contents($baseVariableListFile));
        }

        $debugVariables = $this->getdebugVariables();

        $variables = $this->getModulevariableList($coreModuleDir, $module);
        $tempVariableList = (object)array_merge((array)$tempVariableList, (array)$variables);

        $variables = $this->getModulevariableList($extraModulesDir, $module);
        $tempVariableList = (object)array_merge((array)$tempVariableList, (array)$variables);

        if ($module === '') {        
            $variables = $this->getModulevariableList($extraFiles, null, true);
            $tempVariableList = (object)array_merge((array)$tempVariableList, (array)$variables);
        }

        $tempVariableList = (array)$tempVariableList;
        $variableList = array();

        foreach ($tempVariableList as $variable=>$config) {
            if ($module === '') {           
                $add = true;
                if (str_contains($variable, '${COUNT}')) {
                    $matchString = str_replace('${COUNT}', '', $variable);

                    foreach ($tempVariableList as $tempVariable=>$tempConfig) {
                        $tempVariable = preg_replace('/\d+$/', '', $tempVariable);
                        if ($tempVariable === $matchString) {
                            $add = false;
                        }
                    }
                }
                if ($add) {
                    $variableList[$variable] = $config;
                }
            } else {
                $variableList[$variable] = $config;
            }
        }

        $result = array();
        foreach ($variableList as $variable=>$config) {
            $value = (isset($config->value)) && $config->value ? $config->value : '';

            if ($config->group == 'Allsky' && isset($debugVariables[$variable])) {
                $value = $debugVariables[$variable];
            }

            $add = true;
            if ($showEmpty == 'no' && $module === '') {
                if (empty($value)) {
                    $add = false;
                }
            }

            if ($add) {
                $result[] = [
                    'name' => (isset($config->name)) && $config->name ? $config->name : '${' . str_replace('AS_', '', $variable) . '}',
                    'format' => (isset($config->format)) && $config->format ? $config->format : '',
                    'sample' => (isset($config->sample)) && $config->sample ? $config->sample : '',
                    'variable' => $variable,
                    'group' => (isset($config->group)) && $config->group ? $config->group : 'Unknown',
                    'description' => (isset($config->description)) && $config->description ? $config->description : '',
                    'value' => $value,
                    'type' => (isset($config->type)) && $config->type ? $config->type : 'Unknown',
                    'source' => (isset($config->source)) && $config->source ? $config->source : 'Unknown'
                ];
            }
        }

        $this->sendResponse(json_encode($result));
    }
*/
    public function postValidateMask() {
        $error = false;
        $message='';
        $filename=trim(filter_input(INPUT_POST, 'filename', FILTER_SANITIZE_STRING));
        $validFileTypes = array('png', 'jpg', 'jpeg');
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if (in_array($extension, $validFileTypes)) {

            $settings_array = readSettingsFile();
            $captureImageFilename = ALLSKY_TMP . '/' . getVariableOrDefault($settings_array, 'filename', 'image.jpg');

            $imageThumbnailFolder = ALLSKY_OVERLAY . '/imagethumbnails';
            $filePath = ALLSKY_OVERLAY . '/images/' . $filename;
            $imageInfo = getimagesize($filePath);
            if ($imageInfo) {
                $maskWidth = $imageInfo[0];
                $maskHeight = $imageInfo[1];

                $imageInfo = getimagesize($captureImageFilename);
                if ($imageInfo) {
                    $capturedWidth = $imageInfo[0];
                    $capturedHeight = $imageInfo[1];
                    
                    if (($capturedWidth !== $maskWidth) && ($capturedHeight !== $maskHeight)) {
                        $error = true;
                        $message = "The mask does not match the captured images. Captured image is {$capturedWidth}x{$capturedHeight} but the mask is {$maskWidth}x{$maskHeight}";
                    } else {
                        $message = "The mask matches the captured images. Captured image is {$capturedWidth}x{$capturedHeight} but the mask is {$maskWidth}x{$maskHeight}";

                    }
                } else {
                    $error = false;
                    $message = "Unable to locate a captured image";                    
                }
            } else {
                $error = true;
                $message = "Unable to read the image file - {$filePath}";
            }            

        } else {
            $error = true;
            $message = "The filename {$filename} has an invalid extension";
        }

        $result = [
            'error'=>$error,
            'message'=>$message
        ];

        $this->sendResponse(json_encode($result));        
    }

	private function findModule($module) {
		//TODO: add enabled status to result and warn in module manager if module is not enabled
		$metaData = null;
        $coreModuleDir = ALLSKY_SCRIPTS . '/modules';
        $extraModulesDir = '/opt/allsky/modules';

		$found = false;
		$checkPath = $coreModuleDir . '/' . $module;
		if (file_exists($checkPath)) {
			$found = true;
		} else {
			$checkPath = $extraModulesDir . '/' . $module;
			if (file_exists($checkPath)) {
				$found = true;
			}
		}

		if ($found) {
			$metaData = $this->getMetaDataFromFile($checkPath);
			$metaData = json_decode($metaData);
		}

		return $metaData;
	}

	private function isModuleInAnyFlow($flows, $module) {
		$result = false;

		foreach ($flows as $flowName=>$flowData) {
			foreach ($flowData as $flowModule=>$flowModuleData) {
				if ($flowModule == $module) {
					$result = true;
					break 2;
				}
			}
		}

		return $result;
	}

	public function postCheckModuleDependencies() {
		$results = array();
		$check = $_POST['check'];
		$checkFlow = trim(filter_input(INPUT_POST, 'flow', FILTER_SANITIZE_STRING));

		$flows = array('day', 'night', 'periodic', 'daynight', 'nightday');

		$flowInfo = array();
		foreach($flows as $flow) {
			$flowInfo[$flow] = array();
			if ($flow == $checkFlow) {
				$flowKeys = $check;
			} else {
				$flowFileName = ALLSKY_MODULES . "/postprocessing_$flow.json";
				$flowData = file_get_contents($flowFileName);
				$flowData = json_decode($flowData);
				$flowKeys = array_keys(get_object_vars($flowData));
				foreach($flowKeys as &$flowKey) {
					$flowKey = "allsky_$flowKey.py";
				}
			}

			foreach ($flowKeys as $moduleFile) {
				$moduleMetaData = $this->findModule($moduleFile);
				$flowInfo[$flow][$moduleFile] = $moduleMetaData;
			}
		}

		foreach ($flowInfo as $flowName=>$flowData) {
			foreach ($flowData as $flowModule=>$flowModuleData) {
				if (isset($flowModuleData->dependency)) {
					$result = $this->isModuleInAnyFlow($flowInfo, $flowModuleData->dependency);
					if ($result === false) {
						$moduleMetaData = $this->findModule($flowModuleData->dependency);

						if ($moduleMetaData === null) {
							$message = 'The "' . $flowModuleData->dependency . '" module is required by the "' . $flowModuleData->name . '" module ';
							$message .= "This module is NOT installed. Please install the extra modules.";
						} else {
							$message = 'The "' . $moduleMetaData->name . '" module is required by the "' . $flowModuleData->name . '" module ';
							$message .= "Please add this module to the relevant flow and configure it.";
						}
						$results[$flowModule][$flowName] = $message;
					}
					
				}
			}
		}
		$this->sendResponse(json_encode($results));
	}

	public function getOnewire() {
		$folderPath = '/sys/bus/w1/devices/';
		$files = glob($folderPath . '[0-9a-fA-F]*-*');
				  
		$results = array();
		$results['results'] = array();
		foreach ($files as $file) {
			$results['results'][] = [
				'id' => basename($file),
				'text' => basename($file)
			];
		}
		
		$this->sendResponse(json_encode($results));
	}

	public function postGetExtraDataFile() {
		$extraDataFilename = basename($_POST['extradatafilename']);
		$filePath = $this->extraDataFolder . '/' . $extraDataFilename;
		$result = [];
		if (file_exists($filePath)) {
			$result = file_get_contents($filePath);
		}

		$this->sendResponse(json_encode($result));		
	}
}

$moduleUtil = new MODULEUTIL();
$moduleUtil->run();
