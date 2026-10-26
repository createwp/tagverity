'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const app = document.getElementById('publish-app');
  if (!app) return;
  const state = {clientId: Number(app.dataset.clientId), apiBase: (app.dataset.apiBase || '').replace(/\/$/, ''), baseEvents: [], mappings: [], changes: {new: 0, modified: 0, total: 0, baseline_version: null}, readiness: null, releases: []};

  function endpoint(path) { return state.apiBase + '/' + path.replace(/^\//, ''); }
  function csrfToken() { return app.querySelector('input[name="csrf_token"]').value; }
  function node(tag, className, value) {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (value !== undefined && value !== null) item.textContent = String(value);
    return item;
  }
  function clear(target) { while (target.firstChild) target.removeChild(target.firstChild); }
  async function request(path, options) {
    const response = await fetch(endpoint(path), options);
    let payload;
    try { payload = await response.json(); } catch (error) { throw new Error('The server returned an unreadable response.'); }
    if (!response.ok || payload.error) {
      const requestError = new Error(payload.message || 'Something went wrong.');
      requestError.payload = payload;
      throw requestError;
    }
    return payload.data;
  }
  function compactUrl(url) { try { const parsed = new URL(url); return parsed.pathname || '/'; } catch (error) { return url; } }
  function platformLabel(value) { return {ga4: 'GA4', posthog: 'PostHog', google_ads: 'Google Ads', meta: 'Meta', generic: 'Not assigned', unassigned: 'Not assigned'}[value] || value; }
  function renderChangeSummary() {
    const target = document.getElementById('release-change-summary');
    clear(target);
    const changes = state.changes || {new: 0, modified: 0, total: 0, baseline_version: null};
    const newItem = node('span', 'release-change-count new');
    newItem.appendChild(node('strong', '', changes.new || 0)); newItem.appendChild(node('small', '', 'New'));
    const modifiedItem = node('span', 'release-change-count modified');
    modifiedItem.appendChild(node('strong', '', changes.modified || 0)); modifiedItem.appendChild(node('small', '', 'Modified'));
    target.appendChild(newItem); target.appendChild(modifiedItem);
    if (changes.baseline_version) target.appendChild(node('p', '', 'Compared with approved release v' + changes.baseline_version + '.'));
    else target.appendChild(node('p', '', 'This is the first release, so every included tag is new.'));

    const hasChanges = Number(changes.total || 0) > 0;
    document.querySelector('.release-select-all').hidden = !hasChanges;
    document.querySelector('.release-preview-gate').hidden = !hasChanges;
    document.querySelector('.release-create-panel').hidden = !hasChanges;
  }
  function selectedEventSelections() {
    const mappingSelections = {};
    const baseSelections = {};
    let eventCount = 0;
    let error = '';
    document.querySelectorAll('.release-event-check:checked').forEach(function (checkbox) {
      const row = checkbox.closest('.release-event-row');
      const input = row.querySelector('.release-event-name');
      const eventName = input.value.trim();
      if (!/^[a-z][a-z0-9_]{0,39}$/.test(eventName)) {
        error = 'Event names must start with a letter and use only lowercase letters, numbers, and underscores.';
        input.classList.add('invalid');
        return;
      }
      input.classList.remove('invalid');
      const selectedParameters = checkbox.dataset.selectedParameters ? JSON.parse(checkbox.dataset.selectedParameters) : [];
      const event = {source_name: checkbox.dataset.sourceName, event_name: eventName, selected_parameters: selectedParameters};
      if (checkbox.dataset.kind === 'base') {
        const key = checkbox.dataset.itemKey;
        if (!baseSelections[key]) baseSelections[key] = {key: key, events: []};
        baseSelections[key].events.push(event);
      } else {
        const mappingId = Number(checkbox.dataset.mappingId);
        if (!mappingSelections[mappingId]) mappingSelections[mappingId] = {mapping_id: mappingId, events: []};
        mappingSelections[mappingId].events.push(event);
      }
      eventCount++;
    });
    return {mappingSelections: Object.values(mappingSelections), baseSelections: Object.values(baseSelections), eventCount: eventCount, error: error};
  }

  function renderMappings() {
    const target = document.getElementById('release-mapping-list');
    clear(target);
    if (!state.mappings.length && !state.baseEvents.length) {
      const empty = node('div', 'release-empty');
      empty.appendChild(node('strong', '', state.changes && state.changes.baseline_version ? 'No unpublished changes' : 'No draft tracking yet'));
      empty.appendChild(node('p', '', state.changes && state.changes.baseline_version ? 'The workspace matches the latest approved release.' : 'Include or configure tracking in Tags & triggers before creating the first release.'));
      const link = node('a', 'btn btn-primary btn-sm', 'Return to Tags & triggers');
      link.href = 'tracking-workspace.php?client_id=' + encodeURIComponent(state.clientId);
      empty.appendChild(link);
      target.appendChild(empty);
      return;
    }

    function renderItem(item, kind) {
      const card = node('article', 'release-mapping-item');
      const header = node('div', 'release-mapping-item-header');
      const input = node('input', 'release-item-check');
      const itemEvents = ((item.event_template || {}).events || []);
      input.type = 'checkbox'; input.checked = itemEvents.some(function (event) { return event.enabled !== false; });
      const body = node('span', 'release-mapping-body');
      const heading = node('span', 'release-mapping-heading');
      heading.appendChild(node('strong', '', item.element_name));
      heading.appendChild(node('small', '', kind === 'base' ? 'Recommended from site scan' : compactUrl(item.page_url)));
      body.appendChild(heading);
      const chips = node('span', 'release-platforms');
      chips.appendChild(node('span', 'onboarding-chip ' + (item.trigger_scope === 'global' ? '' : 'subtle'), item.trigger_scope === 'global' ? 'Site-wide' : 'This page'));
      (item.tag_templates || []).forEach(function (tag) { chips.appendChild(node('span', 'onboarding-chip subtle', platformLabel(tag.platform))); });
      body.appendChild(chips);
      header.appendChild(input); header.appendChild(body); card.appendChild(header);

      const events = node('div', 'release-event-list');
      itemEvents.forEach(function (event) {
        const row = node('div', 'release-event-row');
        const eventCheck = node('input', 'release-event-check');
        eventCheck.type = 'checkbox'; eventCheck.checked = event.enabled !== false; eventCheck.dataset.kind = kind;
        eventCheck.dataset.sourceName = event.source_name || event.name;
        eventCheck.dataset.selectedParameters = JSON.stringify(event.selected_parameters || event.parameters || []);
        if (kind === 'base') eventCheck.dataset.itemKey = item.key;
        else eventCheck.dataset.mappingId = item.id;
      const copy = node('div', 'release-event-copy');
        const changeType = event.change_type || item.change_type || 'new';
        copy.appendChild(node('span', 'release-change-badge ' + changeType, changeType === 'modified' ? 'Modified' : 'New'));
        copy.appendChild(node('label', 'form-label', 'Event name'));
        const nameInput = node('input', 'form-input release-event-name');
        nameInput.type = 'text'; nameInput.value = event.configured_name || event.name; nameInput.maxLength = 40;
        nameInput.setAttribute('aria-label', 'Editable event name for ' + (event.source_name || event.name));
        copy.appendChild(nameInput);
        copy.appendChild(node('small', '', 'Recommended: ' + (event.recommended_name || event.name) + ' · Parameters: ' + ((event.selected_parameters || event.parameters || []).join(', ') || 'none')));
        row.appendChild(eventCheck); row.appendChild(copy); events.appendChild(row);
        eventCheck.addEventListener('change', function () {
          const checkedEvents = events.querySelectorAll('.release-event-check:checked').length;
          input.checked = checkedEvents > 0;
          input.indeterminate = checkedEvents > 0 && checkedEvents < events.querySelectorAll('.release-event-check').length;
        });
      });
      input.addEventListener('change', function () {
        events.querySelectorAll('.release-event-check').forEach(function (eventCheck) { eventCheck.checked = input.checked; });
        input.indeterminate = false;
      });
      const initiallyChecked = events.querySelectorAll('.release-event-check:checked').length;
      input.indeterminate = initiallyChecked > 0 && initiallyChecked < itemEvents.length;
      card.appendChild(events); target.appendChild(card);
    }

    state.baseEvents.forEach(function (event) { renderItem(event, 'base'); });
    state.mappings.forEach(function (mapping) { renderItem(mapping, 'mapping'); });
    const allEventChecks = target.querySelectorAll('.release-event-check');
    const selectedEventChecks = target.querySelectorAll('.release-event-check:checked');
    const selectAll = document.getElementById('select-all-mappings');
    selectAll.checked = allEventChecks.length > 0 && allEventChecks.length === selectedEventChecks.length;
    selectAll.indeterminate = selectedEventChecks.length > 0 && selectedEventChecks.length < allEventChecks.length;
  }

  function renderReadiness(readiness) {
    state.readiness = readiness;
    const summary = document.getElementById('readiness-summary');
    const target = document.getElementById('readiness-list');
    clear(summary); clear(target);
    const blockers = readiness && readiness.blockers ? readiness.blockers.length : 0;
    summary.className = 'readiness-summary ' + (blockers ? 'blocked' : 'ready');
    summary.appendChild(node('strong', '', blockers ? blockers + ' item' + (blockers === 1 ? '' : 's') + ' to resolve' : 'Ready to publish'));
    summary.appendChild(node('span', '', blockers ? 'Your approved release is safe, but it is not deployable yet.' : 'All required safety and connection checks have passed.'));
    (readiness.checks || []).forEach(function (check) {
      const item = node('div', 'readiness-item ' + check.status);
      item.appendChild(node('span', 'readiness-icon', check.status === 'passed' ? '✓' : '!'));
      const copy = node('div', 'readiness-copy');
      copy.appendChild(node('strong', '', check.label)); copy.appendChild(node('p', '', check.detail)); item.appendChild(copy);
      if (check.status === 'blocked' && check.action_url) {
        const link = node('a', 'readiness-action', check.id === 'deployment_adapter' ? 'View status' : 'Fix this');
        link.href = check.action_url + (check.action_url.indexOf('?') === -1 ? '?' : '&') + 'client_id=' + encodeURIComponent(state.clientId);
        item.appendChild(link);
      }
      target.appendChild(item);
    });
  }

  function releaseStatusLabel(status) { return {draft: 'Draft', approved: 'Approved—not live', publishing: 'Publishing', published: 'Published', failed: 'Failed'}[status] || status; }
  function renderReleases() {
    const target = document.getElementById('release-history');
    clear(target);
    if (!state.releases.length) {
      const empty = node('div', 'card release-empty'); empty.appendChild(node('strong', '', 'No releases created yet'));
      empty.appendChild(node('p', '', 'Select your mappings above and save the first version.')); target.appendChild(empty); return;
    }
    state.releases.forEach(function (release) {
      const card = node('article', 'card release-history-card');
      const copy = node('div', 'release-history-copy'); const title = node('div', 'release-history-title');
      title.appendChild(node('strong', '', 'v' + release.version_number + ' · ' + release.name));
      title.appendChild(node('span', 'release-status ' + release.status, releaseStatusLabel(release.status))); copy.appendChild(title);
      copy.appendChild(node('p', '', (release.event_count || 0) + ' event' + (release.event_count === 1 ? '' : 's') + ' · Created ' + release.created_at)); card.appendChild(copy);
      if (release.status === 'approved') {
        const publish = node('button', 'btn btn-primary btn-sm', 'Publish tags'); publish.type = 'button';
        publish.addEventListener('click', function () { publishRelease(release, publish); }); card.appendChild(publish);
      }
      target.appendChild(card);
    });
  }

  async function load() {
    try {
      const data = await request('api/get-publish-readiness.php?client_id=' + encodeURIComponent(state.clientId));
      state.baseEvents = data.base_events || []; state.mappings = data.mappings || []; state.changes = data.changes || state.changes; state.releases = data.releases || [];
      renderChangeSummary(); renderMappings(); renderReadiness(data.readiness || {checks: [], blockers: []}); renderReleases();
    } catch (error) { showToast(error.message, 'error'); }
  }
  async function createRelease(action, button) {
    const selections = selectedEventSelections();
    if (selections.error) { showToast(selections.error, 'warning'); return; }
    if (!selections.eventCount) { showToast('Select at least one draft event.', 'warning'); return; }
    const original = button.textContent; button.disabled = true; button.textContent = action === 'approve' ? 'Approving…' : 'Saving…';
    try {
      const data = await request('api/create-tag-release.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({
        client_id: state.clientId,
        mapping_selections: selections.mappingSelections,
        base_event_selections: selections.baseSelections,
        name: document.getElementById('release-name').value,
        action: action,
        csrf_token: csrfToken()
      })});
      showToast(action === 'approve' ? 'Release approved. It is not live yet.' : 'Release draft saved.', 'success');
      renderReadiness(data.readiness); document.getElementById('release-name').value = ''; await load();
    } catch (error) { showToast(error.message, 'error'); } finally { button.disabled = false; button.textContent = original; }
  }
  async function publishRelease(release, button) {
    button.disabled = true; button.textContent = 'Checking…';
    try {
      await request('api/publish-tag-release.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({client_id: state.clientId, release_id: release.id, csrf_token: csrfToken()})});
      showToast('Release published.', 'success'); await load();
    } catch (error) {
      if (error.payload && error.payload.data && error.payload.data.readiness) {
        renderReadiness(error.payload.data.readiness); document.querySelector('.publish-readiness-card').scrollIntoView({behavior: 'smooth', block: 'start'});
      }
      showToast(error.message, 'warning');
    } finally { button.disabled = false; button.textContent = 'Publish tags'; }
  }

  document.getElementById('select-all-mappings').addEventListener('change', function () {
    const checked = this.checked;
    document.querySelectorAll('.release-item-check, .release-event-check').forEach(function (input) { input.checked = checked; input.indeterminate = false; });
  });
  document.getElementById('save-release-draft').addEventListener('click', function () { createRelease('draft', this); });
  document.getElementById('approve-release').addEventListener('click', function () { createRelease('approve', this); });
  load();
});
