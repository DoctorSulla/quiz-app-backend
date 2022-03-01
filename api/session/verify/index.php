<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type,jwt");
require_once('../../common/index.php');

$method = $_SERVER['REQUEST_METHOD'];

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

$responseObject = new stdClass();

switch($method) {
  case "POST":
  if(!isset($requestObject->jwt)) {
    die(http_response_code(400));
  }
  else {
    $jwt = $requestObject->jwt;
    try {
    if(verify_jwt($jwt,$hmacSecret)) {
      $responseObject->error = false;
      $responseObject->message = "Authorisation token is valid.";
    }
    else {
      $responseObject->error = true;
      $responseObject->message = "Authorisation token is invalid.";
    }
    echo json_encode($responseObject);
  } catch(Exception $e) {
    $responseObject->error = true;
    $responseObject->message = $e->getMessage();
    die(json_encode($responseObject));
  }
}
  break;
  case "OPTIONS":
    http_response_code(200);
  break;
  default:
    die(http_response_code(405));
}
?>
