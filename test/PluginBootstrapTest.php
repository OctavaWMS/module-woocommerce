<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '--child') {
    runChild($argv[2] ?? '');
    exit(0);
}

$root = dirname(__DIR__);

assertChildLoadsPlugin($root);
assertChildLoadsPlugin(createFallbackPluginCopy($root));
assertConnectServiceStoresCredentials($root);

echo "Plugin bootstrap tests passed.\n";

function assertChildLoadsPlugin(string $root): void
{
    $cmd = escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg(__FILE__)
        . ' --child '
        . escapeshellarg($root);
    $output = [];
    $code   = 0;
    exec($cmd, $output, $code);

    if ($code !== 0) {
        fail('Child bootstrap failed: ' . implode("\n", $output));
    }

    $result = json_decode(implode("\n", $output), true);
    if (! is_array($result)) {
        fail('Child bootstrap returned invalid JSON: ' . implode("\n", $output));
    }

    assertTrue($result['activation_callback'] === 'OctavaWMS\WooCommerce\Activation', 'Activation callback namespace casing');
    assertTrue($result['activation_class_exists'] === true, 'Activation class resolves');
}

function assertConnectServiceStoresCredentials(string $root): void
{
    require_once $root . '/vendor/autoload.php';

    $GLOBALS['octavawms_options'] = [];

    $service = new \OctavaWMS\WooCommerce\ConnectService();
    $method  = new ReflectionMethod($service, 'storeCredentials');
    $method->setAccessible(true);
    $method->invoke($service, 'https://pro.oawms.com/apps/woocommerce/api/label', 'secret-key', 123);

    $settings = $GLOBALS['octavawms_options']['woocommerce_octavawms_settings'] ?? [];

    assertTrue(
        $GLOBALS['octavawms_options']['octavawms_label_endpoint'] === 'https://pro.oawms.com/apps/woocommerce/api/label',
        'Legacy endpoint option stored'
    );
    assertTrue($GLOBALS['octavawms_options']['octavawms_api_key'] === 'secret-key', 'Legacy API key option stored');
    assertTrue($settings['label_endpoint'] === 'https://pro.oawms.com/apps/woocommerce/api/label', 'Settings endpoint stored');
    assertTrue($settings['api_key'] === 'secret-key', 'Settings API key stored');
    assertTrue($settings['source_id'] === '123', 'Source id stored');
}

function createFallbackPluginCopy(string $root): string
{
    $target = sys_get_temp_dir() . '/octavawms-plugin-fallback-' . bin2hex(random_bytes(6));
    mkdir($target . '/src', 0777, true);
    copy($root . '/octavawms-woocommerce.php', $target . '/octavawms-woocommerce.php');

    foreach (glob($root . '/src/*.php') ?: [] as $file) {
        copy($file, $target . '/src/' . basename($file));
    }

    return $target;
}

function runChild(string $root): void
{
    if ($root === '') {
        fail('Missing plugin root');
    }

    define('ABSPATH', $root);

    $GLOBALS['octavawms_activation_callback'] = null;

    require $root . '/octavawms-woocommerce.php';

    echo json_encode(
        [
            'activation_callback' => $GLOBALS['octavawms_activation_callback'][0] ?? null,
            'activation_class_exists' => class_exists(
                (string) ($GLOBALS['octavawms_activation_callback'][0] ?? '')
            ),
        ],
        JSON_THROW_ON_ERROR
    );
}

function assertTrue(bool $condition, string $message): void
{
    if (! $condition) {
        fail($message);
    }
}

function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function register_activation_hook(string $file, array $callback): void
{
    $GLOBALS['octavawms_activation_callback'] = $callback;
}

function add_action(string $hook, callable $callback, int $priority = 10): void
{
}

function is_admin(): bool
{
    return false;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['octavawms_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value): void
{
    $GLOBALS['octavawms_options'][$name] = $value;
}
