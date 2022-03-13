This repository contains the backend APIs for QuizApp which is written in PHP. In order to get the project up and running you will need to configure your own config file from config-template.php with AWS / SMTP credentials and a hashing key.

You will also need a DynamoDB with the following tables:
- activation_codes (Partition key: email)
- categories (Partition key: category)
- games (Partition key: id)
- users (Partition key: email)
