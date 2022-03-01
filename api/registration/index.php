<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, jwt");
require_once('../common/index.php');

$method = $_SERVER['REQUEST_METHOD'];

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

$responseObject = new stdClass();

switch($method) {
  case "POST":
  if(!isset($requestObject->username) || !isset($requestObject->password) || !isset($requestObject->confirmPassword) || !isset($requestObject->email)) {
    die(http_response_code(400));
  }
  else {
    try {
    $userId = generate_id($charSet,8);
    $registrationObject = new RegistrationRequest($requestObject->email,$requestObject->password,$requestObject->confirmPassword,$requestObject->username,$userId); } catch (Exception $e) {
      http_response_code(400);
      $responseObject->error = true;
      $responseObject->message = $e->getMessage();
      die(json_encode($responseObject));
    }

    // Write to database
    add_to_table('users',$registrationObject,$dynamodb,$marshaler);

    $to = $requestObject->email;
    $from = "registration@quiz-app.co.uk";
    $subject = "Verify your email for QuizApp";
    $verificationCode = generate_id($charSet,8);
    $body = "<p>It is required to verify your email in order to use QuizApp.<p><p>Please visit <a href='https://quiz-app.co.uk/verify/".$verificationCode."'>https://quiz-app.co.uk/verify/".$verificationCode."</a> in order to complete verification. Your code is valid for 2 hours.</p><p>If you did not sign up for QuizApp you can safely ignore this email.</p>";
    $toName = $requestObject->username;

    $activationCode = new stdClass();
    $activationCode->code = $verificationCode;
    $activationCode->email = $to;
    $activationCode->expiry = time() + (3600*120);
    $activationCode->retryCount = 0;

    add_to_table('activation_codes',$activationCode,$dynamodb,$marshaler);
    send_email($to,$toName,$from,$subject,$body);

    $token = get_jwt($registrationObject->email,$durationInSeconds,$hmacSecret,$dynamodb,$marshaler);
    $responseObject->jwt = $token;
    echo json_encode($responseObject,JSON_UNESCAPED_SLASHES);
  }
  break;
  case "PATCH":
  // API authentication
  $headers = getallheaders();

  if(!isset($headers['jwt'])) {
    die(http_response_code(401));
  }

  $jwt = $headers['jwt'];

  try { verify_jwt($jwt,$hmacSecret); } catch(Exception $e) {
    die(http_response_code(401));
  }

  $user = get_jwt_claims($jwt);
  // End API authentication

  // Check if evc is present on request
  if(!isset($requestObject->evc)) {
    http_response_code(400);
    $responseObject->error = true;
    $responseObject->message = "Request missing mandatory parameters.";
    die(json_encode($responseObject));
  }

  // Check if user's email is already verified
  if($user->verified) {
    $responseObject->error = true;
    $responseObject->message = "Your email address is already verified.";
    die(json_encode($responseObject));
  }

  // See if there's a valid verification code
  try {
    $evc = get_evc($user->email,$dynamodb,$marshaler);
  } catch(Exception $e) {
    $responseObject->error = true;
    $responseObject->message = "No verification code could be found for that email.";
    die(json_encode($responseObject));
  }

  if($evc->code == $requestObject->evc) {
    // Check if verification code has expired
    if(time() > $evc->expiry ) {
      $responseObject->error = true;
      $responseObject->message = "Verification code has expired.";
      die(json_encode($responseObject));
    }
    // Update user to verified
    update_user($user->email,['verified'],[true],$dynamodb,$marshaler);
    // Delete the code
    delete_item('activation_codes','email',$user->email,$dynamodb,$marshaler);
    // Provide an updated JWT
    $jwt = get_jwt($user->email,$durationInSeconds,$hmacSecret,$dynamodb,$marshaler);
    $responseObject->error = false;
    $responseObject->jwt = $jwt;
    echo json_encode($responseObject,JSON_UNESCAPED_SLASHES);
  }
  else {
    $responseObject->error = true;
    $responseObject->message = "Invalid verification code.";
    die(json_encode($responseObject));
  }

  case "OPTIONS":
    http_response_code(200);
  break;
  default:
    die(http_response_code(405));
}
?>
