<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PATCH, POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, jwt");
require_once('../common/index.php');

$responseObject = new stdClass();

$method = $_SERVER['REQUEST_METHOD'];

if($method == 'OPTIONS') {
  die(http_response_code(200));
}

$headers = getallheaders();

// API authentication

if(!isset($headers['jwt'])) {
  die(http_response_code(401));
}

$jwt = $headers['jwt'];

try { verify_jwt($jwt,$hmacSecret); } catch(Exception $e) {
  die(http_response_code(401));
}

// End API authentication

$requestBody = file_get_contents('php://input');
$requestObject = json_decode($requestBody);

switch($method) {
  case "GET":
    // Create an empty array
    $categories = [];
    // Set the params to query the table
    $params = [
      'TableName' => 'categories',
      'ProjectionExpression' => 'category, icon'
    ];

    // Scan through the list of categories and add them to the array
    try {
      while (true) {
          $result = $dynamodb->scan($params);

          foreach ($result['Items'] as $i) {
              $item = $marshaler->unmarshalItem($i);
              $category = new stdClass();
              $category->name = $item['category'];
              $category->icon = $item['icon'];
              array_push($categories,$category);
          }

          if (isset($result['LastEvaluatedKey'])) {
              $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
          } else {
              break;
          }
      }
      $responseObject->categories = $categories;
      echo json_encode($responseObject);

    } catch (DynamoDbException $e) {
      $responseObject->message = "Unable to retrieve categories due to ".$e->getMessage();
      http_response_code(500);
      echo json_encode($responseObject);
    }
  break;
  case "POST":

  if(!isset($requestObject->category)) {
    die(http_response_code(400));
  }

  try { validate_category($requestObject,$dynamodb,$marshaler); } catch(Exception $e) {
    $responseObject->error = true;
    $responseObject->message = $e->getMessage();
    die(json_encode($responseObject));
  }

  add_to_table('categories',$requestObject,$dynamodb,$marshaler);
  $responseObject->error = false;
  $responseObject->message = $requestObject->category. " created successfully.";
  echo json_encode($responseObject);


  // Add category to user so they can edit it later
  $email = get_jwt_claims($jwt)->email;
  $user = get_user($email,$dynamodb,$marshaler);
  if(isset($user->ownedCategories)) {
    $ownedCategories = array_push($user->ownedCategories,$requestObject->category);
  }
  else {
    $ownedCategories = [$requestObject->category];
  }
  update_user($email,['ownedCategories'],[$ownedCategories],$dynamodb,$marshaler);

  break;
  case "PATCH":

  // Code to update a category goes here

  break;
  default:
  die(http_response_code(405));
}
?>
