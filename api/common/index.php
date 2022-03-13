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

// Miscellaneous Functions

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
      throw new Exception('Item could not be added to the database.');
  }
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

function generate_id($charSet,$length) {
  $result = "";
  for($i=0;$i<$length;$i++) {
    $result .= $charSet[random_int(0,count($charSet)-1)];
  }
 return $result;
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

// Auth & Login Functions

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

  if(!preg_match($GLOBALS['emailRegExp'],$email)) {
    throw new Exception("That user does not exist.");
  }
  $user = get_user($email,$dynamodb,$marshaler);

  if(isset($user->retryCount) && $user->retryCount > $GLOBALS['maxPasswordRetries']) {
    throw new Exception("Too many attempts - account has been locked.");
  }
  if(password_verify($password,$user->hashedPassword)) {
    reset_retry_count($user,$dynamodb,$marshaler);
    return true;
  }
  increment_retry_count($user,$dynamodb,$marshaler);
    throw new Exception("Invalid password.");
}

function reset_retry_count($user,$dynamodb,$marshaler) {
  update_user($user->email,['retryCount'],[0],$dynamodb,$marshaler);
}

function increment_retry_count($user,$dynamodb,$marshaler) {
  if(isset($user->retryCount)) {
    $retryCount = $user->retryCount + 1;
  }
  else {
    $retryCount = 1;
  }
  update_user($user->email,['retryCount'],[$retryCount],$dynamodb,$marshaler);
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
    $this->likedCategories = [];
    $this->wins = 0;
    $this->losses = 0;
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
      throw new Exception("Unable to update user.");
  }
}

// Gameplay related functions

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

function calculate_score($oldScore,$startingTimestamp,$bonus) {
  // Give an extra 2 seconds to account for the delay between the question an answers being shown
  $difference = time() - ($startingTimestamp + 2);

  // The score can't be better than 20 or 40 (bonus question)
  if($difference <= 0 && $bonus) {
    return $oldScore + 40;
  }
  else if($difference <=0 && ! $bonus) {
    return $oldScore + 20;
  }

  // If they difference is greater than 10 they score 0
  if($difference >= 10) {
    return $oldScore;
  }

  // Calculate the old score + the max score minus any deduction
  $score = $oldScore + 20 - ($difference * 2);
  if($bonus) {
    $score = $oldScore + 40 - ($difference * 4);
  }
  return $score;
}

// Create Category Related Functions

function check_category_exists($category,$dynamodb,$marshaler) {
  $key = $marshaler->marshalJson('
      {
        "category": "' .$category. '"
      }
  ');

  $params = [
      'TableName' => 'categories',
      'Key' => $key
  ];
      $result = $dynamodb->getItem($params);
      // Check if result is empty, if not the user already exists
      if($result['Item'] != null) {
        throw new Exception('A category with the name '.$category.' already exists, please choose a different name.');
      }
}

function validate_category($category,$dynamodb,$marshaler) {
  // Check category is present
  if(!isset($category->category)) {
    throw new Exception('Category name missing.');
  }
  // Check if category already exists
  check_category_exists($category->category,$dynamodb,$marshaler);
  // Check regex for category name
  if(!preg_match($GLOBALS['categoryRegExp'],$category->category)) {
    throw new Exception('Invalid category name.');
  }
  // Check questions are present
  if(!isset($category->questions)) {
    throw new Exception('Questions missing.');
  }
  // Check there are at least 10 questions
  if(count($category->questions) < 10) {
    throw new Exception('At least 10 questions are required for a new category.');
  }
  // Iterate through questions and check the questions and answers all conform to the regex
  foreach($category->questions as $question) {
    if(!isset($question->question)) {
      throw new Exception('Question missing.');
    }
    else if(!preg_match($GLOBALS['qAndARegExp'],$question->question)) {
      throw new Exception('Invalid question name.');
    }
    else if(!isset($question->otherAnswers)) {
      throw new Exception('Other answers missing.');
    }
    else if(count($question->otherAnswers) != 3) {
      throw new Exception('3 alternate answers must be provided for each question.');
    }
    if(!isset($question->correctAnswer)) {
      throw new Exception('Correct answer missing.');
    }
    else if(!preg_match($GLOBALS['qAndARegExp'],$question->correctAnswer)) {
      throw new Exception('Invalid answer.');
    }
    foreach($question->otherAnswers as $answer) {
      if(!preg_match($GLOBALS['qAndARegExp'],$answer)) {
        throw new Exception('Invalid answer.');
      }
    }
  }
}
?>
