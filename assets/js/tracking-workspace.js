'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const app = document.getElementById('tracking-workspace-app');
  if (!app) return;

  const state = {
    clientId: Number(app.dataset.clientId),
    apiBase: (app.dataset.apiBase || '').replace(/\/$/, ''),
    events: [],
    privacy: null,
    filter: 'all',
    search: '',
    tagType: 'all',
    triggerType: 'all',
    page: 1,
    pageSize: 10,
    expandedEventKey: null
  };

  function endpoint(path) { return state.apiBase + '/' + path.replace(/^\//, ''); }
  function csrfToken() { return app.querySelector('input[name="csrf_token"]').value; }
  function node(tag, className, value) {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (value !== undefined && value !== null) item.textContent = String(value);
    return item;
  }
  function clear(target) { while (target.firstChild) target.removeChild(target.firstChild); }
  function appIcon(name) {
    const template = document.getElementById('tracking-icon-' + name);
    return template && template.content.firstElementChild
      ? template.content.firstElementChild.cloneNode(true)
      : document.createTextNode('');
  }
  async function request(path, options) {
    const response = await fetch(endpoint(path), options);
    let payload;
    try { payload = await response.json(); } catch (error) { throw new Error('The server returned an unreadable response.'); }
    if (!response.ok || payload.error) throw new Error(payload.message || 'Something went wrong.');
    return payload.data;
  }
  function compactUrl(value) {
    if (!value) return 'Every page';
    try { return new URL(value).pathname || '/'; } catch (error) { return value; }
  }
  function platformLabel(value) {
    return {ga4: 'GA4', posthog: 'PostHog', google_ads: 'Google Ads', meta: 'Meta', generic: 'Not assigned', unassigned: 'Not assigned'}[value] || value;
  }
  function destinationNameError(platform, value) {
    if (platform === 'ga4' && !/^[a-z][a-z0-9_]{0,39}$/.test(value)) return 'GA4 names must start with a letter and use no more than 40 lowercase letters, numbers, or underscores.';
    if (platform === 'posthog' && !/^[a-z][a-z0-9_]{0,79}$/.test(value)) return 'PostHog names must use lowercase snake_case and contain no more than 80 characters.';
    if (platform === 'meta' && !/^[A-Za-z][A-Za-z0-9_]{0,79}$/.test(value)) return 'Choose a Meta standard event or use a letter-led custom name without spaces.';
    if (platform === 'google_ads' && (!value || value.length > 100)) return 'Google Ads conversion action names must contain no more than 100 characters.';
    return '';
  }
  function triggerLabel(trigger) {
    const labels = {page_view: 'Page view', element_click: 'Element click', element_visibility: 'Element visibility', form_lifecycle: 'Form journey', data_layer_event: 'Data layer event', site_search: 'Site search', result_interaction: 'Result interaction', dom_value_extraction: 'Value extraction'};
    return labels[trigger.type] || trigger.type || 'Trigger needs review';
  }
  function statusLabel(event) { return event.kind === 'base' ? 'Recommended' : 'Mapped'; }

  function renderSummary() {
    const target = document.getElementById('tracking-workspace-summary');
    clear(target);
    const included = state.events.filter(function (event) { return event.enabled; }).length;
    const recommended = state.events.filter(function (event) { return event.kind === 'base'; }).length;
    const mapped = state.events.filter(function (event) { return event.kind === 'mapping'; }).length;
    [
      [included, 'Included'],
      [recommended, 'Recommended'],
      [mapped, 'Mapped']
    ].forEach(function (metric) {
      const item = node('div', 'tracking-summary-item');
      item.appendChild(node('strong', '', metric[0]));
      item.appendChild(node('span', '', metric[1]));
      target.appendChild(item);
    });
  }

  function eventVisible(event) {
    if (state.filter === 'recommended' && event.kind !== 'base') return false;
    if (state.filter === 'mapped' && event.kind !== 'mapping') return false;
    if (state.filter === 'enabled' && !event.enabled) return false;
    if (state.filter === 'configured' && !event.configured) return false;
    if (state.filter === 'disabled' && event.enabled) return false;
    if (state.tagType !== 'all' && !(event.tag_templates || []).some(function (tag) { return tag.platform === state.tagType; })) return false;
    if (state.triggerType !== 'all' && (event.trigger_template || {}).type !== state.triggerType) return false;
    if (state.search) {
      const tags = (event.tag_templates || []).map(function (tag) { return platformLabel(tag.platform) + ' ' + tag.template + ' ' + (tag.destination_event_name || ''); }).join(' ');
      const searchable = [event.configured_name, event.source_name, event.element_name, event.source_label, event.coverage_label, triggerLabel(event.trigger_template || {}), tags, (event.available_parameters || []).join(' ')].join(' ').toLowerCase();
      if (searchable.indexOf(state.search) === -1) return false;
    }
    return true;
  }

  function renderEvents() {
    const target = document.getElementById('tracking-event-list');
    clear(target);
    const filteredEvents = state.events.filter(eventVisible);
    const totalPages = Math.max(1, Math.ceil(filteredEvents.length / state.pageSize));
    state.page = Math.min(state.page, totalPages);
    const start = (state.page - 1) * state.pageSize;
    const visibleEvents = filteredEvents.slice(start, start + state.pageSize);
    renderPagination(filteredEvents.length, start, visibleEvents.length, totalPages);
    if (!visibleEvents.length) {
      const empty = node('div', 'card tracking-list-empty');
      empty.appendChild(node('strong', '', state.events.length ? 'No events match this filter' : 'No events have been proposed yet'));
      empty.appendChild(node('p', '', state.events.length ? 'Clear the search or choose another tag or trigger filter.' : 'Complete tracking setup or map an element to create the first event.'));
      target.appendChild(empty);
      return;
    }
    visibleEvents.forEach(function (event) { target.appendChild(buildEventCard(event)); });
  }

  function renderPagination(total, start, visibleCount, totalPages) {
    const pagination = document.getElementById('tracking-pagination');
    pagination.hidden = state.events.length === 0;
    document.getElementById('tracking-page-range').textContent = total
      ? (start + 1) + '–' + (start + visibleCount) + ' of ' + total
      : '0 results';
    document.getElementById('tracking-page-number').textContent = 'Page ' + state.page + ' of ' + totalPages;
    document.getElementById('tracking-page-previous').disabled = state.page <= 1;
    document.getElementById('tracking-page-next').disabled = state.page >= totalPages;
  }

  function populateTypeFilters() {
    const tagTypes = {};
    const triggerTypes = {};
    state.events.forEach(function (event) {
      (event.tag_templates || []).forEach(function (tag) { if (tag.platform) tagTypes[tag.platform] = platformLabel(tag.platform); });
      const triggerType = (event.trigger_template || {}).type;
      if (triggerType) triggerTypes[triggerType] = triggerLabel({type: triggerType});
    });
    populateFilterSelect(document.getElementById('tracking-tag-type-filter'), tagTypes, 'All destinations', state.tagType);
    populateFilterSelect(document.getElementById('tracking-trigger-type-filter'), triggerTypes, 'All trigger types', state.triggerType);
  }

  function populateFilterSelect(select, options, allLabel, selectedValue) {
    clear(select);
    const all = node('option', '', allLabel); all.value = 'all'; select.appendChild(all);
    Object.keys(options).sort(function (left, right) { return options[left].localeCompare(options[right]); }).forEach(function (value) {
      const option = node('option', '', options[value]); option.value = value; select.appendChild(option);
    });
    select.value = options[selectedValue] ? selectedValue : 'all';
  }

  function buildEventCard(event) {
    const expanded = state.expandedEventKey === event.event_key;
    const trigger = event.trigger_template || {};
    const form = node('form', 'card tracking-event-card' + (expanded ? ' expanded' : ''));
    form.dataset.eventKey = event.event_key;

    const header = node('div', 'tracking-event-card-header');
    const identity = node('div', 'tracking-event-identity');
    const status = node('span', 'tracking-status ' + (event.kind === 'base' ? 'proposed' : 'configured'), statusLabel(event));
    status.title = event.kind === 'base' ? 'Recommended from the site scan and business setup.' : 'Created by selecting a specific website element.';
    const identityCopy = node('div', 'tracking-event-identity-copy');
    identityCopy.appendChild(node('code', 'tracking-event-summary-name', event.configured_name));
    const platformSummary = (event.tag_templates || []).map(function (tag) { return platformLabel(tag.platform); }).filter(function (value, index, values) { return values.indexOf(value) === index; }).join(', ');
    identityCopy.appendChild(node('p', '', triggerLabel(trigger) + ' · ' + (event.coverage_label || (event.kind === 'base' ? 'Site-wide' : compactUrl(event.page_url))) + (platformSummary ? ' · Destination: ' + platformSummary : '')));
    identity.appendChild(identityCopy);
    const quickActions = node('div', 'tracking-event-quick-actions');
    quickActions.appendChild(status);
    const includeInput = node('input', 'tracking-inclusion-state');
    includeInput.type = 'checkbox'; includeInput.checked = event.enabled;
    includeInput.dataset.role = 'enabled';
    includeInput.tabIndex = -1; includeInput.setAttribute('aria-hidden', 'true');
    const includeButton = node('button', 'tracking-icon-action tracking-inclusion-action' + (event.enabled ? ' included' : ' excluded'));
    includeButton.type = 'button'; includeButton.dataset.role = 'include-action';
    const includeLabel = event.enabled ? 'Remove ' + event.configured_name + ' from deployment' : 'Add ' + event.configured_name + ' to deployment';
    includeButton.setAttribute('aria-label', includeLabel); includeButton.title = includeLabel;
    includeButton.appendChild(appIcon(event.enabled ? 'minus' : 'plus'));
    const expandButton = node('button', 'tracking-icon-action tracking-event-expand');
    expandButton.type = 'button';
    expandButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    expandButton.setAttribute('aria-label', (expanded ? 'Close ' : 'Edit ') + event.configured_name + ' details');
    expandButton.title = expanded ? 'Close event details' : 'Edit event details';
    expandButton.appendChild(appIcon('pencil'));
    quickActions.appendChild(includeInput); quickActions.appendChild(includeButton); quickActions.appendChild(expandButton);
    header.appendChild(identity); header.appendChild(quickActions); form.appendChild(header);

    const body = node('div', 'tracking-event-card-body');
    body.hidden = !expanded;
    const config = node('div', 'tracking-event-config');
    const nameGroup = node('div', 'form-group');
    const nameLabel = node('label', 'form-label', event.kind === 'mapping' ? 'Custom event name' : 'Approved event name');
    const nameInput = node('input', 'form-input font-mono');
    nameInput.type = 'text'; nameInput.maxLength = 40; nameInput.value = event.configured_name;
    nameInput.dataset.role = 'event-name';
    nameLabel.htmlFor = 'event-name-' + safeId(event.event_key); nameInput.id = nameLabel.htmlFor;
    nameGroup.appendChild(nameLabel); nameGroup.appendChild(nameInput);
    nameGroup.appendChild(node('p', 'form-help', event.kind === 'mapping'
      ? 'Uses your mapper name. Parameter template inherited from system event: ' + event.system_event_name + '.'
      : 'Recommended name: ' + event.recommended_name));
    if (event.kind === 'base' && event.recommendation_when) nameGroup.appendChild(node('p', 'tracking-recommendation-reason', event.recommendation_when));
    config.appendChild(nameGroup);

    const groupField = node('div', 'form-group');
    const groupLabel = node('label', 'form-label', 'Business meaning');
    const groupSelect = node('select', 'form-select');
    groupSelect.dataset.role = 'event-group';
    groupSelect.id = 'event-group-' + safeId(event.event_key); groupLabel.htmlFor = groupSelect.id;
    Object.keys(event.event_group_options || {}).forEach(function (value) {
      const option = node('option', '', event.event_group_options[value]);
      option.value = value; option.selected = value === event.event_group; groupSelect.appendChild(option);
    });
    groupField.appendChild(groupLabel); groupField.appendChild(groupSelect);
    groupField.appendChild(node('p', 'form-help', 'A neutral classification shared by every destination.'));
    config.appendChild(groupField);

    if ((event.available_trigger_intents || []).length) {
      const triggerGroup = node('div', 'form-group');
      const triggerSelectLabel = node('label', 'form-label', 'Trigger & parameter template');
      const triggerSelect = node('select', 'form-select');
      triggerSelect.dataset.role = 'trigger-intent';
      triggerSelectLabel.htmlFor = 'trigger-intent-' + safeId(event.event_key); triggerSelect.id = triggerSelectLabel.htmlFor;
      event.available_trigger_intents.forEach(function (intent) {
        const option = node('option', '', intent === 'click' ? 'Element click' : 'Element visibility');
        option.value = intent; option.selected = intent === event.trigger_intent; triggerSelect.appendChild(option);
      });
      triggerGroup.appendChild(triggerSelectLabel); triggerGroup.appendChild(triggerSelect);
      triggerGroup.appendChild(node('p', 'form-help', 'Changing this keeps your custom event name and loads the matching system parameters.'));
      config.appendChild(triggerGroup);
      triggerSelect.addEventListener('change', function () { form.requestSubmit(); });
    }

    const params = node('fieldset', 'tracking-parameter-fieldset');
    params.appendChild(node('legend', '', event.kind === 'mapping'
      ? 'Parameters inherited from ' + event.system_event_name
      : 'Parameters this event can capture'));
    if (!event.available_parameters.length) {
      params.appendChild(node('p', 'form-help', 'This event does not capture additional parameters.'));
    } else {
      const choices = node('div', 'tracking-parameter-grid');
      event.available_parameters.forEach(function (parameter) {
        const label = node('label', 'tracking-parameter');
        const checkbox = node('input');
        checkbox.type = 'checkbox'; checkbox.value = parameter; checkbox.dataset.role = 'parameter';
        checkbox.checked = event.selected_parameters.indexOf(parameter) !== -1;
        label.appendChild(checkbox); label.appendChild(node('span', '', parameter)); choices.appendChild(label);
      });
      params.appendChild(choices);
    }
    config.appendChild(params);

    const destinationFieldset = node('fieldset', 'tracking-destination-fieldset');
    destinationFieldset.appendChild(node('legend', '', 'Destinations'));
    destinationFieldset.appendChild(node('p', 'form-help', 'Send this one event to one or more platforms. Each platform can use its own name.'));
    const destinationGrid = node('div', 'tracking-destination-grid');
    let openedSelectedDestination = false;
    (event.destinations || []).forEach(function (destination) {
      const card = node('details', 'tracking-destination-card' + (destination.enabled ? ' selected' : ''));
      if (destination.enabled && !openedSelectedDestination) {
        card.open = true;
        openedSelectedDestination = true;
      }
      const summary = node('summary', 'tracking-destination-summary');
      const toggleLabel = node('label', 'tracking-destination-toggle');
      const toggle = node('input');
      toggle.type = 'checkbox'; toggle.checked = destination.enabled;
      toggle.dataset.role = 'destination-enabled'; toggle.dataset.platform = destination.platform;
      toggleLabel.appendChild(toggle); toggleLabel.appendChild(node('strong', '', destination.label));
      const statusSummary = node('span', 'tracking-destination-value', destination.enabled ? destination.event_name : 'Not selected');
      const chevron = node('span', 'tracking-destination-chevron');
      chevron.appendChild(appIcon('chevron'));
      summary.appendChild(toggleLabel); summary.appendChild(statusSummary); summary.appendChild(chevron);
      card.appendChild(summary);
      const body = node('div', 'tracking-destination-body');
      const controls = node('div', 'tracking-destination-controls');
      const recommendationGroup = node('div', 'form-group');
      const recommendationLabel = node('label', 'form-label', 'Recommended mapping');
      const recommendationSelect = node('select', 'form-select');
      recommendationSelect.dataset.role = 'destination-recommendation';
      recommendationSelect.disabled = !destination.enabled;
      recommendationSelect.id = 'destination-recommendation-' + destination.platform + '-' + safeId(event.event_key);
      recommendationLabel.htmlFor = recommendationSelect.id;
      recommendationGroup.appendChild(recommendationLabel); recommendationGroup.appendChild(recommendationSelect);
      const valueGroup = node('div', 'form-group');
      const valueLabel = node('label', 'form-label', destination.field_label);
      const valueInput = node('input', 'form-input');
      valueInput.type = 'text'; valueInput.maxLength = destination.max_length || 80; valueInput.value = destination.event_name;
      valueInput.dataset.role = 'destination-name'; valueInput.dataset.platform = destination.platform;
      valueInput.disabled = !destination.enabled;
      valueInput.id = 'destination-' + destination.platform + '-' + safeId(event.event_key);
      valueLabel.htmlFor = valueInput.id;
      valueGroup.appendChild(valueLabel); valueGroup.appendChild(valueInput);
      controls.appendChild(recommendationGroup); controls.appendChild(valueGroup);
      body.appendChild(controls);
      body.appendChild(node('p', 'form-help tracking-destination-format', destination.format_help));
      const parameterFieldset = node('fieldset', 'tracking-destination-parameters');
      parameterFieldset.appendChild(node('legend', '', 'Parameters sent to ' + destination.label));
      if (!(destination.parameters || []).length) {
        parameterFieldset.appendChild(node('p', 'form-help', 'None of this event’s parameters map to a standard ' + destination.label + ' field.'));
      } else {
        (destination.parameters || []).forEach(function (parameter) {
          const parameterLabel = node('label', 'tracking-destination-parameter');
          const parameterToggle = node('input');
          parameterToggle.type = 'checkbox'; parameterToggle.checked = parameter.selected;
          parameterToggle.dataset.role = 'destination-parameter';
          parameterToggle.dataset.platform = destination.platform;
          parameterToggle.value = parameter.canonical_name;
          parameterToggle.disabled = !destination.enabled;
          parameterLabel.appendChild(parameterToggle);
          const parameterCopy = node('span');
          parameterCopy.appendChild(node('code', '', parameter.canonical_name));
          parameterCopy.appendChild(node('span', '', '→'));
          parameterCopy.appendChild(node('code', '', parameter.destination_name));
          parameterLabel.appendChild(parameterCopy);
          parameterFieldset.appendChild(parameterLabel);
        });
      }
      body.appendChild(parameterFieldset);
      const identifierFieldset = node('fieldset', 'tracking-destination-identifiers');
      identifierFieldset.appendChild(node('legend', '', 'User identifiers'));
      identifierFieldset.appendChild(node('p', 'form-help', destination.identifier_notice));
      (destination.user_identifiers || []).forEach(function (identifier) {
        const identifierLabel = node('label', 'tracking-destination-parameter tracking-destination-identifier');
        const identifierToggle = node('input');
        identifierToggle.type = 'checkbox'; identifierToggle.checked = identifier.selected;
        identifierToggle.dataset.role = 'destination-identifier';
        identifierToggle.dataset.platform = destination.platform;
        identifierToggle.value = identifier.canonical_name;
        identifierToggle.disabled = !destination.enabled;
        identifierLabel.appendChild(identifierToggle);
        const identifierCopy = node('span');
        identifierCopy.appendChild(node('code', '', identifier.canonical_name));
        identifierCopy.appendChild(node('span', '', '→ SHA-256 →'));
        identifierCopy.appendChild(node('code', '', identifier.destination_name));
        identifierLabel.appendChild(identifierCopy);
        identifierFieldset.appendChild(identifierLabel);
      });
      body.appendChild(identifierFieldset);
      const previewActions = node('div', 'tracking-destination-preview-actions');
      const previewButton = node('button', 'btn btn-secondary btn-sm', 'Preview saved payload');
      previewButton.type = 'button';
      previewButton.disabled = !destination.enabled;
      previewButton.addEventListener('click', function () { previewDestinationPayload(event, destination.platform, previewButton); });
      previewActions.appendChild(node('p', 'form-help', 'Uses generated sample values. No event is sent.'));
      previewActions.appendChild(previewButton);
      body.appendChild(previewActions);
      card.appendChild(body);
      function populateRecommendations(group) {
        clear(recommendationSelect);
        const recommendations = (destination.recommendations_by_group || {})[group] || destination.recommendations || [];
        recommendations.forEach(function (recommendation) {
          const option = node('option', '', recommendation);
          option.value = recommendation; recommendationSelect.appendChild(option);
        });
        const customOption = node('option', '', 'Custom name…');
        customOption.value = '__custom__'; recommendationSelect.appendChild(customOption);
        recommendationSelect.value = recommendations.indexOf(valueInput.value.trim()) !== -1 ? valueInput.value.trim() : '__custom__';
      }
      populateRecommendations(groupSelect.value);
      toggle.addEventListener('click', function (clickEvent) { clickEvent.stopPropagation(); });
      toggle.addEventListener('change', function () {
        valueInput.disabled = !toggle.checked;
        recommendationSelect.disabled = !toggle.checked;
        card.querySelectorAll('[data-role="destination-parameter"]').forEach(function (parameterToggle) {
          parameterToggle.disabled = !toggle.checked;
        });
        card.querySelectorAll('[data-role="destination-identifier"]').forEach(function (identifierToggle) {
          identifierToggle.disabled = !toggle.checked;
        });
        previewButton.disabled = !toggle.checked;
        card.classList.toggle('selected', toggle.checked);
        statusSummary.textContent = toggle.checked ? (valueInput.value.trim() || 'Name required') : 'Not selected';
        if (toggle.checked) card.open = true;
      });
      valueInput.addEventListener('input', function () {
        if (toggle.checked) statusSummary.textContent = valueInput.value.trim() || 'Name required';
        const optionValues = Array.from(recommendationSelect.options).map(function (option) { return option.value; });
        recommendationSelect.value = optionValues.indexOf(valueInput.value.trim()) !== -1 ? valueInput.value.trim() : '__custom__';
      });
      recommendationSelect.addEventListener('change', function () {
        if (recommendationSelect.value === '__custom__') {
          valueInput.focus();
          return;
        }
        valueInput.value = recommendationSelect.value;
        statusSummary.textContent = recommendationSelect.value;
      });
      groupSelect.addEventListener('change', function () { populateRecommendations(groupSelect.value); });
      destinationGrid.appendChild(card);
    });
    destinationFieldset.appendChild(destinationGrid);
    config.appendChild(destinationFieldset);

    const details = node('div', 'tracking-trigger-panel');
    const detailHeader = node('div', 'tracking-trigger-panel-header');
    detailHeader.appendChild(node('strong', '', 'Trigger'));
    detailHeader.appendChild(node('span', 'onboarding-chip', triggerLabel(trigger)));
    details.appendChild(detailHeader);
    details.appendChild(detailRow('Runs on', event.coverage_label || (event.trigger_scope === 'global' ? 'Every matching page' : compactUrl(event.page_url))));
    if (trigger.selector) details.appendChild(detailRow('Selector', trigger.selector, true));
    details.appendChild(detailRow('Verification', trigger.requires_browser_preview ? 'Live preview required before publishing' : 'Automatic'));
    const tags = node('div', 'tracking-trigger-tags');
    (event.tag_templates || []).forEach(function (tag) {
      tags.appendChild(node('span', 'onboarding-chip subtle', tag.destination_event_name
        ? platformLabel(tag.platform) + ' → ' + tag.destination_event_name
        : platformLabel(tag.platform)));
    });
    details.appendChild(tags);
    if (event.mapping_id) {
      const edit = node('a', 'btn btn-secondary btn-sm', 'Edit visually in mapper');
      edit.href = 'element-mapper.php?client_id=' + encodeURIComponent(state.clientId) + '&mapping_id=' + encodeURIComponent(event.mapping_id) + '&return=workspace';
      details.appendChild(edit);
    }
    body.appendChild(config); body.appendChild(details); form.appendChild(body);

    const footer = node('div', 'tracking-event-card-footer');
    footer.hidden = !expanded;
    const footerCopy = node('p', '', 'Saving changes does not publish this event.');
    footer.appendChild(footerCopy);
    if (event.mapping_id) {
      const remove = node('button', 'btn btn-danger-outline btn-sm', 'Delete tag');
      remove.type = 'button';
      remove.addEventListener('click', function () { archiveMapping(event, remove); });
      footer.appendChild(remove);
    }
    const save = node('button', 'btn btn-primary btn-sm', 'Save event configuration');
    save.type = 'submit'; footer.appendChild(save); form.appendChild(footer);
    form.addEventListener('submit', function (submitEvent) { saveEvent(submitEvent, event); });
    expandButton.addEventListener('click', function () {
      const opening = state.expandedEventKey !== event.event_key;
      state.expandedEventKey = opening ? event.event_key : null;
      document.querySelectorAll('.tracking-event-card').forEach(function (card) {
        const isOpen = opening && card.dataset.eventKey === event.event_key;
        card.classList.toggle('expanded', isOpen);
        const cardBody = card.querySelector('.tracking-event-card-body');
        const cardFooter = card.querySelector('.tracking-event-card-footer');
        const cardButton = card.querySelector('.tracking-event-expand');
        cardBody.hidden = !isOpen; cardFooter.hidden = !isOpen;
        cardButton.setAttribute('aria-label', (isOpen ? 'Close ' : 'Edit ') + card.querySelector('.tracking-event-summary-name').textContent + ' details');
        cardButton.title = isOpen ? 'Close event details' : 'Edit event details';
        cardButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
    });
    includeButton.addEventListener('click', function () {
      includeInput.checked = !includeInput.checked;
      form.requestSubmit();
    });
    return form;
  }

  async function archiveMapping(event, button) {
    const confirmed = await window.showConfirm({
      title: 'Delete this mapped tag?',
      message: 'This removes “' + event.element_name + '” and every event created by that element mapping from the active workspace. Existing release history is preserved.',
      confirmLabel: 'Delete tag',
      destructive: true
    });
    if (!confirmed) return;
    button.disabled = true; button.textContent = 'Deleting...';
    try {
      await request('api/archive-element-mapping.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({client_id: state.clientId, mapping_id: event.mapping_id, csrf_token: csrfToken()})
      });
      state.expandedEventKey = null;
      showToast('Mapped tag removed. Existing release snapshots were preserved.', 'success');
      await load();
    } catch (error) {
      showToast(error.message, 'error');
      button.disabled = false; button.textContent = 'Delete tag';
    }
  }

  function detailRow(label, value, technical) {
    const row = node('div', 'tracking-trigger-detail');
    row.appendChild(node('span', '', label));
    row.appendChild(node(technical ? 'code' : 'strong', technical ? 'font-mono' : '', value));
    return row;
  }

  function safeId(value) { return value.replace(/[^a-zA-Z0-9_-]/g, '-'); }

  async function saveEvent(submitEvent, event) {
    submitEvent.preventDefault();
    const form = submitEvent.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const includeInput = form.querySelector('[data-role="enabled"]');
    const includeAction = form.querySelector('[data-role="include-action"]');
    const nameInput = form.querySelector('[data-role="event-name"]');
    const name = nameInput.value.trim();
    if (!/^[a-z][a-z0-9_]{0,39}$/.test(name)) {
      nameInput.classList.add('invalid');
      showToast('Event names must start with a letter and use only lowercase letters, numbers, and underscores.', 'error');
      return;
    }
    nameInput.classList.remove('invalid');
    const selectedParameters = Array.from(form.querySelectorAll('[data-role="parameter"]:checked')).map(function (input) { return input.value; });
    const eventGroup = form.querySelector('[data-role="event-group"]').value;
    const destinations = Array.from(form.querySelectorAll('[data-role="destination-enabled"]')).map(function (toggle) {
      const platform = toggle.dataset.platform;
      const destinationName = form.querySelector('[data-role="destination-name"][data-platform="' + platform + '"]');
      const parameters = Array.from(form.querySelectorAll('[data-role="destination-parameter"][data-platform="' + platform + '"]:checked'))
        .map(function (parameter) { return parameter.value; })
        .filter(function (parameter) { return selectedParameters.indexOf(parameter) !== -1; });
      const userIdentifiers = Array.from(form.querySelectorAll('[data-role="destination-identifier"][data-platform="' + platform + '"]:checked'))
        .map(function (identifier) { return identifier.value; });
      return {platform: platform, enabled: toggle.checked, event_name: destinationName.value.trim(), parameters: parameters, user_identifiers: userIdentifiers};
    });
    let destinationError = '';
    destinations.forEach(function (destination) {
      const destinationInput = form.querySelector('[data-role="destination-name"][data-platform="' + destination.platform + '"]');
      const error = destination.enabled ? destinationNameError(destination.platform, destination.event_name) : '';
      destinationInput.classList.toggle('invalid', !!error);
      if (!destinationError && error) {
        destinationError = error;
        destinationInput.closest('.tracking-destination-card').open = true;
        destinationInput.focus();
      }
    });
    if (destinationError) {
      showToast(destinationError, 'error');
      return;
    }
    const triggerSelect = form.querySelector('[data-role="trigger-intent"]');
    const requestedTriggerIntent = triggerSelect ? triggerSelect.value : '';
    const triggerChanged = requestedTriggerIntent && requestedTriggerIntent !== event.trigger_intent;
    button.disabled = true; includeInput.disabled = true; includeAction.disabled = true; button.textContent = 'Saving...';
    try {
      await request('api/save-tracking-event.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          client_id: state.clientId,
          event_key: event.event_key,
          approved_event_name: name,
          enabled: includeInput.checked,
          selected_parameters: selectedParameters,
          trigger_intent: requestedTriggerIntent,
          event_group: eventGroup,
          destinations: destinations,
          csrf_token: csrfToken()
        })
      });
      showToast(triggerChanged
        ? 'Trigger changed. Your custom event name was kept and the matching parameters were loaded.'
        : 'Event configuration saved. It is not live until you publish a release.', 'success');
      await load();
    } catch (error) {
      includeInput.checked = event.enabled;
      if (triggerSelect) triggerSelect.value = event.trigger_intent;
      showToast(error.message, 'error');
    } finally {
      button.disabled = false; includeInput.disabled = false; includeAction.disabled = false; button.textContent = 'Save event configuration';
    }
  }

  function renderPrivacy() {
    const privacy = state.privacy || {hashed_pii_enabled: false, allowed_hashed_fields: []};
    const mode = privacy.hashed_pii_enabled ? 'hashed' : 'off';
    const radio = document.querySelector('input[name="pii_policy"][value="' + mode + '"]');
    if (radio) radio.checked = true;
    document.getElementById('tracking-hashed-fields').hidden = mode !== 'hashed';
    document.getElementById('tracking-hash-demo-open').hidden = mode !== 'hashed';
    document.querySelectorAll('#tracking-hashed-fields input').forEach(function (input) {
      input.checked = privacy.allowed_hashed_fields.indexOf(input.value) !== -1;
    });
  }

  async function savePrivacy(event) {
    event.preventDefault();
    const hashed = document.querySelector('input[name="pii_policy"]:checked').value === 'hashed';
    const fields = Array.from(document.querySelectorAll('#tracking-hashed-fields input:checked')).map(function (input) { return input.value; });
    const button = document.getElementById('tracking-privacy-save');
    button.disabled = true; button.textContent = 'Saving...';
    try {
      state.privacy = await request('api/save-tracking-privacy.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({client_id: state.clientId, hashed_pii_enabled: hashed, allowed_hashed_fields: fields, csrf_token: csrfToken()})
      });
      showToast(hashed ? 'Hashed identifier policy saved. Consent remains required.' : 'Personal identifiers are disabled, including hashed identifiers.', 'success');
      renderPrivacy(); renderSummary();
    } catch (error) {
      showToast(error.message, 'error');
    } finally {
      button.disabled = false; button.textContent = 'Save privacy policy';
    }
  }

  function selectedIdentifierFields() {
    return Array.from(document.querySelectorAll('#tracking-hashed-fields input:checked')).map(function (input) { return input.value; });
  }

  function renderHashDemoFields() {
    const target = document.getElementById('tracking-hash-demo-fields');
    clear(target);
    const labels = {email: 'Email', phone: 'Phone', full_name: 'Full name', first_name: 'First name', last_name: 'Last name'};
    const placeholders = {email: 'example@company.com', phone: '+61 400 000 000', full_name: 'Eric Example', first_name: 'Eric', last_name: 'Example'};
    const fields = selectedIdentifierFields();
    if (!fields.length) {
      const empty = node('div', 'tracking-hash-empty');
      empty.appendChild(node('strong', '', 'No identifier fields selected'));
      empty.appendChild(node('p', '', 'Close this window and select at least one allowed field first.'));
      target.appendChild(empty);
    }
    fields.forEach(function (field) {
      const row = node('div', 'tracking-hash-demo-row');
      row.dataset.hashField = field;
      const label = node('label', 'form-label', labels[field] || field);
      const input = node('input', 'form-input');
      input.type = 'text'; input.autocomplete = 'off'; input.placeholder = placeholders[field] || 'Example value';
      input.id = 'hash-example-' + field; label.htmlFor = input.id;
      const result = node('div', 'tracking-hash-result'); result.hidden = true;
      result.appendChild(node('small', '', 'Normalised value'));
      result.appendChild(node('code', 'tracking-hash-normalised'));
      result.appendChild(node('small', '', 'SHA-256 output'));
      result.appendChild(node('code', 'tracking-hash-output'));
      const error = node('p', 'tracking-hash-field-error'); error.hidden = true;
      row.appendChild(label); row.appendChild(input); row.appendChild(result); row.appendChild(error); target.appendChild(row);
    });
    document.getElementById('tracking-hash-generate').disabled = fields.length === 0;
  }

  function openHashDemo() {
    const modal = document.getElementById('tracking-hash-modal');
    renderHashDemoFields();
    modal.hidden = false;
    document.body.classList.add('tracking-modal-open');
    requestAnimationFrame(function () {
      modal.classList.add('visible');
      const firstInput = modal.querySelector('input');
      (firstInput || document.getElementById('tracking-hash-close')).focus();
    });
  }

  function closeHashDemo() {
    const modal = document.getElementById('tracking-hash-modal');
    modal.classList.remove('visible');
    document.body.classList.remove('tracking-modal-open');
    window.setTimeout(function () { modal.hidden = true; }, 150);
    document.getElementById('tracking-hash-demo-open').focus();
  }

  async function previewDestinationPayload(event, platform, button) {
    const original = button.textContent;
    button.disabled = true; button.textContent = 'Preparing…';
    try {
      const data = await request('api/preview-destination-payload.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({client_id: state.clientId, event_key: event.event_key, platform: platform, csrf_token: csrfToken()})
      });
      document.getElementById('tracking-payload-title').textContent = platformLabel(platform) + ' payload preview';
      document.getElementById('tracking-payload-subtitle').textContent = event.configured_name + ' → ' + data.event_name;
      document.getElementById('tracking-canonical-payload').textContent = JSON.stringify(data.canonical_event, null, 2);
      document.getElementById('tracking-destination-payload').textContent = JSON.stringify(data.destination_payload, null, 2);
      const status = document.getElementById('tracking-payload-status');
      clear(status);
      status.appendChild(node('strong', '', data.connection_status === 'verified' ? 'Connection verified' : (data.connection_status === 'configured' ? 'Connection saved · verification pending' : 'Connection not configured')));
      (data.warnings || []).forEach(function (warning) { status.appendChild(node('p', '', warning)); });
      const modal = document.getElementById('tracking-payload-modal');
      modal.hidden = false; document.body.classList.add('tracking-modal-open');
      requestAnimationFrame(function () { modal.classList.add('visible'); document.getElementById('tracking-payload-close').focus(); });
    } catch (error) {
      showToast(error.message, 'error');
    } finally {
      button.disabled = false; button.textContent = original;
    }
  }

  function closePayloadPreview() {
    const modal = document.getElementById('tracking-payload-modal');
    modal.classList.remove('visible'); document.body.classList.remove('tracking-modal-open');
    window.setTimeout(function () { modal.hidden = true; }, 150);
  }

  async function showHashDemo() {
    const processor = window.TagVerityIdentifierProcessor;
    if (!processor) { showToast('The identifier processor could not be loaded.', 'error'); return; }
    const rows = Array.from(document.querySelectorAll('.tracking-hash-demo-row'));
    let completed = 0;
    for (const row of rows) {
      const value = row.querySelector('input').value;
      const result = row.querySelector('.tracking-hash-result');
      const error = row.querySelector('.tracking-hash-field-error');
      result.hidden = true; error.hidden = true;
      if (!value.trim()) continue;
      try {
        const processed = await processor.process(row.dataset.hashField, value, 'common');
        row.querySelector('.tracking-hash-normalised').textContent = processed.normalised;
        row.querySelector('.tracking-hash-output').textContent = processed.hashed;
        result.hidden = false;
        completed++;
      } catch (processingError) {
        error.textContent = processingError.message === 'e164_required'
          ? 'Use E.164 format with + and country code, for example +61412345678.'
          : 'Enter a valid value for this field.';
        error.hidden = false;
      }
    }
    if (!completed) showToast('Enter at least one example value first.', 'warning');
  }

  async function load() {
    try {
      const data = await request('api/get-tracking-workspace.php?client_id=' + encodeURIComponent(state.clientId));
      state.events = data.events || [];
      state.privacy = data.privacy || null;
      populateTypeFilters(); renderSummary(); renderEvents(); renderPrivacy();
    } catch (error) {
      showToast(error.message, 'error');
    }
  }

  document.querySelectorAll('.tracking-filter').forEach(function (button) {
    button.addEventListener('click', function () {
      state.filter = button.dataset.filter;
      state.page = 1;
      document.querySelectorAll('.tracking-filter').forEach(function (item) { item.classList.toggle('active', item === button); });
      renderEvents();
    });
  });
  document.getElementById('tracking-event-search').addEventListener('input', function () {
    state.search = this.value.trim().toLowerCase(); state.page = 1; renderEvents();
  });
  document.getElementById('tracking-tag-type-filter').addEventListener('change', function () {
    state.tagType = this.value; state.page = 1; renderEvents();
  });
  document.getElementById('tracking-trigger-type-filter').addEventListener('change', function () {
    state.triggerType = this.value; state.page = 1; renderEvents();
  });
  document.getElementById('tracking-page-size').addEventListener('change', function () {
    state.pageSize = Number(this.value) || 10; state.page = 1; renderEvents();
  });
  document.getElementById('tracking-page-previous').addEventListener('click', function () {
    if (state.page > 1) { state.page--; renderEvents(); }
  });
  document.getElementById('tracking-page-next').addEventListener('click', function () {
    state.page++; renderEvents();
  });
  document.querySelectorAll('input[name="pii_policy"]').forEach(function (input) {
    input.addEventListener('change', function () {
      const hashed = input.value === 'hashed';
      document.getElementById('tracking-hashed-fields').hidden = !hashed;
      document.getElementById('tracking-hash-demo-open').hidden = !hashed;
    });
  });
  document.getElementById('tracking-privacy-form').addEventListener('submit', savePrivacy);
  document.getElementById('tracking-hash-demo-open').addEventListener('click', openHashDemo);
  document.getElementById('tracking-hash-close').addEventListener('click', closeHashDemo);
  document.getElementById('tracking-hash-cancel').addEventListener('click', closeHashDemo);
  document.getElementById('tracking-hash-generate').addEventListener('click', function () { showHashDemo().catch(function () { showToast('The local hashes could not be generated.', 'error'); }); });
  document.getElementById('tracking-hash-modal').addEventListener('click', function (event) { if (event.target === this) closeHashDemo(); });
  document.getElementById('tracking-payload-close').addEventListener('click', closePayloadPreview);
  document.getElementById('tracking-payload-done').addEventListener('click', closePayloadPreview);
  document.getElementById('tracking-payload-modal').addEventListener('click', function (event) { if (event.target === this) closePayloadPreview(); });
  document.addEventListener('keydown', function (event) {
    const modal = document.getElementById('tracking-hash-modal');
    if (event.key === 'Escape' && !modal.hidden) closeHashDemo();
    const payloadModal = document.getElementById('tracking-payload-modal');
    if (event.key === 'Escape' && !payloadModal.hidden) closePayloadPreview();
  });
  load();
});
