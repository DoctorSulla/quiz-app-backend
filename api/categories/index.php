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
logRequest($requestBody,$method);
$requestObject = json_decode($requestBody);

if($requestObject == null && strlen($requestBody) > 0) {
  $responseObject->error = true;
  $responseObject->message = 'Invalid JSON in request body.';
  http_response_code(400);
  die(json_encode($responseObject));
}

switch($method) {
  case "GET":
    if(isset($_GET['category'])) {
      $category = $_GET['category'];
      $category = htmlentities($category,ENT_QUOTES,'UTF-8');
      $email = get_jwt_claims($jwt)->email;
      $user = get_user($email,$dynamodb,$marshaler);
      $ownedCategories = $user->ownedCategories;
      if(!in_array($category,$ownedCategories)) {
        http_response_code(401);
        $responseObject->additionalInfo = $category;
        $responseObject->error = true;
        $responseObject->message = 'You do not have permission to edit this category.';
        die(json_encode($responseObject));
      }
      try {
        $responseObject = get_category($category,$dynamodb,$marshaler);
      } catch(Exception $e) {
        $responseObject->error = true;
        $responseObject->message = $e->getMessage();
        die(json_encode($responseObject));
      }
      die(json_encode($responseObject));
    }
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
              $category->name = html_entity_decode($item['category'],ENT_QUOTES,'UTF-8');
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
  
  // Add default icon if one is not provided
  if(!isset($requestObject->icon)) {
    $icon = 'faQuestion';
    $requestObject->icon = $icon;
  }
  else {
    $icon = $requestObject->icon;
  }

  $requestObject = encode_category_entities($requestObject);
  $requestObject->author = get_jwt_claims($jwt)->username;

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
    array_push($user->ownedCategories,$requestObject->category);
    $ownedCategories = $user->ownedCategories;
  }
  else {
    $ownedCategories = [$requestObject->category];
  }
  update_user($email,['ownedCategories'],[$ownedCategories],$dynamodb,$marshaler);

  break;
  case "PATCH":

  // Code to update a category goes here
  $email = get_jwt_claims($jwt)->email;
  $user = get_user($email,$dynamodb,$marshaler);
 
  $category = $requestObject->category;
  $encodedCategory = htmlentities($requestObject->category,ENT_QUOTES,'UTF-8');

  if(in_array($category,$user->ownedCategories)) {
    $requestObject = encode_category_entities($requestObject);

    try { validate_category($requestObject,$dynamodb,$marshaler,true); } catch(Exception $e) {
      $responseObject->error = true;
      $responseObject->message = $e->getMessage();
      die(json_encode($responseObject));
    } 
    $requestObject->author = get_jwt_claims($jwt)->username;
    
    // Add default icon if one is not provided
    if(!isset($requestObject->icon)) {
      $icon = 'faQuestion';
      $requestObject->icon = $icon;
    }
    else {
      $icon = $requestObject->icon;
    }
    add_to_table('categories',$requestObject,$dynamodb,$marshaler);
    $responseObject->error = false;
    $responseObject->message = $requestObject->category. " updated successfully.";
    echo json_encode($responseObject);
  }
  else {
    http_response_code(403);
    $responseObject->error = true;
    $responseObject->message = "You do not have permission to edit this category.";
    die (json_encode($responseObject));
  }

  break;
  default:
  die(http_response_code(405));
}
?>
