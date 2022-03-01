<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, jwt");
require_once('../common/index.php');

$method = $_SERVER['REQUEST_METHOD'];

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

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

$responseObject = new stdClass();

if($user->verified) {
  $responseObject->error = true;
  $responseObject->message = "You've already verified your email.";
  die(json_encode($responseObject));
}

switch($method) {
  case "POST":

    delete_item('activation_codes','email',$user->email,$dynamodb,$marshaler);

    $to = $user->email;
    $from = "registration@quiz-app.co.uk";
    $subject = "Verify your email for QuizApp";
    $verificationCode = generate_id($charSet,8);
    $body = "<p>It is required to verify your email in order to use QuizApp.<p><p>Please visit <a href='https://quiz-app.co.uk/verify/".$verificationCode."'>https://quiz-app.co.uk/verify/".$verificationCode."</a> in order to complete verification. Your code is valid for 2 hours.</p><p>If you did not sign up for QuizApp you can safely ignore this email.</p>";
    $toName = $user->username;

    $activationCode = new stdClass();
    $activationCode->code = $verificationCode;
    $activationCode->email = $to;
    $activationCode->expiry = time() + (3600*120);
    $activationCode->retryCount = 0;

    add_to_table('activation_codes',$activationCode,$dynamodb,$marshaler);
    send_email($to,$toName,$from,$subject,$body);
    $responseObject->error = false;
    $responseObject->message = "Verification email sent for ".$user->email.", please check your spam folder if you are unable to see it in your inbox.";
  break;
  case "OPTIONS":
    http_response_code(200);
  break;
  default:
    die(http_response_code(405));
}
?>
