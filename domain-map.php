<?php
// ─── domain-map.php — Which websites belong to the client (GTM → GA4 → domains) ───
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/src/DomainMap.php';

use GTMAuditor\DomainMap;

$user        = require_auth();
$active_page = 'domain-map';

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$role     = $user['role'] ?? '';
$can_edit = in_array($role, ['superadmin', 'owner', 'admin', 'member'], true);

$pdo = db();

// Clients for the switcher. The requested client must belong to this account, else the first one.
$stmt = $pdo->prepare("SELECT id, name FROM clients WHERE account_id = ? ORDER BY id ASC");
$stmt->execute([$user['account_id']]);
$clients = $stmt->fetchAll();

$requested_client_id = (int)($_GET['client_id'] ?? 0);
$client = null;
foreach ($clients as $candidate) {
    if ((int)$candidate['id'] === $requested_client_id) {
        $client = $candidate;
        break;
    }
}
if ($client === null && $clients !== []) {
    $client = $clients[0];
}

$map = ['containers' => [], 'properties' => [], 'domains' => [], 'edges' => []];
if ($client !== null) {
    DomainMap::refresh($pdo, (int)$client['id']);
    $map = DomainMap::load($pdo, (int)$client['id']);
}

$has_nodes = $map['containers'] !== [] || $map['properties'] !== [] || $map['domains'] !== [];

// ─── Display helpers ──────────────────────────────────────────────────────────

function dm_count(int $n, string $singular, string $plural): string {
    return $n . ' ' . ($n === 1 ? $singular : $plural);
}

function dm_confirmation_label(string $confirmation): string {
    return ['yours' => 'Yours', 'not_yours' => 'Not yours', 'investigate' => 'Investigating'][$confirmation] ?? 'Not confirmed';
}

function dm_confirmation_tone(string $confirmation): string {
    return ['yours' => 'badge-success', 'not_yours' => 'badge-critical', 'investigate' => 'badge-critical'][$confirmation] ?? 'badge-warning';
}

function dm_source_label(string $source): string {
    return ['ga4_stream' => 'From GA4 stream', 'site_scan' => 'From site scan'][$source] ?? 'Added by you';
}

// Summary used as the diagram's aria-label. The table below carries the detail.
function dm_summary(array $map): string {
    $yours = 0;
    $unconfirmed = 0;
    $flagged = 0;
    foreach ($map['domains'] as $domain) {
        if ($domain['confirmation'] === 'yours') {
            $yours++;
        } elseif ($domain['confirmation'] === 'unconfirmed') {
            $unconfirmed++;
        } else {
            $flagged++;
        }
    }

    $text = 'Domain map: '
        . dm_count(count($map['containers']), 'GTM container', 'GTM containers') . ', '
        . dm_count(count($map['properties']), 'GA4 property', 'GA4 properties') . ', '
        . dm_count(count($map['domains']), 'domain', 'domains');

    $parts = [];
    if ($yours > 0) {
        $parts[] = $yours . ' confirmed yours';
    }
    if ($unconfirmed > 0) {
        $parts[] = $unconfirmed . ' unconfirmed';
    }
    if ($flagged > 0) {
        $parts[] = $flagged . ' flagged';
    }
    if ($parts !== []) {
        $text .= ' (' . implode(', ', $parts) . ')';
    }

    return $text;
}

// Node labels show 28 characters at most. The full text sits in the <title> child.
function dm_short(string $text): string {
    return mb_strlen($text, 'UTF-8') > 28 ? mb_substr($text, 0, 27, 'UTF-8') . '…' : $text;
}

// Server-rendered SVG: three columns, curved edges drawn beneath the nodes.
function dm_svg(array $map): string {
    $e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

    $node_w = 240;
    $node_h = 44;
    $top    = 60;
    $step   = 64;

    $columns = [
        ['x' => 40,  'title' => 'GTM containers', 'nodes' => []],
        ['x' => 360, 'title' => 'GA4 properties', 'nodes' => []],
        ['x' => 680, 'title' => 'Domains',        'nodes' => []],
    ];
    foreach ($map['containers'] as $container) {
        $columns[0]['nodes'][] = [
            'key' => 'gtm:' . $container['id'],
            'label' => $container['name'] !== '' ? $container['name'] : $container['public_id'],
            'class' => 'dm-node dm-container',
            'domain_id' => 0,
        ];
    }
    foreach ($map['properties'] as $property) {
        $columns[1]['nodes'][] = [
            'key' => 'ga4:' . $property['id'],
            'label' => $property['display_name'] !== '' ? $property['display_name'] : $property['property_id'],
            'class' => 'dm-node dm-property',
            'domain_id' => 0,
        ];
    }
    foreach ($map['domains'] as $domain) {
        $columns[2]['nodes'][] = [
            'key' => 'domain:' . $domain['id'],
            'label' => $domain['host'],
            'class' => 'dm-node dm-' . $domain['confirmation'],
            'domain_id' => (int)$domain['id'],
        ];
    }

    $rows = max(1, count($columns[0]['nodes']), count($columns[1]['nodes']), count($columns[2]['nodes']));
    $height = 80 + $step * $rows;

    $positions = [];
    foreach ($columns as $column) {
        foreach ($column['nodes'] as $index => $node) {
            $positions[$node['key']] = ['x' => $column['x'], 'y' => $top + $step * $index];
        }
    }

    $edges = '';
    foreach ($map['edges'] as $edge) {
        if (!isset($positions[$edge['from']], $positions[$edge['to']])) {
            continue;
        }
        $from = $positions[$edge['from']];
        $to   = $positions[$edge['to']];
        $x1 = $from['x'] + $node_w;
        $y1 = $from['y'] + $node_h / 2;
        $x2 = $to['x'];
        $y2 = $to['y'] + $node_h / 2;
        $edges .= '<path class="dm-edge" d="M ' . $x1 . ' ' . $y1
            . ' C ' . ($x1 + 80) . ' ' . $y1 . ', ' . ($x2 - 80) . ' ' . $y2 . ', ' . $x2 . ' ' . $y2 . '"/>';
    }

    $headings = '';
    $nodes = '';
    foreach ($columns as $column) {
        $headings .= '<text class="dm-column-heading" x="' . $column['x'] . '" y="32">' . $e($column['title']) . '</text>';
        foreach ($column['nodes'] as $index => $node) {
            $x = $column['x'];
            $y = $top + $step * $index;
            $data = $node['domain_id'] > 0 ? ' data-domain-id="' . $node['domain_id'] . '"' : '';
            $nodes .= '<g class="' . $e($node['class']) . '"' . $data . '>'
                . '<title>' . $e($node['label']) . '</title>'
                . '<rect x="' . $x . '" y="' . $y . '" width="' . $node_w . '" height="' . $node_h . '" rx="10"/>'
                . '<text x="' . ($x + 16) . '" y="' . ($y + 27) . '">' . $e(dm_short($node['label'])) . '</text>'
                . '</g>';
        }
    }

    return '<svg class="dm-svg" viewBox="0 0 960 ' . $height . '" role="img" aria-label="' . $e(dm_summary($map)) . '"'
        . ' data-container-count="' . count($map['containers']) . '"'
        . ' data-property-count="' . count($map['properties']) . '">'
        . $edges . $headings . $nodes
        . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Domain map — GTM Auditor</title>
  <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div class="main-content">

    <div class="page-header">
      <div class="page-header-copy">
        <h1 class="page-title">Domain map</h1>
        <p class="page-subtitle"><?php if ($client !== null): ?><?= $e($client['name']) ?> &middot; <?php endif; ?>Confirm which websites belong to you. Audits flag anything you mark as not yours.</p>
      </div>
      <?php if (count($clients) > 1): ?>
      <div class="page-header-actions">
        <form method="get" action="domain-map.php" class="dm-client-switch">
          <label class="dm-switch-label" for="dm-client">Client</label>
          <select class="form-select" id="dm-client" name="client_id">
            <?php foreach ($clients as $option): ?>
            <option value="<?= (int)$option['id'] ?>"<?= $client !== null && (int)$option['id'] === (int)$client['id'] ? ' selected' : '' ?>><?= $e($option['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-secondary" type="submit">Show client</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="page-body domain-map-page" id="domain-map-app" data-client-id="<?= $client !== null ? (int)$client['id'] : 0 ?>" data-api-base="<?= $e(APP_URL) ?>">
      <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>">

      <?php if ($client === null): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-state-icon"><?= app_icon('globe') ?></div>
          <h3>Add a client website first</h3>
          <p>Websites belong to a client. Add one, then come back here.</p>
        </div>
      </div>
      <?php else: ?>

      <?php if ($has_nodes): ?>
      <!-- ─── 1. Diagram (hidden below 640 px; the list below carries the same information) ─── -->
      <section class="card mb-6 dm-diagram" aria-labelledby="dm-diagram-heading">
        <div class="card-header">
          <div>
            <h2 class="card-title" id="dm-diagram-heading">How your websites connect</h2>
            <p class="card-subtitle">Each line shows a tag container or analytics property sending data from that website.</p>
          </div>
        </div>
        <div class="dm-diagram-body">
          <?= dm_svg($map) ?>
          <ul class="dm-legend" role="list" aria-label="Colour key">
            <li><span class="dm-swatch dm-yours" aria-hidden="true"></span>Confirmed as yours</li>
            <li><span class="dm-swatch dm-unconfirmed" aria-hidden="true"></span>Not confirmed yet</li>
            <li><span class="dm-swatch dm-not_yours" aria-hidden="true"></span>Marked not yours or under investigation</li>
          </ul>
        </div>
      </section>
      <?php endif; ?>

      <!-- ─── 2. Domains ─── -->
      <section class="card mb-6" aria-labelledby="dm-domains-heading">
        <div class="card-header">
          <div>
            <h2 class="card-title" id="dm-domains-heading">Your websites</h2>
            <p class="card-subtitle"><?= $can_edit ? 'Choose an answer for each website. Audits flag anything you mark as not yours or under investigation.' : 'Only editors can change these answers.' ?></p>
          </div>
        </div>

        <?php if ($map['domains'] === []): ?>
        <div class="empty-state">
          <div class="empty-state-icon"><?= app_icon('globe') ?></div>
          <h3>No websites yet</h3>
          <p>Link a GA4 property on the Connections page or run a site scan to see your domains here.</p>
        </div>
        <?php else: ?>
        <ul class="dm-domain-list" role="list">
          <?php foreach ($map['domains'] as $domain): ?>
          <?php
            $confirmation = $domain['confirmation'];
            $answers = [
              'yes'         => ['label' => 'Yes',         'value' => 'yours'],
              'no'          => ['label' => 'No',          'value' => 'not_yours'],
              'investigate' => ['label' => 'Investigate', 'value' => 'investigate'],
            ];
          ?>
          <li class="dm-domain-row" data-domain-row data-domain-id="<?= (int)$domain['id'] ?>" data-confirmation="<?= $e($confirmation) ?>">
            <div class="dm-domain-copy">
              <strong class="dm-host"><?= $e($domain['host']) ?></strong>
              <span class="text-xs text-muted"><?= $e(dm_source_label($domain['source'])) ?></span>
            </div>
            <div class="dm-domain-controls">
              <span class="badge <?= $e(dm_confirmation_tone($confirmation)) ?>" data-domain-badge><?= $e(dm_confirmation_label($confirmation)) ?></span>
              <?php if ($can_edit): ?>
              <div class="dm-answer">
                <span class="dm-answer-label" aria-hidden="true">Is this yours?</span>
                <div class="dm-answer-group" role="group" aria-label="Is <?= $e($domain['host']) ?> yours?">
                  <?php foreach ($answers as $key => $answer): ?>
                  <button type="button" data-answer="<?= $e($answer['value']) ?>" aria-pressed="<?= $confirmation === $answer['value'] ? 'true' : 'false' ?>"><?= $e($answer['label']) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($can_edit): ?>
        <form class="dm-add-form" id="dm-add-form">
          <div class="dm-add-field">
            <label for="dm-add-url">Add a website</label>
            <input class="form-input" id="dm-add-url" name="url" type="text" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="example.com" required aria-describedby="dm-add-hint">
            <p class="form-hint" id="dm-add-hint">Type a web address, such as example.com.</p>
          </div>
          <button type="submit" class="btn btn-primary" id="dm-add-submit">Add</button>
        </form>
        <?php endif; ?>
      </section>

      <?php endif; ?>

    </div>
  </div>
</div>
<div id="toast-container" aria-live="polite"></div>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php if ($client !== null): ?>
<script src="<?= asset_url('assets/js/domain-map.js') ?>"></script>
<?php endif; ?>
</body>
</html>
