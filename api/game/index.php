<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PATCH, POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, jwt");
require_once('../common/index.php');

$method = $_SERVER['REQUEST_METHOD'];

if($method == 'OPTIONS') {
  die(http_response_code(200));
}

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

$player = $user->username;
$userId = $user->id;

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

$responseObject = new stdClass();

switch($method) {
  case "GET":
    if(!isset($_GET['gameId'])) {
      die(http_response_code(400));
    }
    $timestamp = time();
    $gameId = $_GET['gameId'];
    $retrieveQuestions = $_GET['retrieveQuestions'];
    $result = get_game($gameId,$dynamodb,$marshaler);

    // Check if the calling user is a player in the game
    if(!in_array($userId,$result->ids)) {

      die(http_response_code(401));
    }

    if($result->gameStatus == "AwaitingPlayers" || $retrieveQuestions == "false") {

      // If both players have answered but the game has not moved on for some reason move to the next question
      if($result->completedP1 == true && $result->completedP2 == true && $activeQuestion != 7) {
        $newQuestion = $result->activeQuestion + 1;
        update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','activeQuestion'],[$result->scores,null,null,false,false,$newQuestion],$dynamodb,$marshaler);
      }

      // If it has been more than 20 seconds since the question started move to the next question
      $time = time();
      if(($result->timestampP1 != null && $result->timestampP1 + 20 < $time) || ($result->timestampP2 != null && $result->timestampP2 + 20 < $time) && $activeQuestion != 7) {
        $newQuestion = $result->activeQuestion + 1;
        update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','activeQuestion'],[$result->scores,null,null,false,false,$newQuestion],$dynamodb,$marshaler);
      }


      $responseObject->players = $result->players;
      $responseObject->gameStatus = $result->gameStatus;
      $responseObject->activeQuestion = $result->activeQuestion;
      $responseObject->scores = $result->scores;
      die(json_encode($responseObject));
    }

    // Player One code
    if($userId == $result->ids[0]) {
      if($result->timestampP1 == null) {
        update_game_properties($gameId,['timestampP1'],[$timestamp],$dynamodb,$marshaler);
      }
      if($result->completedP2) {
        $responseObject->otherPlayerAnswered = true;
      }
    }
    // Player Two code
    else if($userId == $result->ids[1]) {
      if($result->timestampP2 == null) {
        update_game_properties($gameId,['timestampP2'],[$timestamp],$dynamodb,$marshaler);
      }
      if($result->completedP1) {
        $responseObject->otherPlayerAnswered = true;
      }
    }
    else {
      die(http_response_code(401));
    }

    // Get current question and answers
    $activeQuestion = $result->activeQuestion;
    $questionAndAnswers = $result->questions[$activeQuestion];
    // Get question text
    $question = $questionAndAnswers->question;
    $question = html_entity_decode($question,ENT_QUOTES,'UTF-8');
    // Combine correct and incorrect answers and shuffle the array
    $answers = $questionAndAnswers->otherAnswers;
    array_push($answers,$questionAndAnswers->correctAnswer);
    for($i=0;$i <count($answers);$i++) {
      $answers[$i] = html_entity_decode($answers[$i],ENT_QUOTES,'UTF-8');
    }
    shuffle($answers);
    // Populate the response object
    $responseObject->id = $result->id;
    $responseObject->activeQuestion = $activeQuestion;
    $responseObject->question = $question;
    $responseObject->answers = $answers;
    $responseObject->scores = $result->scores;
    $responseObject->players = $result->players;
    $responseObject->gameStatus = $result->gameStatus;

    echo json_encode($responseObject);
  break;
  case "POST":
    if(!isset($requestObject->category) || count(get_object_vars($requestObject)) !== 1) {
        die(http_response_code(400));
    }
    $gameId = generate_id($charSet,6);
    $category = $requestObject->category;
    // Category needs to be encoded or it will fail to find a match in the DB
    $category = htmlentities($category,ENT_QUOTES,'UTF-8',false);


    $key = $marshaler->marshalJson('
        {
          "category": "' .$category. '"
        }
    ');

    $params = [
        'TableName' => 'categories',
        'Key' => $key
    ];

    try {
        $result = $dynamodb->getItem($params);
        // Check if result is empty, if so the category is invalid
        if($result['Item'] == null) {
          die(http_response_code(400));
        }
        // Turn result into a PHP object
        $result = $marshaler->unmarshalJson($result['Item']);
        $result = json_decode($result);
        // Get the questions
        $questions = $result->questions;
        // Select 7 random indexes from the questions array
        $questionIndexes = array_rand($questions, 7);
        // Create an empty array to store the questions
        $chosenQuestions = [];
        // Populate the array by iterating over the questions array with the indexes
        foreach($questionIndexes as $index) {
          array_push($chosenQuestions,$questions[$index]);
        }
        $game = new GameInstance($gameId,$category,$player,$userId,$chosenQuestions);
        add_to_table('games',$game,$dynamodb,$marshaler);
        $responseObject->id = $gameId;
        echo json_encode($responseObject);

    } catch (DynamoDbException $e) {
        echo "Coudln't fetch category:\n";
        echo $e->getMessage() . "\n";
    }
  break;
  case "PATCH":
  if(!isset($requestObject->gameId) || !isset($requestObject->action)) {
      die(http_response_code(400));
  }
  $gameId = $requestObject->gameId;
  $action = $requestObject->action;
  try {
    $result = get_game($gameId,$dynamodb,$marshaler);
  } catch(Exception $e) {
    $responseObject->error = true;
    $responseObject->message = $e->getMessage();
    die(json_encode($responseObject));
  }

  if($action == "JOIN") {
    if(count(get_object_vars($requestObject)) > 3) {
      die(http_response_code(400));
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
    // Check if a new player should be able to join
    if(count($result->players) > 1) {
      $responseObject->error = true;
      $responseObject->message = "The game you are trying to join is already full.";
      die(json_encode($responseObject));
    }
    else if($result->gameStatus != "AwaitingPlayers") {
      $responseObject->error = true;
      $responseObject->message = "The game you are trying to join has already started";
      die(json_encode($responseObject));
    }
    // Add the new player
    array_push($result->players,$player);
    array_push($result->ids,$userId);
    // Update the game status
    $result->status = "Started";

    // Push the updated object to the database
    update_game_properties($gameId,['gameStatus','players','ids'],[$result->status,$result->players,$result->ids],$dynamodb,$marshaler);
    $responseObject->id = $gameId;
    echo json_encode($responseObject);
  }
  else if($action == "ANSWER") {

    // Check if the calling user is a player in the game
    if(!in_array($userId,$result->ids)) {
      die(http_response_code(401));
    }

    if(!isset($requestObject->answer) || count(get_object_vars($requestObject)) > 4) {
      die(http_response_code(400));
    }
    else {
      if($result->activeQuestion == 6) {
        $bonus = true;
      }
      else {
        $bonus = false;
      }
      $correctAnswer = $result->questions[$result->activeQuestion]->correctAnswer;
      $submittedAnswer = htmlentities($requestObject->answer,ENT_QUOTES,'UTF-8',false);
      if($result->gameStatus == "AwaitingPlayers") {
        $responseObject->error = true;
        $responseObject->message = "You cannot answer a question for a game that hasn't started.";
        die(json_encode($responseObject));
      }
      if($result->gameStatus == "Finished") {
        $responseObject->error = true;
        $responseObject->message = "You cannot answer a question for a game that has finished.";
        die(json_encode($responseObject));
      }
      // Code for player one submitting an answer
      if($player == $result->players[0]) {

        // Check if the user has already answered this question
        if($result->completedP1 == true) {
          $responseObject->error = true;
          $responseObject->message = "You have already answered this question.";
          die(json_encode($responseObject));
        }
        // Check if the question has been fetched before accepting an answer
        if($result->timestampP1 == null) {
          $responseObject->error = true;
          $responseObject->message = "Your timer for this question hasn't started yet.";
          die(json_encode($responseObject));
        }
        // If the answer is correct then the score should be updated
        if($submittedAnswer == $correctAnswer) {
          $responseObject->correct = true;
          $result->scores[0] = calculate_score($result->scores[0],$result->timestampP1,$bonus);
          $responseObject->scores = $result->scores;
        }
        else {
          $responseObject->correct = false;
          $responseObject->scores = $result->scores;
        }
        // Other player has answered already
        if($result->completedP2 == true) {
          if($result->activeQuestion == 6) {
            // End the game
            $result->gameStatus = "Finished";
            update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','gameStatus'],[$result->scores,null,null,false,false,'Finished'],$dynamodb,$marshaler);
          }
          else {
            // Move the game to the next question
            $newQuestion = $result->activeQuestion + 1;
            update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','activeQuestion'],[$result->scores,null,null,false,false,$newQuestion],$dynamodb,$marshaler);
          }
          $responseObject->status = $result->gameStatus;
        }
        else {
          update_game_properties($gameId,['scores','completedP1'],[$result->scores,true],$dynamodb,$marshaler);
        }
      }
      // Code for player two submitting an answer
      else if($player == $result->players[1]) {

        // Check if the user has already answered this question
        if($result->completedP2 == true) {
          $responseObject->error = true;
          $responseObject->message = "You have already answered this question.";
          die(json_encode($responseObject));
        }

        // Check if the question has been fetched before accepting an answer
        if($result->timestampP2 == null) {
          $responseObject->error = true;
          $responseObject->message = "Your timer for this question hasn't started yet.";
          die(json_encode($responseObject));
        }
        // If the answer is correct then the score should be updated
        if($submittedAnswer == $correctAnswer) {
          $responseObject->correct = true;
          $result->scores[1] = calculate_score($result->scores[1],$result->timestampP2,$bonus);
          $responseObject->scores = $result->scores;
        }
        else {
          $responseObject->correct = false;
          $responseObject->scores = $result->scores;
        }
        // Other player has answered already
        if($result->completedP1 == true) {
          if($result->activeQuestion == 6) {
            // End the game
            $result->gameStatus = "Finished";
            update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','gameStatus'],[$result->scores,null,null,false,false,'Finished'],$dynamodb,$marshaler);
          }
          else {
            // Move the game to the next question
            $newQuestion = $result->activeQuestion + 1;
            update_game_properties($gameId,['scores','timestampP1','timestampP2','completedP1','completedP2','activeQuestion'],[$result->scores,null,null,false,false,$newQuestion],$dynamodb,$marshaler);
          }
          $responseObject->status = $result->gameStatus;
        }
        else {
          update_game_properties($gameId,['scores','completedP2'],[$result->scores,true],$dynamodb,$marshaler);
        }
      }
      else {
        die(http_response_code(401));
      }
      $responseObject->correctAnswer = $correctAnswer;
      echo json_encode($responseObject);
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
