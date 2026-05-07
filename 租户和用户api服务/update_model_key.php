<?php
// Update Model API Key Script
namespace think;

require __DIR__ . '/vendor/autoload.php';

// Initialize App
$app = new App();
$app->initialize();

use app\model\ModelConfig;

// Get args
$args = $_SERVER['argv'];
if (count($args) < 3) {
    echo "Usage: php update_model_key.php <model_identity> <new_api_key>\n";
    echo "Example: php update_model_key.php doubao sk-1234567890\n";
    exit(1);
}

$identity = $args[1];
$newKey = $args[2];

try {
    $model = ModelConfig::where('model_identity', $identity)->find();

    if (!$model) {
        echo "Error: Model '$identity' not found in model_configs table.\n";
        exit(1);
    }

    // Update config
    $config = $model->config;
    
    // Handle case where config might be null or not an array (though model casts it)
    if (!is_array($config)) {
        $config = [];
    }
    
    $config['api_key'] = $newKey;
    
    // Save
    $model->config = $config;
    $model->save();

    echo "Success! API Key for model '{$model->name}' ($identity) has been updated.\n";
    echo "New Key: " . substr($newKey, 0, 5) . "..." . substr($newKey, -4) . "\n";

} catch (\Exception $e) {
    echo "Error updating key: " . $e->getMessage() . "\n";
    exit(1);
}
