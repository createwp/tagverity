/* ─── Connections — Google logins, resource picker and audit targets ─────────
   Endpoints: get-google-resources, link-google-resources, unlink-google-resource,
   disconnect-google, save-audit-target. All API data is written with textContent.
   ─────────────────────────────────────────────────────────────────────────── */

'use strict';

(function () {
  var root = document.getElementById('connections-app');
  if (!root) return;

  var clientId = Number(root.dataset.clientId);
  var apiBase = String(root.dataset.apiBase || '').replace(/\/$/, '');
  var csrfInput = root.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';
  var dialog = document.getElementById('resource-picker');
  var autoPickConnection = Number(root.dataset.autoPick || 0);

  // Last saved audit targets, so a failed save can restore the radio.
  var savedTargets = {
    ga4: checkedTarget('ga4_audit_target'),
    gtm: checkedTarget('gtm_audit_target')
  };

  var picker = {
    connectionId: 0,
    email: '',
    opener: null,
    data: null,
    requestSeq: 0,
    submitting: false,
    idCounter: 0
  };

  // ─── Helpers ──────────────────────────────────────────────────────────────

  function checkedTarget(name) {
    var checked = root.querySelector('input[name="' + name + '"]:checked');
    return checked ? Number(checked.value) : null;
  }

  function statusCopy(code) {
    switch (code) {
      case 'authentication_failed':
        return 'Your Google connection has expired. Reconnect Google.';
      case 'permission_denied':
        return "This Google login doesn't have access.";
      case 'quota_limited':
        return 'Google asked us to slow down. Try again in a minute.';
      default:
        return "We couldn't load this from Google. Try again.";
    }
  }

  function postJson(path, payload) {
    return fetch(apiBase + '/api/' + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        return {ok: response.ok, body: body};
      });
    });
  }

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function nextId(prefix) {
    picker.idCounter += 1;
    return 'resource-picker-' + prefix + '-' + picker.idCounter;
  }

  function reloadWith(extra) {
    var url = new URL(window.location.href);
    url.searchParams.delete('pick');
    url.searchParams.delete('connection_id');
    url.searchParams.delete('linked');
    Object.keys(extra || {}).forEach(function (key) { url.searchParams.set(key, extra[key]); });
    window.location.href = url.pathname + url.search;
  }

  // ─── Linked resources: unlink, disconnect, audit targets ─────────────────

  root.addEventListener('click', function (event) {
    var unlink = event.target.closest('[data-unlink-type]');
    if (unlink) {
      unlinkResource(unlink);
      return;
    }
    var disconnect = event.target.closest('[data-disconnect-connection]');
    if (disconnect) {
      disconnectLogin(disconnect);
      return;
    }
    var link = event.target.closest('[data-link-connection]');
    if (link) {
      openPicker(Number(link.dataset.linkConnection), link.dataset.connectionEmail || '', link);
    }
  });

  root.addEventListener('change', function (event) {
    var input = event.target;
    if (input.name === 'ga4_audit_target' || input.name === 'gtm_audit_target') {
      saveAuditTarget(input);
    }
  });

  function unlinkResource(button) {
    var type = button.dataset.unlinkType;
    var id = Number(button.dataset.unlinkId);
    var name = button.dataset.unlinkName || 'this resource';
    var message = type === 'ga4'
      ? 'Audits will stop using ' + name + ' for this client. Any BigQuery connection and domain link for this property are removed too.'
      : 'Audits will stop using ' + name + ' for this client. You can link it again later.';

    window.showConfirm({
      title: 'Unlink ' + name + '?',
      message: message,
      confirmLabel: 'Unlink',
      destructive: true
    }).then(function (confirmed) {
      if (!confirmed) return;
      postJson('unlink-google-resource.php', {
        csrf_token: csrfToken,
        client_id: clientId,
        type: type,
        id: id
      }).then(function (result) {
        if (!result.ok || result.body.error) {
          showToast("Couldn't unlink " + name + '. Try again.', 'error');
          return;
        }
        reloadWith({});
      }).catch(function () {
        showToast("Couldn't unlink " + name + '. Try again.', 'error');
      });
    });
  }

  function disconnectLogin(button) {
    var connectionId = Number(button.dataset.disconnectConnection);
    var email = button.dataset.connectionEmail || 'this Google account';

    window.showConfirm({
      title: 'Disconnect ' + email + '?',
      message: 'Every GA4 property and GTM container linked through this login will be unlinked from all of your clients. Audits that use them will stop.',
      confirmLabel: 'Disconnect',
      destructive: true
    }).then(function (confirmed) {
      if (!confirmed) return;
      postJson('disconnect-google.php', {
        csrf_token: csrfToken,
        connection_id: connectionId
      }).then(function (result) {
        if (!result.ok || result.body.error) {
          showToast("Couldn't disconnect " + email + '. Try again.', 'error');
          return;
        }
        reloadWith({});
      }).catch(function () {
        showToast("Couldn't disconnect " + email + '. Try again.', 'error');
      });
    });
  }

  function saveAuditTarget(input) {
    var type = input.name === 'ga4_audit_target' ? 'ga4' : 'gtm';
    var group = type === 'ga4' ? 'ga4_audit_target' : 'gtm_audit_target';
    var ga4Target = type === 'ga4' ? Number(input.value) : checkedTarget('ga4_audit_target');
    var gtmTarget = type === 'gtm' ? Number(input.value) : checkedTarget('gtm_audit_target');

    // Radios save one type at a time, so both current selections are always sent.
    postJson('save-audit-target.php', {
      csrf_token: csrfToken,
      client_id: clientId,
      gtm_container_id: gtmTarget,
      ga4_property_id: ga4Target
    }).then(function (result) {
      if (!result.ok || result.body.error) {
        throw new Error(result.body.error || 'target_save_failed');
      }
      savedTargets[type] = Number(input.value);
      var hint = root.querySelector('[data-target-hint="' + type + '"]');
      if (hint) hint.hidden = true;
      showToast('Audit target saved.', 'success');
    }).catch(function () {
      restoreTargetRadios(group, savedTargets[type]);
      showToast("Couldn't save the audit target. Try again.", 'error');
    });
  }

  function restoreTargetRadios(group, value) {
    root.querySelectorAll('input[name="' + group + '"]').forEach(function (radio) {
      radio.checked = value !== null && Number(radio.value) === value;
    });
  }

  // ─── Resource picker ─────────────────────────────────────────────────────

  var pickerBody = document.getElementById('resource-picker-body');
  var pickerSearch = document.getElementById('resource-picker-search');
  var pickerCount = document.getElementById('resource-picker-count');
  var pickerError = document.getElementById('resource-picker-error');
  var pickerLink = document.getElementById('resource-picker-link');
  var pickerTitleLogin = document.getElementById('resource-picker-login');

  if (dialog) {
    document.getElementById('resource-picker-close').addEventListener('click', function () { dialog.close(); });
    document.getElementById('resource-picker-cancel').addEventListener('click', function () { dialog.close(); });
    pickerSearch.addEventListener('input', applySearch);
    pickerLink.addEventListener('click', submitLink);

    // Backdrop click: the dialog element itself is the target when the backdrop is clicked.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });

    // Keep Tab inside the dialog (focus trap).
    dialog.addEventListener('keydown', function (event) {
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(dialog.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])'
      )).filter(function (node) { return node.offsetParent !== null; });
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    dialog.addEventListener('close', function () {
      picker.requestSeq += 1; // ignore any load still in flight
      document.body.classList.remove('connections-picker-open');
      var opener = picker.opener;
      picker.opener = null;
      if (opener && document.body.contains(opener)) opener.focus();
    });
  }

  function openPicker(connectionId, email, opener) {
    if (!dialog || !connectionId) return;
    picker.connectionId = connectionId;
    picker.email = email;
    picker.opener = opener || null;
    picker.data = null;
    pickerSearch.value = '';
    pickerTitleLogin.textContent = 'Choose what this client should use from ' + (email || 'this Google account') + '.';
    setPickerError('');
    showLoading('Finding your Google properties…');
    updateFooter();

    if (!dialog.open) {
      dialog.showModal();
      document.body.classList.add('connections-picker-open');
    }
    pickerSearch.focus();
    loadResources();
  }

  function showLoading(message) {
    pickerBody.replaceChildren(element('p', 'picker-state', message));
  }

  function loadResources() {
    var seq = picker.requestSeq + 1;
    picker.requestSeq = seq;
    var url = apiBase + '/api/get-google-resources.php?client_id=' + encodeURIComponent(clientId) +
      '&connection_id=' + encodeURIComponent(picker.connectionId);

    fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function (response) {
        return response.json().catch(function () { return {}; }).then(function (body) {
          return {ok: response.ok, status: response.status, body: body};
        });
      })
      .then(function (result) {
        if (seq !== picker.requestSeq) return;
        if (result.status === 403) {
          showLoading("You don't have permission to link Google resources for this client.");
          return;
        }
        if (!result.ok || result.body.error) {
          showLoading(statusCopy(result.body.error || 'unknown'));
          return;
        }
        picker.data = result.body;
        renderPicker();
      })
      .catch(function () {
        if (seq !== picker.requestSeq) return;
        showLoading(statusCopy('unknown'));
      });
  }

  function renderPicker() {
    var data = picker.data || {};
    var fragment = document.createDocumentFragment();
    fragment.appendChild(renderSection('ga4', 'Google Analytics 4', data.ga4_accounts || [], (data.errors || {}).ga4, 'GA4 properties'));
    fragment.appendChild(renderSection('gtm', 'Google Tag Manager', data.gtm_accounts || [], (data.errors || {}).gtm, 'GTM containers'));
    pickerBody.replaceChildren(fragment);
    applySearch();
    syncAll();
  }

  function renderSection(kind, heading, accounts, error, noun) {
    var section = element('section', 'picker-section');
    var headingId = nextId(kind + '-heading');
    var title = element('h3', 'picker-section-title', heading);
    title.id = headingId;
    section.setAttribute('aria-labelledby', headingId);
    section.appendChild(title);

    if (error) {
      var failure = element('div', 'picker-side-error');
      failure.setAttribute('role', 'status');
      failure.appendChild(element('p', 'picker-side-error-copy', statusCopy(error)));
      failure.appendChild(element('code', 'picker-status-code', error));
      section.appendChild(failure);
      return section;
    }

    if (accounts.length === 0) {
      section.appendChild(element('p', 'picker-empty', kind === 'ga4'
        ? "This Google login can't see any GA4 properties."
        : "This Google login can't see any GTM containers."));
      return section;
    }

    accounts.forEach(function (account) {
      section.appendChild(renderAccount(kind, account, noun));
    });
    return section;
  }

  function renderAccount(kind, account, noun) {
    var group = element('div', 'picker-account');
    var items = kind === 'ga4' ? (account.properties || []) : (account.containers || []);
    var accountName = account.account_name || 'Unnamed account';

    var head = element('div', 'picker-account-head');
    var selectAllId = nextId(kind + '-all');
    var selectAll = element('input');
    selectAll.type = 'checkbox';
    selectAll.id = selectAllId;
    selectAll.className = 'picker-select-all';
    var selectAllLabel = element('label', 'picker-account-label');
    selectAllLabel.htmlFor = selectAllId;
    selectAllLabel.appendChild(element('span', 'picker-account-name', accountName));
    selectAllLabel.appendChild(element('span', 'picker-account-meta', 'Select all ' + noun + ' in this account'));
    head.append(selectAll, selectAllLabel);
    group.appendChild(head);

    var list = element('div', 'picker-items');
    items.forEach(function (item) {
      list.appendChild(renderItem(kind, item, accountName));
    });
    group.appendChild(list);

    selectAll.addEventListener('change', function () {
      group.querySelectorAll('input.picker-item-check:not(:disabled)').forEach(function (box) {
        box.checked = selectAll.checked;
      });
      syncAll();
    });

    group.dataset.kind = kind;
    return group;
  }

  function renderItem(kind, item, accountName) {
    var linked = Boolean(item.linked);
    var id = kind === 'ga4' ? String(item.property_id) : String(item.container_id);
    var name = kind === 'ga4'
      ? (item.display_name || 'Untitled property')
      : (item.name || 'Untitled container');
    var meta = kind === 'ga4'
      ? 'Property ID ' + id
      : (item.public_id ? item.public_id + ' · ' : '') + 'Container ID ' + id;

    var row = element('div', 'picker-item');
    row.dataset.search = [name, id, item.public_id || '', accountName].join(' ').toLowerCase();

    var checkId = nextId(kind + '-item');
    var box = element('input', 'picker-item-check');
    box.type = 'checkbox';
    box.id = checkId;
    box.value = id;
    box.dataset.kind = kind;
    box.dataset.id = id;
    if (linked) {
      box.checked = true;
      box.disabled = true;
    }
    box.addEventListener('change', syncAll);

    var label = element('label', 'picker-item-label');
    label.htmlFor = checkId;
    label.appendChild(element('span', 'picker-item-name', name));
    label.appendChild(element('span', 'picker-item-meta', meta));

    row.append(box, label);
    if (linked) {
      row.appendChild(element('span', 'badge badge-success picker-linked', 'Linked'));
    }
    return row;
  }

  function applySearch() {
    var query = pickerSearch.value.trim().toLowerCase();
    var visibleTotal = 0;
    pickerBody.querySelectorAll('.picker-account').forEach(function (group) {
      var visible = 0;
      group.querySelectorAll('.picker-item').forEach(function (row) {
        var match = query === '' || row.dataset.search.indexOf(query) !== -1;
        row.hidden = !match;
        if (match) visible += 1;
      });
      group.hidden = visible === 0;
      visibleTotal += visible;
    });
    var noMatch = pickerBody.querySelector('.picker-no-match');
    if (query !== '' && visibleTotal === 0 && picker.data) {
      if (!noMatch) {
        noMatch = element('p', 'picker-empty picker-no-match', 'Nothing matches “' + pickerSearch.value.trim() + '”.');
        pickerBody.appendChild(noMatch);
      }
    } else if (noMatch) {
      noMatch.remove();
    }
  }

  // Keeps every account's select-all checkbox in step with its items.
  function syncAll() {
    pickerBody.querySelectorAll('.picker-account').forEach(function (group) {
      var selectAll = group.querySelector('.picker-select-all');
      var boxes = Array.prototype.slice.call(group.querySelectorAll('input.picker-item-check:not(:disabled)'));
      var checked = boxes.filter(function (box) { return box.checked; }).length;
      selectAll.disabled = boxes.length === 0;
      selectAll.checked = boxes.length > 0 && checked === boxes.length;
      selectAll.indeterminate = checked > 0 && checked < boxes.length;
    });
    updateFooter();
  }

  function selectedIds(kind) {
    return Array.prototype.slice.call(pickerBody.querySelectorAll('input.picker-item-check:not(:disabled)'))
      .filter(function (box) { return box.dataset.kind === kind && box.checked; })
      .map(function (box) { return String(box.dataset.id); });
  }

  function updateFooter() {
    var ga4 = selectedIds('ga4');
    var gtm = selectedIds('gtm');
    var total = ga4.length + gtm.length;
    if (total === 0) {
      pickerCount.textContent = 'No items selected';
    } else {
      pickerCount.textContent = total + ' selected';
    }
    pickerLink.disabled = total === 0 || picker.submitting;
  }

  function setPickerError(message) {
    if (!message) {
      pickerError.hidden = true;
      pickerError.textContent = '';
      return;
    }
    pickerError.textContent = message;
    pickerError.hidden = false;
  }

  function linkErrorCopy(error) {
    if (error === 'not_accessible') {
      return 'Some of these are no longer visible to this Google login. Refresh the list and try again.';
    }
    if (error === 'foreign_target') {
      return 'Some of these belong to another client. Refresh the list and try again.';
    }
    return statusCopy(error);
  }

  function submitLink() {
    if (picker.submitting) return;
    var ga4 = selectedIds('ga4');
    var gtm = selectedIds('gtm');
    if (ga4.length + gtm.length === 0) return;

    picker.submitting = true;
    pickerLink.setAttribute('aria-busy', 'true');
    pickerLink.disabled = true;
    setPickerError('');

    // Both arrays are always sent, even when empty.
    postJson('link-google-resources.php', {
      csrf_token: csrfToken,
      client_id: clientId,
      connection_id: picker.connectionId,
      ga4_property_ids: ga4,
      gtm_container_ids: gtm
    }).then(function (result) {
      picker.submitting = false;
      pickerLink.removeAttribute('aria-busy');
      if (!result.ok || result.body.error) {
        setPickerError(linkErrorCopy(result.body.error || 'unknown'));
        updateFooter();
        return;
      }
      var linked = Number(result.body.ga4_linked || 0) + Number(result.body.gtm_linked || 0);
      dialog.close();
      reloadWith({linked: linked});
    }).catch(function () {
      picker.submitting = false;
      pickerLink.removeAttribute('aria-busy');
      setPickerError(statusCopy('unknown'));
      updateFooter();
    });
  }

  // ─── Open the picker straight away when arriving from the OAuth callback ───
  if (autoPickConnection > 0) {
    var url = new URL(window.location.href);
    url.searchParams.delete('pick');
    url.searchParams.delete('connection_id');
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);

    var autoButton = root.querySelector('[data-link-connection="' + autoPickConnection + '"]');
    if (autoButton) {
      openPicker(autoPickConnection, autoButton.dataset.connectionEmail || '', null);
    }
  }
})();
