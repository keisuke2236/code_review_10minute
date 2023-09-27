<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>CB WordPress Sync</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-KyZXEAg3QhqLMpG8r+8fhAXLRk2vvoC2f3B09zVXn8CA5QIVfZOJ3BCsw2P0p/We" crossorigin="anonymous">
</head>
<body>
<?php
require 'vendor/autoload.php';
use Aws\Ssm\SsmClient;
use Aws\DynamoDb\DynamoDbClient;

const USE_SERVER_ERROR_MESSAGE = '<div class="message">前の人が同期を実施中です。完了するまでslackの通知を見てお待ちください。トップ画面に戻るには、<a href="https://hoge-test.dev.kmsn.work/hogehoge/HogeMedia.php"> https://hoge-test.dev.kmsn.work/hogehoge/HogeMedia.php</a>にアクセスしてください</div>';
const SYNC_MESSAGE = '<div class="message">同期中です。トップ画面に戻るには、<a href="https://hoge-test.dev.kmsn.work/hogehoge/HogeMedia.php">https://hoge-test.dev.kmsn.work/hogehoge/HogeMedia.php</a>にアクセスしてください</div>';

function sendSlackNotification($accessToken, $channel, $message) {
  $url = 'https://slack.com/api/chat.postMessage';
  $headers = ['Authorization: Bearer ' . $accessToken,'Content-Type: application/json',];
  $payload = json_encode(['channel' => $channel,'text' => $message,]);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

  $result = curl_exec($ch);
  curl_close($ch);

  return $result;
}

function syncDbData($dynamoDbClient, $client, $action) {
  $tableName = 'hoge-dev';
  $lockKey = $action;

  $dynamoDbClient->putItem([
    'TableName' => $tableName,
    'Item' => ['LockTable' => ['S' => $lockKey]],
  ]);

  $directoryPath = "/XXXXX/XXXXX/HogeMedia/profiles/";
  $files = array_diff(scandir($directoryPath), array('..', '.'));

  $mediaMap = [];
  foreach ($files as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) === 'yml') {
      $filenameWithoutExt = pathinfo($file, PATHINFO_FILENAME);
      $mediaMap[$filenameWithoutExt] = $filenameWithoutExt;
    }
  }

  if (!array_key_exists($action, $mediaMap)) {
    echo "不正なアクションが指定されました。";
    exit;
  }

  $media = $mediaMap[$action];
  $command = "bash /xxxxx/HogeMedia/sync/hoge.sh /xxxxx/HogeMedia/profiles/${media}.yml";
  $commands = [$command];

  $result = $client->sendCommand([
    'DocumentName' => 'AWS-RunShellScript',
    'Parameters' => ['commands' => $commands],
    'Targets' => [['Key' => 'InstanceIds','Values' => ['XXXXXXXXXXXXXXXX']]],
    'MaxConcurrency' => '2',
    'MaxErrors' => '0',
  ]);

  echo SYNC_MESSAGE;

  $commandId = $result['Command']['CommandId'];

  $slackAccessToken = '';
  $slackChannel = '';
  sendSlackNotification($slackAccessToken, $slackChannel, "'$media'から同期中です。今しばらくお待ちください");
}

$client = new SsmClient(['version' => 'latest', 'region' => '']);
$dynamoDbClient = new DynamoDbClient(['version' => 'latest', 'region' => '',]);

$action = $_POST['action'] ?? '';

$tableName = 'hoge-dev';
$lockKey = $action;

try {
  $item = $dynamoDbClient->getItem([
    'TableName' => $tableName,
    'Key' => ['LockTable' => ['S' => $lockKey]],
  ]);

  $is_server_using = isset($item['Item']);
  if ($is_server_using) {
    echo USE_SERVER_ERROR_MESSAGE;
  } else {
    syncDbData($dynamoDbClient, $client, $action);
  }
} catch (Exception $e) {
  echo $e->getMessage();
}
?>
</body>
</html>
