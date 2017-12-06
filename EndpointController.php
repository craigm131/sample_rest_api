<?php

require_once('/htdocs/common/php/logger/Logger.php');
require_once('/htdocs/common/php/DBRequest/DBDataRequest.php');

class EndpointController{

  public $logDir              = '/cust_data/rest/log/';
  public $configFile          = '/u/home/config/rest';
  public $logDetailSetting    = 3; //The higher the number, the more detail is written to logs
  public $debug               = FALSE; //Set to FALSE in production.  Setting to TRUE will result in the most detail written to logs
  public $alpha               = 2;//Position of first resource in the resource array 

  function __construct(){
    $this->startLog($this->logDir);
    $this->clearLastError();
    $this->readConfig();
    if($this->validateAndReceiveRequest() === FALSE){
      $this->formatResponse();
      return;
    }
    if($this->validateThirdPartyConstraints($this->request) === FALSE){
      $this->formatResponse();
      return;
    }
    $this->processRequest($this->request);
    $this->formatResponse();
  }

  private function startLog($logDir, $createSub = FALSE){ 
    $this->oldMask            = umask(0);
    $this->defaultPerms       = 0775;
    $this->defaultPermsDec    = decoct($this->defaultPerms);
    $this->dateObj            = $this->createTimestamp();
    $this->dateTime           = date_format($this->dateObj, "mdHisu");
    $this->dateTimeLastLog    = $this->dateTime;
    if(!file_exists($logDir)){
      if(mkdir($logDir, 0777, TRUE)){
        $msg = 'Created log directory: '.$logDir;
      }else{
        $msg = 'Failed to create log directory: '.$logDir;
      }
    }
    $this->log = new Logger(array('logDir'=>$logDir, 'addSubDir'=> $createSub, 'logDetailSetting'=>$this->logDetailSetting));
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    if(!empty($msg)){
      $this->log->preLog($msg, 1);
    }
  }

  private function createTimestamp($type = 'object', $format = 'mdHisu'){
    $t = microtime(true);
    $micro = sprintf("%06d",($t - floor($t)) * 1000000);
    $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
    if($type == 'object'){
      return $d;
    }else{
      return date_format($d, $format);
    }
  }
  
  private function clearLastError(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    set_error_handler('var_dump', 0);
    //@$undef_var;
    restore_error_handler();
  }

  private function readConfig(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    
    $str = file_get_contents($this->configFile);
    $this->config = json_decode($str);
    $this->log->preLog("Config: ". print_r($this->config, TRUE));
  }

  private function validateAndReceiveRequest(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    
    $this->log->preLog("_SERVER: ".print_r($_SERVER, TRUE));
    
    $msg = '';
    $this->request = new stdClass();
    
    //Check required parameters
    $requiredParams = array(
      'method'      => 'REQUEST_METHOD',
      'line'        => 'REQUEST_URI',
      'resources'   => 'PATH_INFO',
      'user'        => 'PHP_AUTH_USER',
      'password'    => 'PHP_AUTH_PW',
      'cust'        => 'HTTP_HOME_CUSTOMER',
      'apiVersion'  => 'HTTP_API_VERSION' 
    );
    foreach($requiredParams as $key=>$requiredParam){
      if(empty($_SERVER[$requiredParam])){
        $msg .= "\nFail: missing required parameter: SERVER[".$requiredParam."]";
      }else{
        $msg .= "\nSuccess: found required parameter: SERVER[".$requiredParam."]";
        if($key == 'line' || $key == 'resources'){
          $this->request->{$key} = rtrim($_SERVER[$requiredParam], '/');
        }else{
          $this->request->{$key} = $_SERVER[$requiredParam];
        }
      }
    }

    //Check API version
    $allowedApiVersions = array(1, "'1'");
    if(!empty($_SERVER['HTTP_API_VERSION'])){
      if(!in_array($this->request->apiVersion, $allowedApiVersions)){
        $msg .= "Fail: API version not recognized: ".print_r($this->request->apiVersion, TRUE);
        $msg .= "\nOnly these versions are allowed: ".print_r($allowedApiVersions, TRUE);
      }
    }else{
      $this->request->apiVersion = 1;
    }

    //Check username and password
    if(!empty($_SERVER['PHP_AUTH_USER']) AND !empty($_SERVER['PHP_AUTH_PW'])){
      $msg .= $this->validateUserAndPassword($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    //Stop here if any problems with username, password, request
    $this->log->preLog($msg, 1);
    if(stripos($msg, 'fail') == TRUE){
      $this->addError($msg);
      $this->log->preLog('Stopping due to missing required parameter');
      return FALSE;
    }
    
    //Derive action and cust from line
    $this->request->resourceArr = explode('/', $this->request->resources);
    $i = 0;
    $action = strtolower($this->request->method);
    foreach($this->request->resourceArr as $resource){
      if($i >=1 AND $i % 2 !== 0){
        $action .= ucfirst($this->request->resourceArr[$i]);
      }
      $i++;
    }
    $this->request->action  = $action;
    $arr = $this->receiveRequestPayload();
    if(isset($arr['record']) AND !isset($arr['record'][0])){
      $this->request->payload = array('record' => array($arr['record']));
    }else{
      $this->request->payload = $arr;
    }

    $this->log->preLog("Received request: ".print_r($this->request, TRUE), 3);
    return TRUE;
  }

  private function validateUserAndPassword($username, $password){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $msg = '';
    if(isset($this->config->third_parties->{$username})){
      $msg = "\nSuccess: username recognized: ".$username;  
      if($password == $this->config->third_parties->{$username}->password){
        $msg .= "\nSuccess: password recognized"; 
        $this->config = $this->config->third_parties->{$username}; 
      }else{
        $msg .= "\nFail: password not recognized: ".$password;  
      }
    }else{
      $msg .= "\nFail: username not recognized: ".$username;  
    }

    return $msg;
  }

  private function startApiLog($filenameFormat){ 
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    //Start log in directory that relates to the api, e.g. /cust_data/cargo/edi/factor_service/log/ap/ instead of /cust_data/rest/
    $logDir = $this->getLogDir($filenameFormat);
    $this->startLog($logDir, TRUE);
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    $this->log->preLog("Request: ".print_r($this->request, TRUE), 1);
  }

  private function getLogDir($filenameFormat){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $dir      = '/cust_data/'.$this->request->cust.'/edi/'.$this->request->user;
    $fileArr  = explode("_", $filenameFormat);
    $logDir   = $dir.'/log/'.$fileArr[0].'/';
    $this->log->preLog("Log directory: ".$logDir);
    return $logDir;
  }

  private function receiveRequestPayload(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    //Read the stream into the payload variable
    $payload = file_get_contents("php://input");

    if(empty($payload)){
      $msg = "No payload provided";
      $this->log->preLog($msg, 1);
      return;
    }else{
      $this->log->preLog("Received payload: \n".print_r($payload, TRUE), 3);
    }

    try{
      $obj  = new SimpleXMLElement($payload);
    }catch(Exception $e){
      $msg = 'String could not be parsed as XML';
      $msg .= "\nString: ".print_r($payload, TRUE);
      $this->log->preLog($msg, 1);
      $this->addError($msg);
      return $msg;
    }

    $json = $this->xmlToArray($obj);
    $this->log->preLog("Payload object: ".print_r($json, TRUE), 5);
    if(!isset($json['payload'])){
      $msg = "Missing 'payload' object";
      $msg .= "\nXML must be enclosed by a payload tag:";
      $msg .= "\n<payload>";
      $msg .= "\n</payload>";
      
      $this->log->preLog($msg, 1);
      $this->addError($msg);
      return;
    }
    return $json['payload'];
  }

  private function validateThirdPartyConstraints($request){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    
    $this->log->preLog("request: ".print_r($request, TRUE), 3);

    //Confirm third-party's access to custs.  If their custs array is empty in the config, then they have access to all custs.
    if(!empty($this->config->custs)){
      if(in_array($request->cust, $this->config->custs) === FALSE){
        $msg = "Fail: ".$request->user." does not have access to ".$request->cust;
        $this->log->preLog($msg, 1);
        $this->addError($msg);
        return FALSE;
      }
    }
    $this->log->preLog("Success: ".$request->user." has access to ".$request->cust, 3);

    //Check methods
    if(in_array($request->method, $this->config->permissions) === FALSE){
      $msg = "Fail: ".$request->user." is not allowed to use this method: ".$request->method;
      $this->log->preLog($msg, 1);
      $this->addError($msg);
      return FALSE;
    }
    $this->log->preLog("Success: ".$request->user." is allowed to use this method: ".$request->method, 3);

    return TRUE;
  }

  private function processRequest(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    try {
      $this->{$this->request->action}($this->request->resourceArr);
    } catch(Exception $e) {
      $msg = "\nFail: action not recognized";
      $msg .= "\n".$e->getMessage();
      $this->log->preLog($msg, 1);
      $this->addError($msg);
      return;
    }
  }

  private function createCsv($path, $params){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $this->log->preLog("Attempting to write to ". $path.": ".print_r($params, TRUE), 3);
    $handle   = fopen($path, 'w');
    $result   = fputcsv($handle, $params);
    fclose($handle);

    $this->log->preLog("Result of fputcsv: ".print_r($result, TRUE), 3);
    if($result === FALSE){
      $this->log->preLog("Fail: cannot create file ".$path, 1);
    }
    return $result;
  }

  private function postTransactions($resourceArr){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    
    $records          = $this->request->payload['record'];
    $transactionType  = strtolower($resourceArr[$this->alpha]);
    switch($transactionType){
      case 'payment':
        $filenameFormat = 'ap_imp_.csv';
      
        //Fields in the csv and a note about the field
        $fields = array(
          'amount'        => 'required.  Must be a number.',
          'check_date'    => 'required and in mm/dd/yy format',
          'check_number'  => 'max 12 characters',
          'gl_code'       => 'must provide gl_code if qopay # is not provided',
          'ap_number'  => '',
          'invoice_date'  => 'mm/dd/yy format',
          'id'           => 'required',
          'vendor_id'     => 'required',
          'filler'        => 'x'
        );
        $fieldOrder = array('id', 'vendor_id', 'amount', 'check_number', 'check_date', 'gl_code', 'invoice_date', 'ap_number', 'filler');
        break;
      case 'receipt':
        $filenameFormat = 'ar_imp_.csv';

        //Fields in the csv and a note about the field
        $fields = array(
          'amount'        => 'required.  Must be a number.',
          'credit_debit'  => "'c' for credit, 'd' for debit; leave blank if payment received",
          'check_date'    => 'must be in this format mm/dd/yy',
          'check_number'  => 'max 12 characters',
          'comments'      => 'max 40 characters',
          'date'          => 'must be in this format mm/dd/yy',
          'gl_code'       => 'Must match GL already in Home and not the default AR GL',
          'filler'        => 'x',
          'id'           => 'required'
        );
        $fieldOrder = array('id', 'credit_debit', 'amount', 'date', 'check_number', 'check_date', 'comments', 'gl_code', 'filler');
        break;
    }

    $this->startApiLog($filenameFormat);

    $path   = $this->createPath($filenameFormat);
    $handle = fopen($path, 'w');

    foreach($records as $recordNo=>$record){
      $proceed = TRUE;
      $this->log->preLog("Processing this record, key=".$recordNo.": ".print_r($record, TRUE));
      foreach($fields as $field=>$note){
        $msg = NULL;
        //Check for required fields
        if(stripos($note, 'required') !== FALSE){
          if(in_array($field, array_keys($record)) === FALSE || empty($record[$field])){
            $msg = 'Required parameter missing or blank: record '.$recordNo.', field '.$field;
            $this->log->preLog($msg."\n".print_r($record, TRUE));
            $this->addError($msg);
            $proceed = FALSE;
            continue;
          }
        }
        if(!isset($record[$field])){
          $this->log->preLog('record['.$recordNo.']['.$field.'] is not set', 5);
          continue;
        }
        if($record[$field] == array()){
          $this->log->preLog('record['.$recordNo.']['.$field.'] is an empty array.  Changing to empty string', 5);
          $record[$field] = '';
          continue;
        }
        $function = 'check'.ucfirst($transactionType).'FieldRestrictions';
        $msg = $this->checkTransactionFieldRestrictions($transactionType, $field, $note, $record);

        if($msg !== NULL){
          $this->addError('Record '.$recordNo.': '.$msg);
          $proceed = FALSE;
        }
      }

      //Reviewed each field, now write record if proceed = TRUE 
      if($proceed === FALSE){
        $this->addMessage(array('Fail'=>$record));
        continue;
      }else{
        $this->addMessage(array('Success'=>$record));
        $orderedRecord = array();
        foreach($fieldOrder as $value){
          if(isset($record[$value])){
            $orderedRecord[$value] = $record[$value];
          }elseif($value = 'filler'){
            $orderedRecord[$value] = 'x';
          }else{
            $orderedRecord[$value] = '';
          }
        }
        $result = fputcsv($handle, $orderedRecord);
      }
    }
    fclose($handle);
    $this->makeCopies($path);
  }

  private function checkTransactionFieldRestrictions($transaction, $field, $note, $record){
    $this->log->preLog(__CLASS__." ".__METHOD__, 5);

    $err = FALSE;
    if($transaction == 'payment'){
      switch($field){
      case 'amount':
        if(!is_numeric($record[$field])){
          $err = TRUE;
        }
        break;
      case 'check_number':
        if(strlen($record[$field]) > 12){
          $err = TRUE; 
        }
        break;
      case 'check_date':
        if($this->validDate($record[$field]) === FALSE){ 
          $err = TRUE; 
        }
        break;
      case 'invoice_date':
        if($this->validDate($record[$field]) === FALSE){ 
          $err = TRUE; 
        }
        break;
      default;
      }
    }elseif($transaction == 'receipt'){
      switch($field){
      case 'amount':
        if(!is_numeric($record[$field])){
          $err = TRUE; 
        }
        break;
      case 'credit_debit':
        if(in_array($record[$field], array('c','d','C','D', '')) === FALSE){
          $err = TRUE; 
        }
        break;
      case 'date':
        if($this->validDate($record[$field]) === FALSE){ 
          $err = TRUE; 
        }
        break;
      case 'check_number':
        if(strlen($record[$field]) > 12){ 
        }
        break;
      case 'check_date':
        if($this->validDate($record[$field]) === FALSE){ 
          $err = TRUE; 
        }
        break;
      case 'comments':
        if(strlen($record[$field]) > 40){ 
          $err = TRUE; 
        }
        break;
        default;
      }
    }
    $msg = NULL;
    if($err === TRUE){
      $msg = $field.': '.$note; 
    }
    return $msg;
  }

  private function putTransactionsStatuses($resourceArr){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $records          = $this->request->payload['record'];
    $transactionType  = strtolower($resourceArr[$this->alpha]);
    $status           = strtolower($resourceArr[$this->alpha+2]);
    switch($transactionType){
      case 'payment':
        $filenameFormat = 'ap_imp_.csv';
        $this->startApiLog($filenameFormat);
        $fields = array(
          'ap_number'  => 'required'
        );
        $queryParams = array(
          'fileName'  => 'ap',
          'index'     => 'P',
          'noauth'    => 'Y',
          'nocust'    => 'N',
          'realCust'  => $this->request->cust,
          'exact'     => 'Y',
          'limit'     => 10
        );
        $fieldMap = array(
          'Xpay Invoice'  => 16,
          'Notes'         => 10,
          'ACH Id #'   => 30
        );
        $data = array(
          'Notes'         => 'Processing by '.$this->request->user.' '.$this->createTimestamp('string', 'mdy')
        );
        if($status == 'reject'){
          $data['Xpay Invoice'] = 'N';
          $data['ACH Id #']  = '';
          //@TODO email Home customer
        }
        $this->db = new DBDataRequest();
        foreach($records as $recordNo=>$record){
          //check field restrictions
          if(empty($record['ap_number'])){
            $msg = 'Record '.$recordNo.': ap_number required';
            $this->addError($msg);
            $this->log->preLog($msg, 1);
            continue;
          }
          
          //process request
          $queryParams['key'] = $record['ap_number'];
          $this->updateDb($queryParams, $fieldMap, $data);
        }
        break;
      case 'receipt':
        $filenameFormat = 'ar_imp_.csv';
        $this->startApiLog($filenameFormat);
        $fields = array(
          'id'       => 'required'
        );
        $queryParams = array(
          'fileName'  => 'accounts_receivable',
          'index'     => 'C',
          'noauth'    => 'Y',
          'nocust'    => 'N',
          'realCust'  => $this->request->cust,
          'exact'     => 'Y',
          'limit'     => 10 
        );
        $fieldMap = array('Notes' => 701);
        $data = array(
          'Notes' => $this->request->user.' '.$status.$this->createTimestamp('string', 'mdy')
        );
        if($status == 'reject'){
          //@TODO email Home customer
        }
        $this->db = new DBDataRequest();

        foreach($records as $record){
          $this->log->preLog("Processing this record: ".print_r($record, TRUE));
          
          //check field restrictions
          if(empty($record['id'])){
            $msg = 'Record '.$recordNo.': id required';
            $this->addError($msg);
            $this->log->preLog($msg, 1);
            continue;
          }
          
          //process request
          $queryParams['key'] = $record['id'];
          $this->updateDb($queryParams, $fieldMap, $data);
        }
        break;
    }
  }

  private function updateDb($queryParams, $fieldMap, $data){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
      
    try{
      $result = $this->db->getRequestResult($queryParams, $fieldMap);
    }catch(exception $e){
      $this->log->preLog("Fail: can't find record : ".print_r($queryParams, TRUE));
    }
    $this->log->preLog("getRequestResult: ".print_r($result, TRUE));
    
    try{
      $result = $this->db->doUpdate($data);
    }catch(exception $e){
      $this->log->preLog("Fail: can't update record : ".print_r($data, TRUE));
    }
    $this->log->preLog("doUpdate result: ".print_r($result, TRUE));
    return $result;
  }

  private function getLoadsFoldersDocuments($resourceArr){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $docList  = $this->getDocumentList($resourceArr[$this->alpha], TRUE);
    
    //Determine if the request is for a specific document or list of docs
    $totalElements = count($resourceArr);

    if(count($resourceArr) > ($this->alpha+4)){
      //searching for specific document
      //Expecting HTTP line like:  home_customers/[cust]/loads/[id]/folders/[folder]/documents/[document_id]
      //where $resourceArr[6] = [folder]
      foreach($docList as $folderKey=>$folder){
        foreach($folder as $docNumber=>$doc){
          //$this->log->preLog('doc[document_id]: '.$doc['document_id']);
          //$this->log->preLog('resourceArr     : '.$resourceArr[$this->alpha+4]);
          if($doc['document_id'] == $resourceArr[$this->alpha+4]){
            $filepath = $docList[$folderKey][$docNumber]['path'];
            break;
          }
        }
      }
      
      if(empty($filepath)){
        $this->log->preLog("Fail: document_id is not found in document list: ".$resourceArr[$this->alpha+4], 1);
      }
      if(file_exists($filepath)){
        $result = readfile($filepath);
        $this->log->preLog("Result of readfile: ".print_r($result, TRUE));
      }else{
        $result = readfile('');
        $msg = "Fail: file does not exist ".$filepath;
        $this->log->preLog($msg, 1);
        $this->addError($msg);
        return;
      }
    }else{
      //searching for list of documents
      $docList = $this->getDocumentList($resourceArr[$this->alpha]);
      $this->addMessage($docList);
      return;
    } 
  }

  private function getDocumentList($load, $getPath = FALSE){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $arr        = str_split($load, 2);
    $pop        = array_pop($arr);
    $idDir     = implode("/", $arr);
    $dirsResult = $this->getValidDirectories($this->request->cust);  //e.g. invoice_private, invoice_bol, etc.
    $dirs       = $dirsResult[0]; 
    $docList    = array();
    unset($dirs['rn']);
    
    foreach($dirs as $dir){
      //Truncate directory, e.g. show 'private' vs 'invoice_private'
      if(empty($dir)){
        continue;
      }
      $arr = explode('_', $dir);
      if(empty($arr[1])){
        $dirDisplay = $dir;
      }else{
        $dirDisplay = $arr[1];
      }

      $parentDir  = '/cust_data/'.$this->request->cust.'/'.$dir;
      
      //Get subdirectories that don't start with a number, e.g.
      //  /cust_data/cargo/invoice_test/.ofn
      //  /cust_data/cargo/invoice_test/.thm  exclude this per Ron
      //  /cust_data/cargo/invoice_test/thumb
      $subDirs        = glob($parentDir.'/[!0-9]*', GLOB_ONLYDIR); 
      $subDirs        = array_merge($subDirs, glob($parentDir.'/.[a-z]*', GLOB_ONLYDIR));
      $excludeSubDirs = array('.thm');
      $this->log->preLog("subDirs: ".print_r($subDirs, TRUE), 5);
      $this->log->preLog("excluding subDirs: ".print_r($excludeSubDirs, TRUE), 5);
      
      $i = 0;
      foreach($subDirs as $subDir){
        if(in_array($subDir, $excludeSubDirs)){
          continue;
        }
        //Glob for files in the id's directory
        $globParam  = $subDir.'/'.$idDir.'/'.$pop.'-*';
        $fileArr    = glob($globParam);
        $this->log->preLog("Result of globbing for files that match ".$globParam.": ".print_r($fileArr, TRUE), 5);
        if(!empty($fileArr)){
          foreach($fileArr as $file){
            if(!is_file($file)){
              continue;
            }
            $i++;
            if(basename($subDir) == '.ofn'){
              $docList[$dirDisplay][$i]['name'] = file_get_contents($file); 
              $docList[$dirDisplay][$i]['path'] = dirname($subDir).'/'.$idDir.'/'.basename($file);
            }else{
              $docList[$dirDisplay][$i]['name'] = basename($file); 
              $docList[$dirDisplay][$i]['path'] = $file;
            }
            $docList[$dirDisplay][$i]['document_id']  = $this->encodePath($docList[$dirDisplay][$i]['path']);
            $docList[$dirDisplay][$i]['modtime']      = date('YmdHis', filemtime($file));
            if($getPath == FALSE){
              unset($docList[$dirDisplay][$i]['path']);
            } 
          }
        }
      }
    }  
    $docList = $this->sortDocList($docList);
    $this->log->prelog("document list: ".print_r($docList, true), 3);
    return $docList;
  }

  private function sortDocList($docList){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $this->log->prelog("document list: ".print_r($docList, true), 5);
    
    if(empty($docList)){
      return array();
    }
    $sortedList = array();
    foreach($docList as $key=>$folder){
      //obtain a list of modtimes
      $modtime = array();
      foreach($folder as $docNumber=>$doc){
        $modtime[$docNumber] = $doc['modtime'];
      }
      array_multisort($modtime, SORT_DESC, SORT_NUMERIC, $folder);
      $sortedList[$key] = $folder;
    }
    return $sortedList;
  }

  private function encodePath($path){
    $this->log->preLog(__CLASS__." ".__METHOD__, 5);

    $encodedPath = base64_encode($path);
    return $encodedPath;
  }

  //Get the list of folders within /cust_data/[cust]/ that contain valid documents, viewable by the cust
  private function getValidDirectories($cust){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $this->db = new DBDataRequest();
    
    $params = array(
      'fileName'  => 'dbl_menu',
      'index'     => 'A',
      'key'       => 'invoice_dtyp',
      'noauth'    => 'Y',
      'nocust'    => 'N',
      'realCust'  => $cust,
      'exact'     => 'Y',
      'limit'     => 100 
    );
    
    $fieldMap = array();
    for($i = 70; $i <= 99; $i++){
      $fieldMap[$i] = $i;
    }
    $result = array(0=>array());
    try{
      $result = $this->db->getRequestResult($params, $fieldMap);
    }catch(exception $e){
    }
    $this->log->preLog("result: ".print_r($result, TRUE));
    return $result;
  }

  private function createPath($filenameFormat){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    
    $timeString = date_format($this->createTimestamp(), "mdHisu");
    $uniqueString = $timeString.str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT); 
    $path = $this->log->logDir.pathinfo($filenameFormat, PATHINFO_FILENAME).$uniqueString.'.'.pathinfo($filenameFormat, PATHINFO_EXTENSION);
    $this->log->preLog("Create path ".$path, 3);
    return $path;
  }

  private function makeCopies($sourcePath){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    $subDirArr = explode('_', basename($sourcePath));
    $subDir = $subDirArr[0];

    $targetDir  = '/cust_data/'.$this->request->cust.'/edi/'.$this->request->user.'/';
    $this->copyCsv($sourcePath, $targetDir); 
  }

  private function copyCsv($sourcePath, $targetDir){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    if(!file_exists($targetDir)){
      $this->log->preLog($targetDir." does not exist", 1);
      $result = mkdir($targetDir, 0777, TRUE);
      if($result === FALSE){
        $msg = "Fail: cannot create directory ".$targetDir;
        $this->log->preLog($msg, 1);
        $this->addError($msg);
        return;
      }else{
        $this->log->preLog("Created directory ".$targetDir);
      }
    }  
    $targetPath = $targetDir.basename($sourcePath);
    $result = copy($sourcePath, $targetPath);
    if($result === FALSE){
      $msg = "Fail: cannot copy ".$sourcePath." to ".$targetPath;
      $this->log->preLog($msg, 1);
      $this->addError($msg);
      return;
    }
    $msg = "Copied ".$sourcePath." to ".$targetPath;
    $this->log->preLog($msg, 3);
    $this->addMessage($msg);//@TODO delete in production?
  }

  private function addError($msg){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    $this->response['error'][] = $msg;
  }

  private function addMessage($msg){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    $this->response['message'][] = $msg;
  }

  private function validDate($date){
    //The if statement accommodates cases where the date field is being updated to ''.
    if(!empty($date)){
      $d = DateTime::createFromFormat('m/d/y', $date);
      $this->log->preLog("date: ".$date, 5);
      $this->log->preLog("d: ".print_r($d, TRUE), 5);
      return $d && $d->format('m/d/y') === $date;
    }
  }

  private function formatResponse(){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);

    if(!empty($this->response['error'])){
      $code = 400;
      $result = http_response_code($code);
      $this->log->preLog("Http response code set to ".$code, 3);
    }
    if(isset($_SERVER['HTTP_ACCEPT'])){
      switch($_SERVER['HTTP_ACCEPT']){
      case 'text/xml':
        $this->response = $this->arrayToXml($this->response);
        break;
      case 'text/html':
        $this->response = print_r($this->response, TRUE);
        break;
      default:
        $this->response = json_encode($this->response, JSON_FORCE_OBJECT);
      }
    }else{
      $this->response = json_encode($this->response, JSON_FORCE_OBJECT);
    }
    $this->log->preLog("Response: ".print_r($this->response, TRUE));
  }

  private function arrayToXml($data){
    $this->log->preLog(__CLASS__." ".__METHOD__, 3);
    $xml = new SimpleXMLElement('<?xml version="1.0"?><data></data>');

    $this->arrayToXmlProcessor($data, $xml);
    
    return $xml->asXML();
  }
  
  private function arrayToXmlProcessor($data, &$xml){
    $this->log->preLog(__CLASS__." ".__METHOD__, 5);

    foreach( $data as $key => $value ) {
      if( is_array($value) ) {
        if( is_numeric($key) ){
          $key = 'item'.$key; //dealing with <0/>..<n/> issues
        }
        $subnode = $xml->addChild($key);
        $this->arrayToXmlProcessor($value, $subnode);
      } else {
        $xml->addChild("$key", htmlspecialchars("$value"));
      }
    }
  }

  private function xmlToArray($xml, $options = array()) {
    $this->log->preLog(__CLASS__." ".__METHOD__, 5);
    $defaults = array(
      'namespaceSeparator'  => ':',       //you may want this to be something other than a colon
      'attributePrefix'     => '@',       //to distinguish between attributes and nodes with the same name
      'alwaysArray'         => array(),   //array of xml tag names which should always become arrays
      'autoArray'           => true,      //only create arrays for tags which appear more than once
      'textContent'         => '$',       //key used for the text content of elements
      'autoText'            => true,      //skip textContent key if node has no attributes or child nodes
      'keySearch'           => false,     //optional search and replace on tag and attribute names
      'keyReplace'          => false      //replace values for above search values (as passed to str_replace())
    );
    $options = array_merge($defaults, $options);
    $namespaces = $xml->getDocNamespaces();
    $namespaces[''] = null; //add base (empty) namespace

    //get attributes from all namespaces
    $attributesArray = array();
    foreach ($namespaces as $prefix => $namespace) {
      foreach ($xml->attributes($namespace) as $attributeName => $attribute) {
        //replace characters in attribute name
        if ($options['keySearch']) $attributeName = str_replace($options['keySearch'], $options['keyReplace'], $attributeName);
        $attributeKey = $options['attributePrefix'] . ($prefix ? $prefix . $options['namespaceSeparator'] : '') . $attributeName;
        $attributesArray[$attributeKey] = (string)$attribute;
      }
    }

    //get child nodes from all namespaces
    $tagsArray = array();
    foreach ($namespaces as $prefix => $namespace) {
      foreach ($xml->children($namespace) as $childXml) {
        //recurse into child nodes
        $childArray = $this->xmlToArray($childXml, $options);
        list($childTagName, $childProperties) = each($childArray);

        //replace characters in tag name
        if ($options['keySearch']) $childTagName = str_replace($options['keySearch'], $options['keyReplace'], $childTagName);
        
        //add namespace prefix, if any
        if ($prefix) $childTagName = $prefix . $options['namespaceSeparator'] . $childTagName;

        if (!isset($tagsArray[$childTagName])) {
          //only entry with this key
          //test if tags of this type should always be arrays, no matter the element count
          $tagsArray[$childTagName] = in_array($childTagName, $options['alwaysArray']) || !$options['autoArray'] ? array($childProperties) : $childProperties;
        } elseif (is_array($tagsArray[$childTagName]) && array_keys($tagsArray[$childTagName]) === range(0, count($tagsArray[$childTagName]) - 1)) {
            
            //key already exists and is integer indexed array
            $tagsArray[$childTagName][] = $childProperties;
        } else {
            //key exists so convert to integer indexed array with previous value in position 0
            $tagsArray[$childTagName] = array($tagsArray[$childTagName], $childProperties);
        }
      }
    }

    //get text content of node
    $textContentArray = array();
    $plainText = trim((string)$xml);
    if ($plainText !== '') $textContentArray[$options['textContent']] = $plainText;

    //stick it all together
    $propertiesArray = !$options['autoText'] || $attributesArray || $tagsArray || ($plainText === '')
      ? array_merge($attributesArray, $tagsArray, $textContentArray) : $plainText;
      
    //return node as array
    return array($xml->getName() => $propertiesArray);
  }
}

?>
