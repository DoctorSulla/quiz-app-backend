<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
require_once('../common/index.php');

$method = $_SERVER['REQUEST_METHOD'];

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

$responseObject = new stdClass();

switch($method) {
  case "POST":
  if(!isset($requestObject->email) || !isset($requestObject->password)) {
    die(http_response_code(400));
  }
  else {

    try {
      verify_login($requestObject->email,$requestObject->password,$dynamodb,$marshaler);
      $token = get_jwt($requestObject->email,$durationInSeconds,$hmacSecret,$dynamodb,$marshaler);
      $responseObject->jwt = $token;
      echo json_encode($responseObject,JSON_UNESCAPED_SLASHES);
    } catch(Exception $e) {
      http_response_code(401);
      $responseObject->error = true;
      $responseObject->message = $e->getMessage();
      die(json_encode($responseObject));
    }
  }
  break;
  case "DELETE":
    // Code to log user out
  break;
  case "OPTIONS":
    http_response_code(200);
  break;
  default:
    die(http_response_code(405));
}
?>
