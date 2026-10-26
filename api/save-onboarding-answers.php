<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';

use GTMAuditor\OnboardingPlanner;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT op.latest_scan_id, ss.scan_data, ss.questionnaire
     FROM onboarding_profiles op
     JOIN site_scans ss ON ss.id = op.latest_scan_id AND ss.client_id = op.client_id
     WHERE op.client_id = ? AND ss.status = 'complete'
     LIMIT 1"
);
$stmt->execute([$clientId]);
$row = $stmt->fetch();
if (!$row) {
    api_respond(['error' => 'scan_required', 'message' => 'Run a site scan before creating a measurement plan.'], 409);
}

try {
    $scan = json_decode($row['scan_data'], true, 512, JSON_THROW_ON_ERROR);
    $questionnaire = json_decode($row['questionnaire'], true, 512, JSON_THROW_ON_ERROR);
    $planner = new OnboardingPlanner();
    $answers = $planner->normaliseAnswers(is_array($input['answers'] ?? null) ? $input['answers'] : [], $questionnaire);
    $plan = $planner->buildMeasurementPlan($scan, $answers);
    $answersJson = json_encode($answers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $planJson = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $stmt = db()->prepare(
        "UPDATE onboarding_profiles
         SET status = 'plan_ready', business_type = ?, answers = ?, measurement_plan = ?, updated_at = NOW()
         WHERE client_id = ? AND latest_scan_id = ?"
    );
    $stmt->execute([$answers['business_type'] ?? null, $answersJson, $planJson, $clientId, (int)$row['latest_scan_id']]);

    api_respond(['ok' => true, 'data' => ['answers' => $answers, 'measurement_plan' => $plan]]);
} catch (Throwable $e) {
    error_log('Onboarding plan failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'plan_failed', 'message' => 'We could not create the draft plan. Please try again.'], 500);
}

