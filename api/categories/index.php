<?php
header('Access-Control-Allow-Origin: *');
require_once('../common/index.php');

$responseObject = new stdClass();

$method = $_SERVER['REQUEST_METHOD'];

switch($method) {
  case "GET":
    // Create an empty array
    $categories = [];
    // Set the params to query the table
    $params = [
      'TableName' => 'categories',
      'ProjectionExpression' => 'category'
    ];

    // Scan through the list of categories and add them to the array
    try {
      while (true) {
          $result = $dynamodb->scan($params);

          foreach ($result['Items'] as $i) {
              $category = $marshaler->unmarshalItem($i);
              array_push($categories,$category['category']);
          }

          if (isset($result['LastEvaluatedKey'])) {
              $params['ExclusiveStartKey'] = $result['LastEvaluatedKey'];
          } else {
              break;
          }
      }
      $responseObject->categories = $categories;
      http_response_code(200);
      echo json_encode($responseObject);

    } catch (DynamoDbException $e) {
      $responseObject->message = "Unable to retrieve categories due to ".$e->getMessage();
      http_response_code(500);
      echo json_encode($responseObject);
    }
  break;
  default:
  die(http_response_code(405));
}
?>
