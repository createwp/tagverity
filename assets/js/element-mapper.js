'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const app = document.getElementById('element-mapper-app');
  if (!app) return;

  const state = {
    clientId: Number(app.dataset.clientId),
    apiBase: (app.dataset.apiBase || '').replace(/\/$/, ''),
    selected: null,
    mappings: [],
    editingMapping: null,
    initialMappingId: Number(app.dataset.editMappingId || 0),
    initialMappingHandled: false
  };

  const pageSelect = document.getElementById('mapper-page-select');
  const frame = document.getElementById('site-preview-frame');
  const status = document.getElementById('mapper-preview-status');
  const empty = document.getElementById('mapper-selection-empty');
  const form = document.getElementById('mapping-form');
  const dataKeyGroup = document.getElementById('data-key-group');
  const dataKeySelect = document.getElementById('mapping-data-key');

  const recommendationCopy = {
    click: 'Recommended: use a delegated click trigger scoped to this selector. It will include clicks on child icons and labels.',
    visibility: 'Recommended: trigger after at least 50% of the element is visible for one second, once per page.',
    form: 'Recommended: observe the complete form journey—view, start, field interaction, abandonment, and verified completion—without collecting field values.',
    ecommerce_value: 'Recommended: extract and normalise this value into the ecommerce item model, then validate it before sending an ecommerce event.',
    site_search: 'Recommended: extract this value and attach it to the standard search-results event.'
  };

  const dataKeys = {
    ecommerce_value: [
      ['item_name', 'Product name'], ['item_id', 'Product ID or SKU'], ['price', 'Price'],
      ['currency', 'Currency'], ['item_category', 'Product category']
    ],
    site_search: [
      ['search_term', 'Search term'], ['results_count', 'Number of results'],
      ['filter_name', 'Filter name'], ['filter_value', 'Filter value']
    ]
  };

  function endpoint(path) {
    return state.apiBase + '/' + path.replace(/^\//, '');
  }

  function csrfToken() {
    return app.querySelector('input[name="csrf_token"]').value;
  }

  function node(tag, className, text) {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== undefined && text !== null) item.textContent = String(text);
    return item;
  }

  function clear(target) {
    while (target.firstChild) target.removeChild(target.firstChild);
  }

  async function request(path, options) {
    const response = await fetch(endpoint(path), options);
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }
    if (!response.ok || payload.error) throw new Error(payload.message || 'Something went wrong.');
    return payload.data;
  }

  function loadPreview() {
    status.textContent = 'Loading safe preview...';
    const url = pageSelect.value;
    frame.src = endpoint('preview-site.php?client_id=' + encodeURIComponent(state.clientId) + '&url=' + encodeURIComponent(url) + '&v=' + Date.now());
  }

  function compactUrl(url) {
    try {
      const parsed = new URL(url);
      return parsed.pathname || '/';
    } catch (error) {
      return url;
    }
  }

  function selectElement(payload, mappingId) {
    state.selected = payload;
    state.editingMapping = mappingId || null;
    empty.hidden = true;
    form.hidden = false;
    document.getElementById('mapping-id').value = mappingId || '';
    document.getElementById('selected-element-label').textContent = payload.suggested_name || payload.text || payload.tag;
    const formIdentity = payload.form_id || payload.form_name || payload.form_class_name;
    document.getElementById('selected-element-meta').textContent = '<' + (payload.tag || 'element') + '> on ' + compactUrl(pageSelect.value) + (formIdentity ? ' · form: ' + formIdentity : '');
    document.getElementById('element-business-name').value = payload.saved_name || payload.suggested_name || payload.text || '';
    document.getElementById('selected-css-selector').value = payload.selector || '';
    setRecommendedIntent(payload.saved_intent || payload.recommended_intent || 'click');
    if (payload.saved_goal) {
      const goal = form.querySelector('input[name="measurement_goal"][value="' + payload.saved_goal + '"]');
      if (goal) goal.checked = true;
    }
    const savedScope = form.querySelector('input[name="trigger_scope"][value="' + (payload.saved_scope || 'page') + '"]');
    if (savedScope) savedScope.checked = true;
    updateDataKeyOptions(payload.saved_data_key || '');
    renderRecommendation();
  }

  function setRecommendedIntent(intent) {
    form.querySelectorAll('[data-intent-option]').forEach(function (label) {
      const recommended = label.dataset.intentOption === intent;
      label.classList.toggle('recommended', recommended);
      const input = label.querySelector('input');
      if (recommended) input.checked = true;
    });
  }

  function selectedIntent() {
    const checked = form.querySelector('input[name="tracking_intent"]:checked');
    return checked ? checked.value : 'click';
  }

  function updateDataKeyOptions(preferred) {
    const intent = selectedIntent();
    const options = dataKeys[intent] || [];
    clear(dataKeySelect);
    options.forEach(function (option) {
      const item = node('option', '', option[1]);
      item.value = option[0];
      if (preferred === option[0]) item.selected = true;
      dataKeySelect.appendChild(item);
    });
    dataKeyGroup.hidden = !options.length;
  }

  function renderRecommendation() {
    const intent = selectedIntent();
    const target = document.getElementById('trigger-recommendation');
    clear(target);
    target.appendChild(node('strong', '', 'Suggested trigger'));
    target.appendChild(node('p', '', recommendationCopy[intent]));
  }

  async function loadMappings() {
    try {
      const data = await request('api/get-element-mappings.php?client_id=' + encodeURIComponent(state.clientId));
      state.mappings = data.mappings || [];
      renderMappings();
      if (state.initialMappingId && !state.initialMappingHandled) {
        state.initialMappingHandled = true;
        const initialMapping = state.mappings.find(function (mapping) { return mapping.id === state.initialMappingId; });
        if (initialMapping) editMapping(initialMapping);
      }
    } catch (error) {
      showToast(error.message, 'error');
    }
  }

  function renderMappings() {
    const target = document.getElementById('saved-mappings');
    clear(target);
    if (!state.mappings.length) {
      const blank = node('div', 'card mapper-list-empty');
      blank.appendChild(node('strong', '', 'No elements mapped yet'));
      blank.appendChild(node('p', '', 'Select the first meaningful element in the page preview above.'));
      target.appendChild(blank);
      return;
    }

    state.mappings.forEach(function (mapping) {
      const card = node('article', 'card mapper-mapping-card');
      const header = node('div', 'mapper-mapping-header');
      const title = node('div');
      title.appendChild(node('strong', '', mapping.element_name));
      title.appendChild(node('small', '', compactUrl(mapping.page_url)));
      const edit = node('button', 'btn btn-secondary btn-sm', 'Edit');
      edit.type = 'button';
      edit.addEventListener('click', function () { editMapping(mapping); });
      header.appendChild(title);
      header.appendChild(edit);
      card.appendChild(header);

      const chips = node('div', 'onboarding-chips');
      chips.appendChild(node('span', 'onboarding-chip', labelIntent(mapping.tracking_intent)));
      chips.appendChild(node('span', 'onboarding-chip ' + (mapping.trigger_scope === 'global' ? '' : 'subtle'), mapping.trigger_scope === 'global' ? 'Site-wide' : 'This page'));
      const tags = mapping.tag_templates || [];
      tags.forEach(function (tag) { chips.appendChild(node('span', 'onboarding-chip subtle', labelPlatform(tag.platform))); });
      card.appendChild(chips);
      card.appendChild(node('code', 'mapper-selector-code', mapping.css_selector));
      const trigger = mapping.trigger_template || {};
      card.appendChild(node('p', 'text-muted text-sm', 'Configured trigger: ' + (trigger.type || 'Needs review') + ' · live preview required'));
      target.appendChild(card);
    });
  }

  function labelIntent(value) {
    return {click: 'Click', visibility: 'Visibility', form: 'Form journey', ecommerce_value: 'Product data', site_search: 'Search data'}[value] || value;
  }

  function labelPlatform(value) {
    return {ga4: 'GA4', posthog: 'PostHog', google_ads: 'Google Ads', meta: 'Meta', generic: 'Not assigned', unassigned: 'Not assigned'}[value] || value;
  }

  function ensurePageOption(url) {
    if (!pageSelect.querySelector('option[value="' + CSS.escape(url) + '"]')) {
      const option = new Option(compactUrl(url), url);
      pageSelect.add(option);
    }
  }

  function editMapping(mapping) {
    ensurePageOption(mapping.page_url);
    pageSelect.value = mapping.page_url;
    const attributes = mapping.element_attributes || {};
    selectElement(Object.assign({}, attributes, {
      selector: mapping.css_selector,
      suggested_name: mapping.element_name,
      saved_name: mapping.element_name,
      saved_intent: mapping.tracking_intent,
      saved_goal: mapping.measurement_goal,
      saved_data_key: mapping.data_key,
      saved_scope: mapping.trigger_scope || 'page'
    }), mapping.id);
    loadPreview();
    document.querySelector('.mapper-workspace').scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  window.addEventListener('message', function (event) {
    if (event.source !== frame.contentWindow || !event.data || event.data.source !== 'tagverity-preview') return;
    if (event.data.type === 'preview_ready') {
      status.textContent = 'Ready—click an element on the page.';
      if (state.editingMapping && state.selected && state.selected.selector) {
        frame.contentWindow.postMessage({source: 'tagverity-parent', type: 'highlight_selector', selector: state.selected.selector}, '*');
      }
    }
    if (event.data.type === 'element_selected' && event.data.payload) {
      selectElement(event.data.payload, null);
      showToast('Element selected. Confirm what you want to measure.', 'info');
    }
  });

  document.getElementById('mapper-check-snippet-button')?.addEventListener('click', async function () {
    try {
      const data = await request('api/check-mapper-snippet.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({client_id: state.clientId, csrf_token: csrfToken()})});
      if (data.detected) { showToast('Snippet detected — reloading.', 'success'); location.reload(); }
      else showToast('Snippet not found yet. Double-check it is pasted in <head> and try again.', 'error');
    } catch (error) { showToast(error.message, 'error'); }
  });

  document.getElementById('mapper-open-live-button')?.addEventListener('click', async function () {
    // window.open() must run synchronously in this click handler, before any
    // await, or most browsers (notably Safari) treat the eventual open() as
    // not user-triggered and block it. Open a blank window immediately,
    // fail fast on a popup block, and only navigate it once the session is
    // minted.
    const liveWindow = window.open('about:blank');
    if (!liveWindow) { showToast('Allow pop-ups for this site to open the live mapper.', 'error'); return; }
    try {
      const startUrlInput = document.getElementById('mapper-start-url-input');
      const data = await request('api/create-live-mapper-session.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          client_id: state.clientId,
          start_url: (startUrlInput && startUrlInput.value.trim()) || pageSelect.value,
          csrf_token: csrfToken()
        })
      });
      liveWindow.location = data.root_url + '#__tagverity_mapper=' + encodeURIComponent(data.token);
      state.liveMapperWindow = liveWindow;
      state.liveMapperOrigins = data.allowed_origins || [];
      state.liveMapperExpiresAt = new Date(data.expires_at.replace(' ', 'T') + 'Z').getTime();
      if (state.liveMapperPollId) clearInterval(state.liveMapperPollId);
      state.liveMapperPollId = setInterval(function () {
        if (state.liveMapperWindow.closed || Date.now() > state.liveMapperExpiresAt) {
          clearInterval(state.liveMapperPollId);
          return;
        }
        loadMappings();
      }, 4000);
    } catch (error) {
      liveWindow.close();
      showToast(error.message, 'error');
    }
  });

  window.addEventListener('message', function (event) {
    if (!state.liveMapperWindow || event.source !== state.liveMapperWindow) return;
    if (!state.liveMapperOrigins || state.liveMapperOrigins.indexOf(event.origin) === -1) return;
    if (!event.data || event.data.source !== 'tagverity-live-mapper' || event.data.type !== 'element_selected') return;
    const payload = event.data.payload;
    ensurePageOption(payload.page_url);
    pageSelect.value = payload.page_url;
    selectElement(payload, null);
    showToast('Element received from the live site. Confirm what you want to measure.', 'info');
    event.source.postMessage({source: 'tagverity-dashboard', type: 'element_selected_ack'}, event.origin);
  });

  pageSelect.addEventListener('change', function () {
    state.selected = null;
    state.editingMapping = null;
    empty.hidden = false;
    form.hidden = true;
    loadPreview();
  });

  document.getElementById('reload-preview-button').addEventListener('click', loadPreview);

  form.querySelectorAll('input[name="tracking_intent"]').forEach(function (input) {
    input.addEventListener('change', function () {
      updateDataKeyOptions('');
      renderRecommendation();
    });
  });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!state.selected) return;
    const button = document.getElementById('save-mapping-button');
    button.disabled = true;
    button.textContent = 'Saving mapping...';
    const goal = form.querySelector('input[name="measurement_goal"]:checked');
    const scope = form.querySelector('input[name="trigger_scope"]:checked');
    try {
      const data = await request('api/save-element-mapping.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          client_id: state.clientId,
          mapping_id: Number(document.getElementById('mapping-id').value) || 0,
          page_url: pageSelect.value,
          element_name: document.getElementById('element-business-name').value,
          css_selector: document.getElementById('selected-css-selector').value,
          tracking_intent: selectedIntent(),
          measurement_goal: goal ? goal.value : 'product_analytics',
          trigger_scope: scope ? scope.value : 'page',
          data_key: dataKeyGroup.hidden ? '' : dataKeySelect.value,
          element_attributes: state.selected,
          csrf_token: csrfToken()
        })
      });
      showToast('Element mapping saved. Return to Tags & triggers to choose its events and parameters.', 'success');
      state.editingMapping = data.mapping.id;
      document.getElementById('mapping-id').value = data.mapping.id;
      await loadMappings();
    } catch (error) {
      showToast(error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Save element mapping';
    }
  });

  function setFullscreen(enabled) {
    document.querySelector('.mapper-workspace').classList.toggle('is-fullscreen', enabled);
    document.body.classList.toggle('mapper-fullscreen-open', enabled);
    document.getElementById('mapper-fullscreen-close').hidden = !enabled;
    document.getElementById('mapper-fullscreen-button').textContent = enabled ? 'Full-screen mapper open' : 'Open full-screen mapper';
  }

  document.getElementById('mapper-fullscreen-button').addEventListener('click', function () { setFullscreen(true); });
  document.getElementById('mapper-fullscreen-close').addEventListener('click', function () { setFullscreen(false); });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') setFullscreen(false); });

  loadPreview();
  loadMappings();
});
