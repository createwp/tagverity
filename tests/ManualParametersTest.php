<?php
require_once __DIR__ . '/../src/ManualParameters.php';

use GTMAuditor\ManualParameters;

function manual_fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function manual_expect_code(callable $fn, string $code, string $label): void {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() !== $code) manual_fail($label . ' threw ' . $e->getMessage() . ', expected ' . $code . '.');
        return;
    }
    manual_fail($label . ' did not throw ' . $code . '.');
}

$promo = ['name' => 'promo_code', 'value' => 'SPRING24'];

// Valid input is cleaned unchanged.
$clean = ManualParameters::clean([$promo], []);
if ($clean !== [['name' => 'promo_code', 'value' => 'SPRING24']]) {
    manual_fail('A valid manual parameter should be cleaned unchanged.');
}

// Invalid names.
foreach (['email', 'customer_phone', 'Promo', '1code'] as $name) {
    manual_expect_code(fn () => ManualParameters::clean([['name' => $name, 'value' => 'x']], []), 'manual_parameter_name', "name {$name}");
}

// Conflicts with an available parameter, and duplicates.
manual_expect_code(fn () => ManualParameters::clean([['name' => 'currency', 'value' => 'AUD']], ['currency']), 'manual_parameter_conflict', 'available name');
manual_expect_code(fn () => ManualParameters::clean([$promo, $promo], []), 'manual_parameter_conflict', 'duplicate name');

// Invalid values.
manual_expect_code(fn () => ManualParameters::clean([['name' => 'promo_code', 'value' => str_repeat('a', 101)]], []), 'manual_parameter_value', '101 character value');
manual_expect_code(fn () => ManualParameters::clean([['name' => 'promo_code', 'value' => "a\nb"]], []), 'manual_parameter_value', 'control character value');
manual_expect_code(fn () => ManualParameters::clean([['name' => 'promo_code', 'value' => '']], []), 'manual_parameter_value', 'empty value');

// Limit: 10 is allowed, 11 is not.
$ten = [];
for ($i = 1; $i <= 10; $i++) $ten[] = ['name' => 'param_' . $i, 'value' => 'v' . $i];
if (count(ManualParameters::clean($ten, [])) !== 10) manual_fail('Ten manual parameters should be allowed.');
$eleven = $ten;
$eleven[] = ['name' => 'param_11', 'value' => 'v11'];
manual_expect_code(fn () => ManualParameters::clean($eleven, []), 'manual_parameter_limit', 'eleven parameters');

// Storage helpers: empty clears to NULL, and a stored list decodes back unchanged.
if (ManualParameters::encode([]) !== null) manual_fail('An empty manual list should be stored as NULL.');
if (ManualParameters::decode(null) !== [] || ManualParameters::decode('') !== []) manual_fail('A missing stored value should decode to [].');
if (ManualParameters::decode(ManualParameters::encode([$promo])) !== [$promo]) manual_fail('A stored manual list should decode unchanged.');

// Round trip through TrackingWorkspace::load() against a directly inserted row.
$pdoAvailable = true;
try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    $pdo = db();
} catch (Throwable $e) {
    $pdoAvailable = false;
}

if (!$pdoAvailable) {
    echo "ManualParametersTest round trip ... skipped: no database\n";
    echo "ManualParameters tests passed.\n";
    exit(0);
}

require_once __DIR__ . '/../src/ReleaseEventBuilder.php';
require_once __DIR__ . '/../src/TrackingWorkspace.php';
require_once __DIR__ . '/Fakes/TestFixtures.php';

use GTMAuditor\TrackingWorkspace;
use GTMAuditor\Tests\Fakes\TestFixtures;

$accountId = TestFixtures::createAccount($pdo);
$userId = 0;
$clientId = 0;
$eventKey = 'base:generate_lead';
try {
    $clientId = TestFixtures::createClient($pdo, $accountId);
    $pdo->prepare('INSERT INTO users (account_id, email) VALUES (?, ?)')
        ->execute([$accountId, 'tv-manual-' . bin2hex(random_bytes(4)) . '@example.com']);
    $userId = (int)$pdo->lastInsertId();
    $plan = ['events' => [['name' => 'generate_lead', 'parameters' => ['value']]]];
    $pdo->prepare("INSERT INTO onboarding_profiles (client_id, site_url, status, measurement_plan) VALUES (?, ?, 'plan_ready', ?)")
        ->execute([$clientId, 'https://example.com', json_encode($plan)]);

    // Saved without manual parameters: the column stays NULL and the workspace loads [].
    $save = $pdo->prepare(
        'INSERT INTO tracking_event_settings
         (client_id, event_key, source_event_name, approved_event_name, event_group, enabled, selected_parameters, manual_parameters, updated_by_user_id)
         VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)'
    );
    $save->execute([$clientId, $eventKey, 'generate_lead', 'generate_lead', 'lead', '["value"]', ManualParameters::encode([]), $userId]);
    $column = $pdo->prepare('SELECT manual_parameters FROM tracking_event_settings WHERE client_id = ? AND event_key = ?');
    $column->execute([$clientId, $eventKey]);
    if ($column->fetchColumn() !== null) manual_fail('A row saved without manual parameters should keep a NULL column.');
    $event = (new TrackingWorkspace())->findEvent((new TrackingWorkspace())->load($pdo, $clientId), $eventKey);
    if (!$event || ($event['manual_parameters'] ?? null) !== []) manual_fail('A NULL manual_parameters column should load as [].');

    // Saved with a manual parameter: the stored list loads back on the event.
    $update = $pdo->prepare('UPDATE tracking_event_settings SET manual_parameters = ? WHERE client_id = ? AND event_key = ?');
    $update->execute([ManualParameters::encode([$promo]), $clientId, $eventKey]);
    $event = (new TrackingWorkspace())->findEvent((new TrackingWorkspace())->load($pdo, $clientId), $eventKey);
    if (!$event || ($event['manual_parameters'] ?? null) !== [$promo]) manual_fail('Saved manual parameters should load on the event.');
} catch (Throwable $e) {
    manual_fail('Manual parameter round trip failed: ' . $e->getMessage());
} finally {
    if ($clientId > 0) {
        $pdo->prepare('DELETE FROM tracking_event_settings WHERE client_id = ?')->execute([$clientId]);
        $pdo->prepare('DELETE FROM onboarding_profiles WHERE client_id = ?')->execute([$clientId]);
    }
    if ($userId > 0) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    TestFixtures::cleanupAccount($pdo, $accountId);
}

echo "ManualParameters tests passed.\n";
