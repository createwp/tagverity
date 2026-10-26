(function () {
  'use strict';
  var root = document.getElementById('destination-connections-app');
  if (!root) return;
  var clientId = Number(root.dataset.clientId);
  var apiBase = String(root.dataset.apiBase || '').replace(/\/$/, '');
  var googleAuthUrl = String(root.dataset.googleAuthUrl || '');
  var googleAuthMessage = String(root.dataset.googleAuthMessage || '');
  var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
  var chevronMarkup = (document.getElementById('destination-chevron-icon') || {}).innerHTML || '';
  var googleConnection = {connected: false, accounts: []};

  function request(path, options) {
    return fetch(apiBase + '/' + path, options || {}).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok || body.error) throw new Error(body.message || 'Something went wrong.');
        return body.data;
      });
    });
  }

  function escapeHtml(value) {
    var element = document.createElement('div');
    element.textContent = String(value == null ? '' : value);
    return element.innerHTML;
  }

  function renderConnection(connection) {
    var statusLabel = {not_configured: 'Not configured', configured: 'Saved · verification pending', verified: 'Verified', error: 'Needs attention'}[connection.status] || connection.status;
    var fields = connection.fields.map(function (field) {
      var stored = field.secret && field.has_saved_value;
      var required = field.required && !stored ? ' required' : '';
      if (connection.platform === 'ga4' && field.key === 'measurement_id' && (googleConnection.ga4_streams || []).length) {
        field.options = {};
        googleConnection.ga4_streams.forEach(function (stream) {
          field.options[stream.measurement_id] = stream.display_name + (stream.stream_url ? ' · ' + stream.stream_url : '');
        });
      }
      var control;
      if (field.options) {
        var options = Object.keys(field.options).map(function (value) {
          return '<option value="' + escapeHtml(value) + '"' + (field.value === value ? ' selected' : '') + '>' + escapeHtml(field.options[value]) + '</option>';
        }).join('');
        control = '<select class="form-select" data-field="' + escapeHtml(field.key) + '"' + required + '><option value="">' + (field.key === 'measurement_id' ? 'Choose a website stream' : 'Choose a region') + '</option>' + options + '</select>';
      } else {
        control = '<input class="form-input" type="' + (field.secret ? 'password' : 'text') + '" data-field="' + escapeHtml(field.key) + '" value="' + escapeHtml(field.value) + '" placeholder="' + escapeHtml(stored ? 'Stored securely · enter only to replace' : field.placeholder) + '" autocomplete="off"' + required + '>';
      }
      return '<label class="destination-connection-field"><span>' + escapeHtml(field.label) + (field.required ? ' <em>Required</em>' : '') + '</span>' + control +
        '<small class="destination-field-help">' + escapeHtml(stored ? 'Saved securely. Leave blank to keep the current value.' : (field.help || '')) + '</small></label>';
    }).join('');
    var steps = (connection.setup_steps || []).map(function (step, index) { return '<li><span>' + (index + 1) + '</span><p>' + escapeHtml(step) + '</p></li>'; }).join('');
    var googleAuth = '';
    if (connection.auth_provider === 'google') {
      if (googleConnection.connected) {
        var accountName = (googleConnection.accounts[0] || {}).google_email || 'Google account';
        googleAuth = '<div class="destination-auth-state connected"><div><strong>Google connected</strong><p>' + escapeHtml(accountName) + ' can be used to discover available accounts.</p></div><a href="connections.php" class="btn btn-secondary btn-sm">Manage</a></div>';
      } else if (googleAuthUrl) {
        googleAuth = '<div class="destination-auth-state"><div><strong>Recommended: connect Google</strong><p>Sign in so TagVerity can find the accounts you already have access to.</p></div><a href="' + escapeHtml(googleAuthUrl) + '" class="btn btn-primary btn-sm">Connect Google</a></div>';
      } else {
        googleAuth = '<div class="destination-auth-state unavailable"><div><strong>Google sign-in is not ready</strong><p>' + escapeHtml(googleAuthMessage || 'An administrator needs to configure Google sign-in.') + ' You can still enter the details below.</p></div></div>';
      }
    } else {
      googleAuth = '<div class="destination-auth-state guided"><div><strong>Open your ' + escapeHtml(connection.label) + ' account</strong><p>We will guide you to the exact settings. Secure one-click connection is coming; for now, copy the details shown below.</p></div><a href="' + escapeHtml(connection.account_url) + '" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">' + escapeHtml(connection.account_label) + '</a></div>';
    }
    return '<details class="card destination-connection-card" data-platform="' + escapeHtml(connection.platform) + '">' +
      '<summary><span class="destination-platform-mark">' + escapeHtml(connection.label.substring(0, 2).toUpperCase()) + '</span><div><h2>' + escapeHtml(connection.label) + '</h2><p>' + escapeHtml(connection.summary) + '</p></div><span class="destination-status ' + escapeHtml(connection.status) + '">' + escapeHtml(statusLabel) + '</span><span class="destination-accordion-chevron">' + chevronMarkup + '</span></summary>' +
      '<div class="destination-accordion-body"><section class="destination-setup-guide"><span class="onboarding-eyebrow">Guided setup</span><h3>What you need</h3><ol>' + steps + '</ol><div class="destination-help-links"><a href="' + escapeHtml(connection.docs_url) + '" target="_blank" rel="noopener">' + escapeHtml(connection.docs_label) + '</a><a href="' + escapeHtml(connection.account_url) + '" target="_blank" rel="noopener">' + escapeHtml(connection.account_label) + '</a></div></section>' +
      '<div class="destination-setup-form">' + googleAuth + '<form data-platform="' + escapeHtml(connection.platform) + '"><div class="destination-connection-fields">' + fields + '</div>' +
      '<footer><p>' + (connection.status === 'configured' ? 'Saved. TagVerity will verify it during release testing.' : 'Saving only prepares this destination. Nothing is sent yet.') + '</p><button class="btn btn-primary" type="submit">Save ' + escapeHtml(connection.label) + '</button></footer></form></div></div></details>';
  }

  function render(connections) {
    document.getElementById('destination-connection-grid').innerHTML = connections.map(renderConnection).join('');
    var cards = Array.from(document.querySelectorAll('.destination-connection-card'));
    var firstIncomplete = cards.find(function (card) {
      var status = card.querySelector('.destination-status');
      return status && status.classList.contains('not_configured');
    });
    (firstIncomplete || cards[0] || {}).open = true;
  }

  document.getElementById('destination-connection-grid').addEventListener('submit', function (event) {
    var form = event.target.closest('.destination-connection-card form');
    if (!form) return;
    event.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    var buttonLabel = button.textContent;
    var fields = {};
    form.querySelectorAll('[data-field]').forEach(function (input) { fields[input.dataset.field] = input.value; });
    button.disabled = true; button.textContent = 'Saving…';
    request('api/save-destination-connection.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({client_id: clientId, platform: form.dataset.platform, fields: fields, csrf_token: csrfToken})
    }).then(function () {
      showToast('Destination connection saved.', 'success');
      return load();
    }).catch(function (error) { showToast(error.message, 'error'); }).finally(function () { button.disabled = false; button.textContent = buttonLabel; });
  });

  function load() {
    return request('api/get-destination-connections.php?client_id=' + encodeURIComponent(clientId)).then(function (data) {
      googleConnection = data.google_connection || googleConnection;
      render(data.connections || []);
    });
  }

  document.getElementById('destination-connection-grid').addEventListener('toggle', function (event) {
    var opened = event.target.closest('.destination-connection-card');
    if (!opened || !opened.open) return;
    document.querySelectorAll('.destination-connection-card[open]').forEach(function (card) {
      if (card !== opened) card.open = false;
    });
  }, true);

  load().catch(function (error) { showToast(error.message, 'error'); });
})();
