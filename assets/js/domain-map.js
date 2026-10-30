/* ─── Domain map — ownership answers and adding a website ──────────────────
   Endpoints: confirm-domain, add-domain. A saved answer updates the row badge,
   aria-pressed and the diagram node in place. API data is written with textContent only.
   ─────────────────────────────────────────────────────────────────────────── */

'use strict';

(function () {
  var root = document.getElementById('domain-map-app');
  if (!root) return;

  var clientId = Number(root.dataset.clientId);
  var apiBase = String(root.dataset.apiBase || '').replace(/\/$/, '');
  var csrfInput = root.querySelector('input[name="csrf_token"]');
  var csrfToken = csrfInput ? csrfInput.value : '';
  var svg = root.querySelector('svg.dm-svg');
  var addForm = document.getElementById('dm-add-form');

  var CONFIRMATION_CLASSES = ['dm-yours', 'dm-unconfirmed', 'dm-not_yours', 'dm-investigate'];
  var BADGE_TONES = ['badge-success', 'badge-critical', 'badge-warning'];
  var BADGES = {
    yours:       {text: 'Yours',         tone: 'badge-success'},
    not_yours:   {text: 'Not yours',     tone: 'badge-critical'},
    investigate: {text: 'Investigating', tone: 'badge-critical'},
    unconfirmed: {text: 'Not confirmed', tone: 'badge-warning'}
  };

  // ─── Helpers ──────────────────────────────────────────────────────────────

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

  function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : many);
  }

  function setGroupDisabled(group, disabled) {
    group.querySelectorAll('button').forEach(function (button) {
      button.disabled = disabled;
    });
  }

  // Keeps the diagram's aria-label in step with the answers on screen.
  function updateSummary() {
    if (!svg) return;
    var yours = 0;
    var unconfirmed = 0;
    var flagged = 0;
    var domains = 0;
    root.querySelectorAll('[data-domain-row]').forEach(function (row) {
      domains++;
      var confirmation = row.dataset.confirmation;
      if (confirmation === 'yours') {
        yours++;
      } else if (confirmation === 'unconfirmed') {
        unconfirmed++;
      } else {
        flagged++;
      }
    });

    var text = 'Domain map: ' +
      plural(Number(svg.dataset.containerCount || 0), 'GTM container', 'GTM containers') + ', ' +
      plural(Number(svg.dataset.propertyCount || 0), 'GA4 property', 'GA4 properties') + ', ' +
      plural(domains, 'domain', 'domains');

    var parts = [];
    if (yours > 0) parts.push(yours + ' confirmed yours');
    if (unconfirmed > 0) parts.push(unconfirmed + ' unconfirmed');
    if (flagged > 0) parts.push(flagged + ' flagged');
    if (parts.length) text += ' (' + parts.join(', ') + ')';

    svg.setAttribute('aria-label', text);
  }

  function updateNode(domainId, confirmation) {
    if (!svg) return;
    var node = svg.querySelector('g[data-domain-id="' + domainId + '"]');
    if (!node) return;
    CONFIRMATION_CLASSES.forEach(function (name) {
      node.classList.remove(name);
    });
    node.classList.add('dm-' + confirmation);
  }

  function applyConfirmation(row, confirmation) {
    var meta = BADGES[confirmation] || BADGES.unconfirmed;
    row.dataset.confirmation = confirmation;

    var badge = row.querySelector('[data-domain-badge]');
    if (badge) {
      badge.textContent = meta.text;
      BADGE_TONES.forEach(function (tone) {
        badge.classList.remove(tone);
      });
      badge.classList.add(meta.tone);
    }

    row.querySelectorAll('button[data-answer]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.dataset.answer === confirmation ? 'true' : 'false');
    });

    updateNode(row.dataset.domainId, confirmation);
    updateSummary();
  }

  // ─── Ownership answers ────────────────────────────────────────────────────

  root.addEventListener('click', function (event) {
    var button = event.target.closest('button[data-answer]');
    if (!button || !root.contains(button)) return;

    var row = button.closest('[data-domain-row]');
    var group = button.closest('.dm-answer-group');
    if (!row || !group) return;
    if (button.getAttribute('aria-pressed') === 'true') return;
    if (group.getAttribute('aria-busy') === 'true') return;

    group.setAttribute('aria-busy', 'true');
    setGroupDisabled(group, true);

    postJson('confirm-domain.php', {
      csrf_token: csrfToken,
      client_id: clientId,
      domain_id: Number(row.dataset.domainId),
      answer: button.dataset.answer
    }).then(function (result) {
      if (!result.ok || result.body.error || !result.body.domain) {
        showToast(result.body.message || "Couldn't save that answer. Try again.", 'error');
        return;
      }
      applyConfirmation(row, result.body.domain.confirmation);
    }).catch(function () {
      showToast("Couldn't save that answer. Try again.", 'error');
    }).then(function () {
      group.removeAttribute('aria-busy');
      setGroupDisabled(group, false);
    });
  });

  // ─── Add a website ────────────────────────────────────────────────────────

  if (addForm) {
    addForm.addEventListener('submit', function (event) {
      event.preventDefault();

      var input = document.getElementById('dm-add-url');
      var submit = document.getElementById('dm-add-submit');
      var url = input ? input.value.trim() : '';
      if (url === '' || !submit || submit.disabled) {
        if (input) input.focus();
        return;
      }

      submit.disabled = true;
      submit.setAttribute('aria-busy', 'true');

      function fail(message) {
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        showToast(message, 'error');
      }

      postJson('add-domain.php', {
        csrf_token: csrfToken,
        client_id: clientId,
        url: url
      }).then(function (result) {
        if (!result.ok || result.body.error) {
          fail(result.body.message || "Couldn't add that website. Try again.");
          return;
        }
        // The diagram is server-rendered, so a reload redraws it with the new domain.
        window.location.reload();
      }).catch(function () {
        fail("Couldn't add that website. Try again.");
      });
    });
  }
})();
