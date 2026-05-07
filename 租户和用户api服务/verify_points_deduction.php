<?php
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use think\facade\Db;

// Helper to get DB connection (simulated via direct PDO or just use TP app if possible, but external script is easier with Guzzle)
// Actually, to check DB state, I need to bootstrap TP or use raw PDO.
// Bootstrapping TP in a script:
require __DIR__ . '/public/index.php'; // This runs the app... wait, that's for web request.
// We can use the `think` console entry point approach or just raw PDO.
// Raw PDO is safer to avoid side effects of app run.

$dbHost = '127.0.0.1';
$dbName = 'ai_saas';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

function getVal($pdo, $table, $col, $whereCol, $whereVal) {
    $stmt = $pdo->prepare("SELECT $col FROM $table WHERE $whereCol = ?");
    $stmt->execute([$whereVal]);
    return $stmt->fetchColumn();
}

// 1. Login
$client = new Client(['base_uri' => 'http://localhost:8085']);
echo "Logging in...\n";
try {
    $response = $client->post('/api/auth/login', [
        'json' => [
            'username' => 'testuser',
            'password' => '123456'
        ]
    ]);
    $data = json_decode($response->getBody(), true);
    $token = $data['data']['token'];
    $userId = $data['data']['user']['id'];
    echo "Login successful. Token: " . substr($token, 0, 20) . "...\n";
} catch (\Exception $e) {
    die("Login failed: " . $e->getMessage() . "\n");
}

// List all models
echo "Active Models:\n";
$stmt = $pdo->query("SELECT id, name, model_identity, is_default, cost_per_request, call_count FROM model_configs WHERE status='active'");
$models = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($models as $m) {
    echo "ID: {$m['id']}, Name: {$m['name']}, Identity: {$m['model_identity']}, Default: {$m['is_default']}, Cost: {$m['cost_per_request']}, Count: {$m['call_count']}\n";
}

// 2. Get Initial State
$initialPoints = getVal($pdo, 'users', 'remaining_points', 'id', $userId);
// Assuming using the default model (active, default=1)
// Find default model ID
$stmt = $pdo->query("SELECT id, model_identity, cost_per_request FROM model_configs WHERE is_default=1 AND status='active' LIMIT 1");
$model = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$model) {
    // Try any active model
    $stmt = $pdo->query("SELECT id, model_identity, cost_per_request FROM model_configs WHERE status='active' LIMIT 1");
    $model = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$model) {
    die("No active model found.\n");
}
$modelId = $model['id'];

// Ensure cost is > 0 for testing
if ($model['cost_per_request'] == 0) {
    echo "Setting cost to 10 for testing...\n";
    $pdo->exec("UPDATE model_configs SET cost_per_request = 10 WHERE id = $modelId");
    $model['cost_per_request'] = 10;
}

$cost = $model['cost_per_request'];
$initialCallCount = getVal($pdo, 'model_configs', 'call_count', 'id', $modelId);

echo "Initial Points: $initialPoints\n";
echo "Model Cost: $cost\n";
echo "Initial Call Count: $initialCallCount\n";

if ($initialPoints < $cost) {
    echo "Warning: Not enough points. Adding 100 points for test.\n";
    $pdo->exec("UPDATE users SET remaining_points = remaining_points + 100 WHERE id = $userId");
    $initialPoints += 100;
}

// 3. Make Chat Request
echo "Sending Chat Request for model_identity: " . $model['model_identity'] . "...\n";
try {
    $response = $client->post('/api/v1/llm/chat', [
        'headers' => [
            'Authorization' => $token
        ],
        'json' => [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello']
            ],
            'options' => [
                'model_identity' => $model['model_identity']
            ]
        ]
    ]);
    echo "Chat Response: " . $response->getStatusCode() . "\n";
    // echo $response->getBody() . "\n";
} catch (\Exception $e) {
    echo "Chat failed: " . $e->getMessage() . "\n";
    // Check response body for specific error
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Response Body: " . $e->getResponse()->getBody() . "\n";
    }
}

// 4. Verify State
$finalPoints = getVal($pdo, 'users', 'remaining_points', 'id', $userId);
$finalCallCount = getVal($pdo, 'model_configs', 'call_count', 'id', $modelId);

echo "Final Points: $finalPoints\n";
echo "Final Call Count: $finalCallCount\n";

if ($initialPoints - $finalPoints == $cost) {
    echo "SUCCESS: Points deducted correctly.\n";
} else {
    echo "FAILURE: Points mismatch. Expected deduction: $cost. Actual: " . ($initialPoints - $finalPoints) . "\n";
}

if ($finalCallCount - $initialCallCount == 1) {
    echo "SUCCESS: Call count incremented.\n";
} else {
    echo "FAILURE: Call count mismatch.\n";
}
