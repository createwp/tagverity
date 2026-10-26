<?php

function api_json_input(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}

function api_respond(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        api_respond(['error' => 'method_not_allowed'], 405);
    }
}

function api_require_csrf(array $input): void {
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $provided = (string)($input['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        api_respond(['error' => 'invalid_csrf'], 403);
    }
}

function api_require_editor(array $user): void {
    if (!in_array($user['role'] ?? '', ['superadmin', 'owner', 'admin', 'member'], true)) {
        api_respond(['error' => 'insufficient_role'], 403);
    }
}
