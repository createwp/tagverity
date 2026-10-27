<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/api.php';

use GTMAuditor\ElementTemplateBuilder;

$user = require_auth();
api_require_post();
api_require_editor($user);
$input = api_json_input();
api_require_csrf($input);

$clientId = (int)($input['client_id'] ?? 0);
$mappingId = (int)($input['mapping_id'] ?? 0);
assert_client_access($clientId);

$stmt = db()->prepare(
    "SELECT op.latest_scan_id, op.measurement_plan, ss.root_url
     FROM onboarding_profiles op
     JOIN site_scans ss ON ss.id = op.latest_scan_id AND ss.client_id = op.client_id
     WHERE op.client_id = ? AND op.status = 'plan_ready' AND ss.status = 'complete'
     LIMIT 1"
);
$stmt->execute([$clientId]);
$profile = $stmt->fetch();
if (!$profile) {
    api_respond(['error' => 'measurement_plan_required', 'message' => 'Create a measurement plan before mapping elements.'], 409);
}

$pageUrl = trim((string)($input['page_url'] ?? ''));
$submitted = parse_url($pageUrl);
$allowed = parse_url($profile['root_url'] ?? '');
$scheme = strtolower($submitted['scheme'] ?? '');
$host = strtolower(rtrim($submitted['host'] ?? '', '.'));
$allowedHost = strtolower(rtrim($allowed['host'] ?? '', '.'));

$pageUrlValid = $pageUrl !== ''
    && !preg_match('/[\x00-\x1F\x7F\\\\]/', $pageUrl)
    && in_array($scheme, ['http', 'https'], true)
    && !isset($submitted['user']) && !isset($submitted['pass'])
    && $host !== '' && $host === $allowedHost
    && ($submitted['port'] ?? null) === ($allowed['port'] ?? null);

if (!$pageUrlValid) {
    api_respond(['error' => 'page_outside_domain', 'message' => 'Choose a page on your verified site domain.'], 422);
}

$pageUrl = $scheme . '://' . $host
    . (isset($submitted['port']) ? ':' . $submitted['port'] : '')
    . ($submitted['path'] ?? '/')
    . (isset($submitted['query']) ? '?' . $submitted['query'] : '');

$elementName = mb_substr(trim(strip_tags((string)($input['element_name'] ?? ''))), 0, 120);
$selector = trim((string)($input['css_selector'] ?? ''));
if (mb_strlen($elementName) < 2 || $selector === '' || mb_strlen($selector) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $selector)) {
    api_respond(['error' => 'invalid_element', 'message' => 'Select an element and give it a clear business name.'], 422);
}

$builder = new ElementTemplateBuilder();
$intent = (string)($input['tracking_intent'] ?? '');
$goal = (string)($input['measurement_goal'] ?? '');
$dataKey = (string)($input['data_key'] ?? '');
$triggerScope = (string)($input['trigger_scope'] ?? 'page');
if (!in_array($intent, $builder->allowedIntents(), true) || !in_array($goal, $builder->allowedGoals(), true)) {
    api_respond(['error' => 'invalid_mapping_choice'], 422);
}
if ($dataKey !== '' && !in_array($dataKey, $builder->allowedDataKeys(), true)) {
    api_respond(['error' => 'invalid_data_key'], 422);
}
if (!in_array($triggerScope, ['page', 'global'], true)) {
    api_respond(['error' => 'invalid_trigger_scope'], 422);
}

$attributes = [];
$allowedAttributes = ['tag', 'text', 'id', 'class_name', 'href', 'type', 'name', 'field_label', 'autocomplete',
    'form_id', 'form_class_name', 'form_name', 'form_selector', 'aria_label', 'placeholder', 'recommended_intent'];
foreach (is_array($input['element_attributes'] ?? null) ? $input['element_attributes'] : [] as $key => $value) {
    if (in_array($key, $allowedAttributes, true) && is_scalar($value)) {
        $attributes[$key] = mb_substr(trim(strip_tags((string)$value)), 0, 255);
    }
}
$role = $intent === 'form' ? 'form' : ($intent === 'ecommerce_value' ? 'product_data' : ($intent === 'site_search' ? 'search_control' : (($attributes['tag'] ?? '') === 'a' ? 'navigation_or_cta' : 'content_element')));
$measurementPlan = json_decode($profile['measurement_plan'], true) ?: [];
$destinations = is_array($measurementPlan['destinations'] ?? null) ? $measurementPlan['destinations'] : [];
$templates = $builder->build([
    'element_name' => $elementName,
    'tracking_intent' => $intent,
    'measurement_goal' => $goal,
    'css_selector' => $selector,
    'page_url' => $pageUrl,
    'trigger_scope' => $triggerScope,
    'data_key' => $dataKey ?: null,
], $destinations);

try {
    $attributesJson = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $eventJson = json_encode($templates['event_template'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $triggerJson = json_encode($templates['trigger_template'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $tagsJson = json_encode($templates['tag_templates'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $previewText = mb_substr($attributes['text'] ?? '', 0, 255);

    if ($mappingId > 0) {
        $stmt = db()->prepare(
            "UPDATE measurement_elements
             SET page_url = ?, trigger_scope = ?, element_name = ?, element_role = ?, tracking_intent = ?, measurement_goal = ?,
                 css_selector = ?, data_key = ?, preview_text = ?, element_attributes = ?, event_template = ?,
                 trigger_template = ?, tag_templates = ?, site_scan_id = ?, updated_at = NOW()
             WHERE id = ? AND client_id = ? AND status = 'active'"
        );
        $stmt->execute([$pageUrl, $triggerScope, $elementName, $role, $intent, $goal, $selector, $dataKey ?: null, $previewText,
            $attributesJson, $eventJson, $triggerJson, $tagsJson, (int)$profile['latest_scan_id'], $mappingId, $clientId]);
        if ($stmt->rowCount() === 0) {
            $check = db()->prepare("SELECT id FROM measurement_elements WHERE id = ? AND client_id = ? AND status = 'active' LIMIT 1");
            $check->execute([$mappingId, $clientId]);
            if (!$check->fetch()) api_respond(['error' => 'mapping_not_found'], 404);
        }
    } else {
        $stmt = db()->prepare(
            "INSERT INTO measurement_elements
             (client_id, site_scan_id, created_by_user_id, page_url, trigger_scope, element_name, element_role, tracking_intent,
              measurement_goal, css_selector, selector_strategy, data_key, preview_text, element_attributes,
              event_template, trigger_template, tag_templates, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'generated_css', ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())"
        );
        $stmt->execute([$clientId, (int)$profile['latest_scan_id'], $user['id'], $pageUrl, $triggerScope, $elementName, $role, $intent,
            $goal, $selector, $dataKey ?: null, $previewText, $attributesJson, $eventJson, $triggerJson, $tagsJson]);
        $mappingId = (int)db()->lastInsertId();
    }

    api_respond(['ok' => true, 'data' => [
        'mapping' => [
            'id' => $mappingId,
            'page_url' => $pageUrl,
            'trigger_scope' => $triggerScope,
            'element_name' => $elementName,
            'element_role' => $role,
            'tracking_intent' => $intent,
            'measurement_goal' => $goal,
            'css_selector' => $selector,
            'data_key' => $dataKey ?: null,
            'element_attributes' => $attributes,
            'event_template' => $templates['event_template'],
            'trigger_template' => $templates['trigger_template'],
            'tag_templates' => $templates['tag_templates'],
            'recommendation' => $templates['recommendation'],
            'consent_requirement' => $templates['consent_requirement'],
        ],
    ]]);
} catch (Throwable $e) {
    error_log('Element mapping save failed for client #' . $clientId . ': ' . $e->getMessage());
    api_respond(['error' => 'mapping_save_failed', 'message' => 'The mapping could not be saved. Please try again.'], 500);
}
