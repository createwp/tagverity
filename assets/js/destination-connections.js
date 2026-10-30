(function () {
  'use strict';
  var root = document.getElementById('destination-connections-app');
  if (!root) return;
  var clientId = Number(root.dataset.clientId);
  var apiBase = String(root.dataset.apiBase || '').replace(/\/$/, '');
  var googleAuthUrl = String(root.dataset.googleAuthUrl || '');
  var googleAuthMessage = String(root.dataset.googleAuthMessage || '');
  var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
  var grid = document.getElementById('destination-connection-grid');
  var dialog = document.getElementById('destination-dialog');
  var dialogBody = document.getElementById('destination-dialog-body');
  var dialogMark = document.getElementById('destination-dialog-mark');
  var dialogTitle = document.getElementById('destination-dialog-title');
  var dialogStatus = document.getElementById('destination-dialog-status');
  var dialogSummary = document.getElementById('destination-dialog-summary');
  var dialogClose = dialog.querySelector('[data-dialog-close]');
  var monograms = {ga4: 'GA', posthog: 'PH', meta: 'M', google_ads: 'GAds'};
  var connections = [];
  var googleConnection = {connected: false, accounts: []};

  function request(path, options) {
    return fetch(apiBase + '/' + path, options || {}).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok || body.error) throw new Error(body.message || 'Something went wrong.');
        return body.data;
      });
    });
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  }

  function link(href, text, className, external) {
    var anchor = el('a', className, text);
    anchor.setAttribute('href', href);
    if (external) {
      anchor.target = '_blank';
      anchor.rel = 'noopener';
    }
    return anchor;
  }

  function statusFor(connection) {
    if (connection.status === 'configured' || connection.status === 'verified') return {key: 'connected', label: 'Connected'};
    if (connection.status === 'error') return {key: 'error', label: 'Needs attention'};
    return {key: 'idle', label: 'Not connected'};
  }

  function monogramFor(connection) {
    return monograms[connection.platform] || connection.label.substring(0, 2).toUpperCase();
  }

  function findConnection(platform) {
    return connections.filter(function (connection) { return connection.platform === platform; })[0] || null;
  }

  function buildTile(connection, startHere) {
    var status = statusFor(connection);
    var summaryId = 'destination-summary-' + connection.platform;
    var tile = el('button', 'destination-tile');
    tile.type = 'button';
    tile.dataset.platform = connection.platform;
    tile.setAttribute('aria-haspopup', 'dialog');
    tile.setAttribute('aria-label', connection.label + ' — ' + status.label);
    tile.setAttribute('aria-describedby', summaryId);

    var top = el('span', 'destination-tile-top');
    var mark = el('span', 'destination-mark destination-mark-' + connection.platform, monogramFor(connection));
    mark.setAttribute('aria-hidden', 'true');
    top.appendChild(mark);
    top.appendChild(el('span', 'destination-status ' + status.key, status.label));
    tile.appendChild(top);

    var body = el('span', 'destination-tile-body');
    if (startHere) body.appendChild(el('span', 'destination-tile-eyebrow', 'Start here'));
    body.appendChild(el('span', 'destination-tile-label', connection.label));
    var summary = el('span', 'destination-tile-summary', connection.summary);
    summary.id = summaryId;
    body.appendChild(summary);
    tile.appendChild(body);
    return tile;
  }

  function render(list) {
    connections = list;
    var startPlatform = null;
    list.some(function (connection) {
      if (statusFor(connection).key === 'connected') return false;
      startPlatform = connection.platform;
      return true;
    });
    grid.textContent = '';
    list.forEach(function (connection) {
      grid.appendChild(buildTile(connection, connection.platform === startPlatform));
    });
  }

  function focusTile(platform) {
    var tile = Array.prototype.slice.call(grid.querySelectorAll('.destination-tile')).filter(function (node) {
      return node.dataset.platform === platform;
    })[0];
    if (tile) tile.focus();
  }

  function setDialogIdentity(connection) {
    var status = statusFor(connection);
    dialogMark.className = 'destination-mark destination-mark-' + connection.platform;
    dialogMark.textContent = monogramFor(connection);
    dialogTitle.textContent = connection.label;
    dialogStatus.className = 'destination-status ' + status.key;
    dialogStatus.textContent = status.label;
    dialogSummary.textContent = connection.summary;
  }

  function buildAuthState(connection) {
    var box = el('div', 'destination-auth');
    var copy = el('div');
    if (connection.auth_provider === 'google') {
      if (googleConnection.connected) {
        var accountName = (googleConnection.accounts[0] || {}).google_email || 'Google account';
        box.classList.add('connected');
        copy.appendChild(el('strong', null, 'Google connected'));
        copy.appendChild(el('p', null, accountName + ' can be used to discover available accounts.'));
        box.appendChild(copy);
        box.appendChild(link('connections.php', 'Manage', 'btn btn-secondary btn-sm', false));
      } else if (googleAuthUrl) {
        copy.appendChild(el('strong', null, 'Recommended: connect Google'));
        copy.appendChild(el('p', null, 'Sign in so TagVerity can find the accounts you already have access to.'));
        box.appendChild(copy);
        box.appendChild(link(googleAuthUrl, 'Connect Google', 'btn btn-primary btn-sm', false));
      } else {
        box.classList.add('unavailable');
        copy.appendChild(el('strong', null, 'Google sign-in is not ready'));
        copy.appendChild(el('p', null, (googleAuthMessage || 'An administrator needs to configure Google sign-in.') + ' You can still enter the details below.'));
        box.appendChild(copy);
      }
    } else {
      box.classList.add('guided');
      copy.appendChild(el('strong', null, 'Open your ' + connection.label + ' account'));
      copy.appendChild(el('p', null, 'We will guide you to the exact settings. Secure one-click connection is coming; for now, copy the details shown below.'));
      box.appendChild(copy);
      box.appendChild(link(connection.account_url, connection.account_label, 'btn btn-secondary btn-sm', true));
    }
    return box;
  }

  function buildGuide(connection) {
    var guide = el('section', 'destination-guide');
    guide.appendChild(el('span', 'onboarding-eyebrow', 'Guided setup'));
    guide.appendChild(el('h3', null, 'What you need'));
    var steps = el('ol', 'destination-guide-steps');
    (connection.setup_steps || []).forEach(function (step, index) {
      var item = el('li');
      item.appendChild(el('span', null, String(index + 1)));
      item.appendChild(el('p', null, step));
      steps.appendChild(item);
    });
    guide.appendChild(steps);
    var links = el('div', 'destination-guide-links');
    links.appendChild(link(connection.docs_url, connection.docs_label, null, true));
    links.appendChild(link(connection.account_url, connection.account_label, null, true));
    guide.appendChild(links);
    return guide;
  }

  // Google Analytics streams discovered through the Google connection replace the manual stream ID input.
  function fieldOptions(connection, field) {
    if (field.options) {
      return Object.keys(field.options).map(function (value) { return [value, field.options[value]]; });
    }
    if (connection.platform === 'ga4' && field.key === 'measurement_id' && (googleConnection.ga4_streams || []).length) {
      return googleConnection.ga4_streams.map(function (stream) {
        return [stream.measurement_id, stream.display_name + (stream.stream_url ? ' · ' + stream.stream_url : '')];
      });
    }
    return null;
  }

  function buildField(connection, field) {
    var stored = Boolean(field.secret && field.has_saved_value);
    var required = Boolean(field.required && !stored);
    var label = el('label', 'destination-field');
    var caption = el('span', 'destination-field-label', field.label);
    if (field.required) {
      caption.appendChild(document.createTextNode(' '));
      caption.appendChild(el('em', null, 'Required'));
    }
    label.appendChild(caption);

    var options = fieldOptions(connection, field);
    var control;
    if (options) {
      control = el('select', 'form-select');
      var placeholder = el('option', null, field.key === 'measurement_id' ? 'Choose a website stream' : 'Choose a region');
      placeholder.value = '';
      control.appendChild(placeholder);
      options.forEach(function (pair) {
        var option = el('option', null, pair[1]);
        option.value = pair[0];
        if (field.value === pair[0]) option.selected = true;
        control.appendChild(option);
      });
    } else {
      control = el('input', 'form-input');
      control.type = field.secret ? 'password' : 'text';
      // Saved secrets are never sent back to the page, so secret inputs always start empty.
      control.value = field.secret ? '' : (field.value || '');
      control.placeholder = stored ? 'Stored securely · enter only to replace' : (field.placeholder || '');
      control.setAttribute('autocomplete', 'off');
    }
    control.dataset.field = field.key;
    if (required) control.required = true;
    label.appendChild(control);
    label.appendChild(el('small', 'destination-field-hint', stored ? 'Saved securely. Leave blank to keep the current value.' : (field.help || '')));
    return label;
  }

  function buildForm(connection) {
    var form = el('form');
    form.dataset.platform = connection.platform;
    var fields = el('div', 'destination-fields');
    connection.fields.forEach(function (field) { fields.appendChild(buildField(connection, field)); });
    form.appendChild(fields);

    var footer = el('footer', 'destination-form-footer');
    footer.appendChild(el('p', null, connection.status === 'configured'
      ? 'Saved. TagVerity will verify it during release testing.'
      : 'Saving only prepares this destination. Nothing is sent yet.'));
    var submit = el('button', 'btn btn-primary', 'Save ' + connection.label);
    submit.type = 'submit';
    footer.appendChild(submit);
    form.appendChild(footer);
    return form;
  }

  function renderDialog(connection) {
    setDialogIdentity(connection);
    dialogBody.textContent = '';
    dialogBody.appendChild(buildAuthState(connection));
    dialogBody.appendChild(buildGuide(connection));
    dialogBody.appendChild(buildForm(connection));
  }

  function openDestination(platform, tile) {
    var connection = findConnection(platform);
    if (!connection) return;
    renderDialog(connection);
    var firstField = dialogBody.querySelector('[data-field]');
    TagVerityUI.openDialog(dialog, tile, firstField);
  }

  grid.addEventListener('click', function (event) {
    var tile = event.target.closest('.destination-tile');
    if (!tile || !grid.contains(tile)) return;
    openDestination(tile.dataset.platform, tile);
  });

  dialogClose.addEventListener('click', function () {
    TagVerityUI.closeDialog(dialog);
  });

  dialogBody.addEventListener('submit', function (event) {
    var form = event.target;
    event.preventDefault();
    var platform = form.dataset.platform;
    var button = form.querySelector('button[type="submit"]');
    var buttonLabel = button.textContent;
    var fields = {};
    form.querySelectorAll('[data-field]').forEach(function (input) { fields[input.dataset.field] = input.value; });
    button.disabled = true;
    button.textContent = 'Saving…';
    request('api/save-destination-connection.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({client_id: clientId, platform: platform, fields: fields, csrf_token: csrfToken})
    }).then(function () {
      showToast('Destination connection saved.', 'success');
      return load().then(function () {
        // The opener tile was re-rendered, so close first, then move focus to the saved platform's new tile.
        TagVerityUI.closeDialog(dialog);
        focusTile(platform);
      });
    }).catch(function (error) {
      showToast(error.message, 'error');
    }).finally(function () {
      button.disabled = false;
      button.textContent = buttonLabel;
    });
  });

  function load() {
    return request('api/get-destination-connections.php?client_id=' + encodeURIComponent(clientId)).then(function (data) {
      googleConnection = data.google_connection || googleConnection;
      render(data.connections || []);
    });
  }

  load().catch(function (error) { showToast(error.message, 'error'); });
})();
