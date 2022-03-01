<?php
require($_SERVER['DOCUMENT_ROOT'].'/aws/aws-autoloader.php');
require($_SERVER['DOCUMENT_ROOT'].'/common/regexp_patterns.php');
require($_SERVER['DOCUMENT_ROOT'].'/config/index.php');

date_default_timezone_set('UTC');

use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;

$sdk = new Aws\Sdk([
    'endpoint'   => 'https://dynamodb.eu-west-2.amazonaws.com',
    'region'   => $region,
    'version'  => 'latest',
    'credentials' => array(
    'key' => $key,
    'secret'  => $secret
  )
]);

// Import PHP mailer class into Global Namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

set_include_path('C:\Program Files\Apache24\htdocs\vendor');
require ('autoload.php');

$dynamodb = $sdk->createDynamoDb();

$marshaler = new Marshaler();


$charSet = ["A","B","C","D","E","F","G","H","I","J","K","L","M","N","O","P","Q","R","S","T","U","V","W","X","Y","Z","0","1","2","3","4","5","6","7","8","9"];
function generate_id($charSet,$length) {
  $result = "";
  for($i=0;$i<$length;$i++) {
    $result .= $charSet[random_int(0,count($charSet)-1)];
  }
 return $result;
}

class AccessToken {
  function __construct() {
    $this->header = new stdClass();
    $this->header->alg = "HS256";
    $this->header->type = "JWT";
  }

  function set_payload($user) {
    $this->payload = $user;
  }

  function generate_signature($secret) {
    $header = json_encode($this->header);
    $payload = json_encode($this->payload);

    $content = base64_encode($header).".".base64_encode($payload);
    $this->signature = base64_encode(hash_hmac('sha256',$content,$secret,true));
  }

  function generate_jwt() {
    $header = base64_encode(json_encode($this->header));
    $payload = base64_encode(json_encode($this->payload));
    return $header.".".$payload.".".$this->signature;
  }
}

function send_email($to,$toName,$from,$subject,$body) {
  $mail = new PHPMailer;
  $mail->isSMTP();
  $mail->isHTML();
  $mail->Host = 'email-smtp.eu-west-1.amazonaws.com';
  $mail->Port = 587;
  $mail->SMTPAuth = true;
  $mail->SMTPOptions = array(
      'ssl' => array(
      'verify_peer' => false,
      'verify_peer_name' => false,
      'allow_self_signed' => false
    )
  );
  $mail->Username = 'AKIATOSJGMA43P2S5IDT';
  $mail->Password = 'BFkKTi8USYpNz9HZCaa/xTEK0937FG2m8rS0nBwmx3U/';
  $mail->setFrom($from, 'Quiz App');
  $mail->addReplyTo($from, 'Quiz App');
  $mail->addAddress($to,$toName);
  $mail->Subject = $subject;
  $mail->Body = $body;
  $mail->AltBody = '';
  if (!$mail->send()) {
   return true;
  }
  else {
    return false;
  }
}

function get_jwt_claims($jwt) {
  $parts = explode('.',$jwt);
  if(count($parts) !== 3) {
    throw new Exception("Invalid authorisation token.");
  }
  return json_decode(base64_decode($parts[1]));
}

function get_jwt($email,$durationInSeconds,$hmacSecret,$dynamodb,$marshaler) {
  $user = get_user($email,$dynamodb,$marshaler);
  $user->exp= time() + $durationInSeconds;
  unset($user->hashedPassword);
  $accessToken = new AccessToken;
  $accessToken->set_payload($user);
  $accessToken->generate_signature($hmacSecret);
  return $accessToken->generate_jwt();
}

function verify_jwt($jwt,$secret) {
  $parts = explode('.',$jwt);
  if(count($parts) !== 3) {
    throw new Exception("Invalid authorisation token.");
  }
  $content = $parts[0].".".$parts[1];
  $signature = base64_encode(hash_hmac('sha256',$content,$secret,true));
  if($signature === $parts[2]) {
    $payload = json_decode(base64_decode($parts[1]));
    if(time() <= $payload->exp) {
      return true;
    }
  }
  throw new Exception("Invalid authorisation token.");
}

function verify_login($email,$password,$dynamodb,$marshaler) {
  try { $user = get_user($email,$dynamodb,$marshaler); } catch(Exception $e) {
    return false;
  }
  if(password_verify($password,$user->hashedPassword)) {
    return true;
  }
  return false;
}

class RegistrationRequest {
  function __construct($email,$password,$confirmPassword,$username,$userId) {
    if($password !== $confirmPassword) {
      throw new Exception("Passwords must match");
    }
    else if(!preg_match($GLOBALS['usernameRegExp'],$username)) {
      throw new Exception('Username must be between 5 and 15 characters and can only include alphanumeric characters, hyphens and underscores.');
    }
    else if(!preg_match($GLOBALS['passwordRegExp'],$password)) {
      throw new Exception('Password must be between 5 and 50 characters.');
    }
    else if(!preg_match($GLOBALS['emailRegExp'],$email)) {
      throw new Exception('Invalid email address.');
    }
    else {
      check_user_exists($email,$GLOBALS['dynamodb'],$GLOBALS['marshaler']);
    }
    $this->username = $username;
    $this->hashedPassword = password_hash($password,PASSWORD_DEFAULT);
    $this->email = $email;
    $this->verified = false;
    $this->admin = false;
    $this->id = $userId;
    $this->registrationTimestamp = time();
    $this->ownedCategories = [];
  }
}

class GameInstance {
  function __construct($id,$category,$playerOne,$playerOneId,$questions) {
    $this->id = $id;
    $this->category = $category;
    $this->players = [$playerOne];
    $this->ids = [$playerOneId];
    $this->gameStatus = "AwaitingPlayers";
    $this->scores = [0,0];
    $this->activeQuestion = 0;
    $this->timestampP1 = null;
    $this->timestampP2 = null;
    $this->completedP1 = false;
    $this->completedP2 = false;
    $this->questions = $questions;
  }
}

function get_game($gameId,$dynamodb,$marshaler) {
  if (!preg_match('/[A-Z0-9]{6}/',$gameId)) {
    throw new Exception('Game ID  must be exactly 6 characters');
  }
  $key = $marshaler->marshalJson('
      {
        "id": "' .$gameId. '"
      }
  ');

  $params = [
      'TableName' => 'games',
      'Key' => $key
  ];

  try {
      $result = $dynamodb->getItem($params);
      // Check if result is empty, if so the game doesn't exist
      if($result['Item'] == null) {
        throw new Exception('Game ID '.$gameId.' does not exist');
      }
      // Turn result into a PHP object
      $result = $marshaler->unmarshalJson($result['Item']);
      $result = json_decode($result);
      return $result;
  } catch (DynamoDbException $e) {
      return false;
  }
}

function get_user($email,$dynamodb,$marshaler) {
  $key = $marshaler->marshalJson('
      {
        "email": "' .$email. '"
      }
  ');

  $params = [
      'TableName' => 'users',
      'Key' => $key
  ];
      $result = $dynamodb->getItem($params);
      // Check if result is empty, if so the game doesn't exist
      if($result['Item'] == null) {
        throw new Exception('That user does not exist.');
      }
      else {
        $result = $marshaler->unmarshalJson($result['Item']);
        $result = json_decode($result);
        return $result;
      }
}

function get_evc($email,$dynamodb,$marshaler) {
  $key = $marshaler->marshalJson('
      {
        "email": "' .$email. '"
      }
  ');

  $params = [
      'TableName' => 'activation_codes',
      'Key' => $key
  ];
      $result = $dynamodb->getItem($params);
      // Check if result is empty, if so the game doesn't exist
      if($result['Item'] == null) {
        throw new Exception('No verification code found for that email.');
      }
      else {
        $result = $marshaler->unmarshalJson($result['Item']);
        $result = json_decode($result);
        return $result;
      }
}

function check_user_exists($email,$dynamodb,$marshaler) {
  $key = $marshaler->marshalJson('
      {
        "email": "' .$email. '"
      }
  ');

  $params = [
      'TableName' => 'users',
      'Key' => $key
  ];
      $result = $dynamodb->getItem($params);
      // Check if result is empty, if not the user already exists
      if($result['Item'] != null) {
        throw new Exception('That email address is already registered.');
      }
}

function update_game_properties($gameId,$properties,$newValues,$dynamodb,$marshaler) {
  if(count($properties) != count($newValues)) {
    throw new Exception("Properties and new values length mismatch");
  }
  $key = $marshaler->marshalJson('
  {
    "id":"'.$gameId.'"
  }');

  $variables = [":a",":b",":c",":d",":e",":f",":g",":h",":i",":j"];

  $eavJson = '{';
    for($i=0;$i<count($properties);$i++) {
      $eavJson .= '"'.$variables[$i].'":'.json_encode($newValues[$i]);
      if($i+1 < count($properties)) {
        $eavJson .= ',';
      }
    }

  $eavJson .= '}';

  $ue = 'set ';
  for($i=0;$i<count($properties);$i++) {
    $ue .= $properties[$i]. ' = '.$variables[$i];
    if($i+1 < count($properties)) {
      $ue .= ',';
    }
  }

  $eav = $marshaler->marshalJson($eavJson);

  $params = [
      'TableName' => 'games',
      'Key' => $key,
      'UpdateExpression' =>
          $ue,
      'ExpressionAttributeValues'=> $eav,
      'ReturnValues' => 'UPDATED_NEW'
  ];

  try {
      $result = $dynamodb->updateItem($params);
      return true;

    } catch (DynamoDbException $e) {
      throw new Exception("Unable to update game");
  }
}

function update_user($email,$properties,$newValues,$dynamodb,$marshaler) {
  if(count($properties) != count($newValues)) {
    throw new Exception("Properties and new values length mismatch");
  }
  $key = $marshaler->marshalJson('
  {
    "email":"'.$email.'"
  }');

  $variables = [":a",":b",":c",":d",":e",":f",":g",":h",":i",":j"];

  $eavJson = '{';
    for($i=0;$i<count($properties);$i++) {
      $eavJson .= '"'.$variables[$i].'":'.json_encode($newValues[$i]);
      if($i+1 < count($properties)) {
        $eavJson .= ',';
      }
    }

  $eavJson .= '}';

  $ue = 'set ';
  for($i=0;$i<count($properties);$i++) {
    $ue .= $properties[$i]. ' = '.$variables[$i];
    if($i+1 < count($properties)) {
      $ue .= ',';
    }
  }

  $eav = $marshaler->marshalJson($eavJson);

  $params = [
      'TableName' => 'users',
      'Key' => $key,
      'UpdateExpression' =>
          $ue,
      'ExpressionAttributeValues'=> $eav,
      'ReturnValues' => 'UPDATED_NEW'
  ];

  try {
      $result = $dynamodb->updateItem($params);
      return true;

    } catch (DynamoDbException $e) {
      throw new Exception("Unable to update user because ".$e->getMessage());
  }
}

function add_to_table($tableName,$object,$dynamodb,$marshaler) {

  $item = $marshaler->marshalJson(json_encode($object));

  $params = [
      'TableName' => $tableName,
      'Item' => $item
  ];


  try {
      $result = $dynamodb->putItem($params);
      return $result;
  } catch (DynamoDbException $e) {
      throw new Exception('Item could not be added to the database');
  }
}

function calculate_score($oldScore,$startingTimestamp,$bonus) {
  $difference = time() - $startingTimestamp;
  if($difference >= 10) {
    return $oldScore;
  }
  $score = $oldScore + 20 - ($difference * 2);
  if($bonus) {
    $score = $oldScore + 40 - ($difference * 4);
  }
  return $score;
}

function delete_item($table,$index,$value,$dynamodb,$marshaler) {

  $key = $marshaler->marshalJson('
    {
        "'.$index.'": "' . $value . '"
    }');

  $eav = $marshaler->marshalJson('
      {
          ":val": "'.$value.'"
      }
  ');

  $params = [
      'TableName' => $table,
      'Key' => $key,
  ];

  try {
      $result = $dynamodb->deleteItem($params);
      return true;
  } catch (DynamoDbException $e) {
      echo $e->getMessage();
      return false;
  }
}
?>