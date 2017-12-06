<?php

///This file is used to receive payloads from a factoring service.  The path should be communicated to the factoring service so that they may post to this file.
//Programmer:  Craig Millis

require_once(__DIR__.'/php/EndpointController.php');

define('PRODUCTION', TRUE);

if(PRODUCTION == TRUE){
  file_put_contents('test.txt', print_r(@$_SERVER['PATH_INFO'], TRUE));
  $endpoint = new EndpointController();

  if(is_string($endpoint->response)){
    echo ($endpoint->response);
  }else{
    echo print_r($endpoint->response, TRUE);
  }
}

?>
