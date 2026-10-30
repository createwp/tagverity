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
  const DESTINATION_MONOGRAMS = {ga4: 'GA', posthog: 'PH', meta: 'M', google_ads: 'GAds'};
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
  // Mirrors src/ManualParameters.php; the server stays authoritative.
  const MANUAL_PARAMETER_LIMIT = 10;
  const MANUAL_PARAMETER_IDENTIFIER_NAMES = ['email', 'phone', 'first_name', 'last_name', 'name', 'full_name', 'address', 'postcode', 'zip', 'dob', 'user_id', 'ip'];
  const MANUAL_PARAMETER_IDENTIFIER_FRAGMENTS = ['email', 'phone', 'password'];
  function manualParameterProblem(name, value, manual, available) {
    if (manual.length >= MANUAL_PARAMETER_LIMIT) return {field: 'name', message: 'An event can have up to 10 custom parameters.'};
    const identifierLike = MANUAL_PARAMETER_IDENTIFIER_NAMES.indexOf(name) !== -1
      || MANUAL_PARAMETER_IDENTIFIER_FRAGMENTS.some(function (fragment) { return name.indexOf(fragment) !== -1; });
    if (!/^[a-z][a-z0-9_]{0,39}$/.test(name) || identifierLike) {
      return {field: 'name', message: "Custom parameter names must start with a letter, use lowercase letters, numbers and underscores, and can't be personal information like email or phone."};
    }
    if (available.indexOf(name) !== -1 || manual.some(function (item) { return item.name === name; })) {
      return {field: 'name', message: 'That parameter already exists for this event.'};
    }
    if (!value || Array.from(value).length > 100 || /[\x00-\x1F\x7F]/.test(value)) {
      return {field: 'value', message: 'Custom values must be 1–100 characters of plain text.'};
    }
    return null;
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

    // One state object per card: chips and destination Sends read and write it; saveEvent() sends it.
    const cardState = {
      parameters: event.selected_parameters.slice(),
      manual: (event.manual_parameters || []).map(function (item) { return {name: item.name, value: item.value}; }),
      destinations: {}
    };
    (event.destinations || []).forEach(function (destination) {
      cardState.destinations[destination.platform] = {
        enabled: destination.enabled,
        event_name: destination.event_name,
        parameters: (destination.parameters || []).filter(function (item) { return item.selected; }).map(function (item) { return item.canonical_name; }),
        identifiers: (destination.user_identifiers || []).filter(function (item) { return item.selected; }).map(function (item) { return item.canonical_name; })
      };
    });
    form._cardState = cardState;

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
    const topRow = node('div', 'tracking-config-row');
    topRow.appendChild(nameGroup);

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
    topRow.appendChild(groupField);
    config.appendChild(topRow);

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
    const chips = node('div', 'tracking-parameter-chips');
    chips.dataset.role = 'parameter-chips';
    params.appendChild(chips);

    const addWrap = node('div', 'tracking-parameter-add');
    const addButton = node('button', 'chip-add', '+ Add parameter');
    addButton.type = 'button';
    addButton.setAttribute('aria-haspopup', 'true');
    addButton.dataset.role = 'parameter-add';
    const addPanel = node('div', 'tracking-parameter-popover');
    addPanel.dataset.role = 'parameter-popover';
    addPanel.appendChild(node('p', 'tracking-parameter-popover-title', 'Captured automatically'));
    const autoList = node('ul', 'tracking-parameter-options');
    autoList.dataset.role = 'parameter-options';
    addPanel.appendChild(autoList);

    const customBox = node('div', 'tracking-parameter-custom');
    customBox.appendChild(node('p', 'tracking-parameter-popover-title', 'Custom value'));
    const customNameGroup = node('div', 'form-group');
    const customNameLabel = node('label', 'form-label', 'Parameter name');
    const customNameInput = node('input', 'form-input font-mono');
    customNameInput.type = 'text'; customNameInput.autocomplete = 'off';
    customNameInput.dataset.role = 'custom-parameter-name';
    customNameInput.id = 'parameter-name-' + safeId(event.event_key); customNameLabel.htmlFor = customNameInput.id;
    customNameGroup.appendChild(customNameLabel); customNameGroup.appendChild(customNameInput);
    const customValueGroup = node('div', 'form-group');
    const customValueLabel = node('label', 'form-label', 'Value');
    const customValueInput = node('input', 'form-input');
    customValueInput.type = 'text'; customValueInput.autocomplete = 'off';
    customValueInput.dataset.role = 'custom-parameter-value';
    customValueInput.id = 'parameter-value-' + safeId(event.event_key); customValueLabel.htmlFor = customValueInput.id;
    customValueGroup.appendChild(customValueLabel); customValueGroup.appendChild(customValueInput);
    const customError = node('p', 'tracking-parameter-error');
    customError.hidden = true; customError.setAttribute('role', 'alert');
    const customAdd = node('button', 'btn btn-secondary btn-sm', 'Add');
    customAdd.type = 'button'; customAdd.dataset.role = 'custom-parameter-add';
    customBox.appendChild(customNameGroup); customBox.appendChild(customValueGroup);
    customBox.appendChild(customError); customBox.appendChild(customAdd);
    addPanel.appendChild(customBox);

    addWrap.appendChild(addButton); addWrap.appendChild(addPanel);
    params.appendChild(addWrap);
    config.appendChild(params);

    const popover = window.TagVerityUI.bindPopover(addButton, addPanel);
    function closeAddPanel() { if (popover) popover.close(); }

    function chipItems() {
      return cardState.parameters.map(function (name) { return {kind: 'auto', name: name}; })
        .concat(cardState.manual.map(function (item) { return {kind: 'manual', name: item.name, value: item.value}; }));
    }

    function renderParameterOptions() {
      clear(autoList);
      const available = event.available_parameters || [];
      const remaining = available.filter(function (name) { return cardState.parameters.indexOf(name) === -1; });
      if (!remaining.length) {
        autoList.appendChild(node('li', 'form-help', available.length
          ? 'Every automatic parameter is already captured.'
          : 'This event has no automatic parameters.'));
        return;
      }
      remaining.forEach(function (name) {
        const item = node('li');
        const option = node('button', 'tracking-parameter-option');
        option.type = 'button'; option.dataset.parameter = name;
        option.appendChild(node('code', '', name));
        option.appendChild(node('span', 'chip-auto', 'auto'));
        option.addEventListener('click', function () { addAutoParameter(name); });
        item.appendChild(option); autoList.appendChild(item);
      });
    }

    function renderParameters() {
      clear(chips);
      const items = chipItems();
      items.forEach(function (item) {
        const chip = node('span', 'chip');
        if (item.kind === 'manual') {
          chip.appendChild(node('code', 'tracking-chip-value', item.name + ' = ' + item.value));
          chip.appendChild(node('span', 'chip-auto', 'custom'));
        } else {
          chip.appendChild(node('code', 'tracking-chip-value', item.name));
        }
        const remove = node('button', 'chip-remove');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remove ' + item.name);
        remove.title = 'Remove ' + item.name;
        remove.appendChild(appIcon('x'));
        remove.addEventListener('click', function () { removeParameter(item.kind, item.name); });
        chip.appendChild(remove);
        chips.appendChild(chip);
      });
      if (!items.length) chips.appendChild(node('p', 'form-help', 'No parameters captured yet.'));
      renderParameterOptions();
    }

    function removeParameter(kind, name) {
      const index = chipItems().findIndex(function (item) { return item.kind === kind && item.name === name; });
      if (kind === 'manual') {
        cardState.manual = cardState.manual.filter(function (item) { return item.name !== name; });
      } else {
        cardState.parameters = cardState.parameters.filter(function (item) { return item !== name; });
      }
      Object.keys(cardState.destinations).forEach(function (platform) {
        const destination = cardState.destinations[platform];
        destination.parameters = destination.parameters.filter(function (item) { return item !== name; });
      });
      renderParameters();
      renderDestinationPanel();
      // The next chip slides into the removed chip's place; after the last chip, focus goes to the add button.
      const removeButtons = chips.querySelectorAll('.chip-remove');
      (removeButtons[index] || addButton).focus();
    }

    function addAutoParameter(name) {
      if (cardState.parameters.indexOf(name) === -1) cardState.parameters.push(name);
      closeAddPanel();
      renderParameters();
      renderDestinationPanel();
      addButton.focus();
    }

    function addCustomParameter() {
      const name = customNameInput.value.trim();
      const value = customValueInput.value.trim();
      const problem = manualParameterProblem(name, value, cardState.manual, event.available_parameters || []);
      if (problem) {
        customError.textContent = problem.message;
        customError.hidden = false;
        (problem.field === 'value' ? customValueInput : customNameInput).focus();
        return;
      }
      cardState.manual.push({name: name, value: value});
      customNameInput.value = ''; customValueInput.value = ''; customError.hidden = true;
      closeAddPanel();
      renderParameters();
      renderDestinationPanel();
      addButton.focus();
    }

    // Enter adds the custom value; it must not submit the card form.
    [customNameInput, customValueInput].forEach(function (input) {
      input.addEventListener('keydown', function (keyEvent) {
        if (keyEvent.key === 'Enter') { keyEvent.preventDefault(); addCustomParameter(); }
      });
      input.addEventListener('input', function () { customError.hidden = true; });
    });
    customAdd.addEventListener('click', addCustomParameter);
    renderParameters();

    // Destinations: a row of pills and ONE config panel for the selected pill.
    // Every edit writes to cardState.destinations; the panel is rebuilt from that state whenever it is shown.
    const destinations = event.destinations || [];
    const destinationById = {};
    destinations.forEach(function (destination) { destinationById[destination.platform] = destination; });
    const MAX_VISIBLE_PILLS = 4;
    const visibleDestinations = destinations.slice(0, MAX_VISIBLE_PILLS);
    const overflowDestinations = destinations.slice(MAX_VISIBLE_PILLS);
    const destinationErrors = {};
    const pillButtons = {};
    const rowButtons = {};
    const pillRow = node('div', 'tracking-destination-grid');
    const panelHost = node('div', 'tracking-destination-host');
    panelHost.id = 'destination-panel-' + safeId(event.event_key);
    panelHost.hidden = true;
    let openPlatform = null;
    let moreButton = null;

    function monogram(platform) {
      return DESTINATION_MONOGRAMS[platform] || ((destinationById[platform] || {}).label || platform).substring(0, 2).toUpperCase();
    }
    function destinationMark(platform) {
      const mark = node('span', 'destination-mark destination-mark-' + platform, monogram(platform));
      mark.setAttribute('aria-hidden', 'true');
      return mark;
    }
    function buildDestinationButton(destination, className) {
      const button = node('button', className);
      button.type = 'button';
      button.dataset.platform = destination.platform;
      button.setAttribute('aria-controls', panelHost.id);
      button.appendChild(destinationMark(destination.platform));
      button.appendChild(node('span', 'destination-pill-label', destination.label));
      button.appendChild(node('span', 'destination-pill-dot'));
      button.appendChild(node('span', 'destination-pill-state sr-only', ''));
      return button;
    }
    function paintDestinationButton(button, platform) {
      if (!button) return;
      const on = cardState.destinations[platform].enabled;
      button.setAttribute('aria-pressed', openPlatform === platform ? 'true' : 'false');
      button.querySelector('.destination-pill-dot').classList.toggle('on', on);
      button.querySelector('.destination-pill-state').textContent = on ? '(on)' : '(off)';
    }
    function refreshDestinationButtons() {
      visibleDestinations.forEach(function (destination) { paintDestinationButton(pillButtons[destination.platform], destination.platform); });
      overflowDestinations.forEach(function (destination) { paintDestinationButton(rowButtons[destination.platform], destination.platform); });
      if (moreButton) {
        moreButton.classList.toggle('active', overflowDestinations.some(function (destination) { return destination.platform === openPlatform; }));
      }
    }

    visibleDestinations.forEach(function (destination) {
      const pill = buildDestinationButton(destination, 'destination-pill');
      pill.addEventListener('click', function () { togglePlatform(destination.platform); });
      pillButtons[destination.platform] = pill;
      pillRow.appendChild(pill);
    });
    if (overflowDestinations.length) {
      const moreWrap = node('div', 'destination-more');
      const more = node('button', 'destination-pill destination-pill-more', '+' + overflowDestinations.length + ' more');
      more.type = 'button';
      more.setAttribute('aria-haspopup', 'true');
      moreButton = more;
      const morePanel = node('div', 'destination-more-popover');
      const search = node('input', 'form-input destination-more-search');
      search.type = 'search'; search.autocomplete = 'off'; search.placeholder = 'Search destinations';
      search.setAttribute('aria-label', 'Search destinations');
      const rows = node('ul', 'destination-more-rows');
      const emptyNote = node('p', 'form-help destination-more-empty', 'No destinations match.');
      emptyNote.hidden = true;
      const rowItems = [];
      overflowDestinations.forEach(function (destination) {
        const item = node('li');
        const row = buildDestinationButton(destination, 'destination-pill-row');
        row.addEventListener('click', function () {
          morePopover.close();
          showPlatform(destination.platform, true);
        });
        rowButtons[destination.platform] = row;
        item.appendChild(row);
        rows.appendChild(item);
        rowItems.push({element: item, search: (destination.label + ' ' + destination.platform).toLowerCase()});
      });
      search.addEventListener('input', function () {
        const query = search.value.trim().toLowerCase();
        let shown = 0;
        rowItems.forEach(function (entry) {
          const match = !query || entry.search.indexOf(query) !== -1;
          entry.element.hidden = !match;
          if (match) shown += 1;
        });
        emptyNote.hidden = shown > 0;
      });
      morePanel.appendChild(search); morePanel.appendChild(rows); morePanel.appendChild(emptyNote);
      const morePopover = window.TagVerityUI.bindPopover(more, morePanel);
      more.addEventListener('click', function () { if (!morePanel.hidden) search.focus(); });
      moreWrap.appendChild(more); moreWrap.appendChild(morePanel);
      pillRow.appendChild(moreWrap);
    }

    function capturedNames() {
      return cardState.parameters.concat(cardState.manual.map(function (item) { return item.name; }));
    }
    // A captured parameter as this destination sees it, or null when the destination cannot take it.
    // Manual parameters added this session are not in destination.parameters yet, so they are offered here.
    function destinationOption(platform, name) {
      if (capturedNames().indexOf(name) === -1) return null;
      const known = (destinationById[platform].parameters || []).filter(function (option) { return option.canonical_name === name; })[0];
      if (known) return known;
      if (!cardState.manual.some(function (item) { return item.name === name; })) return null;
      return {
        canonical_name: name,
        destination_name: platform === 'meta' ? 'custom_data.' + name : name,
        manual: true,
        supported: platform !== 'google_ads'
      };
    }
    function sendsOptions(platform) {
      return cardState.destinations[platform].parameters
        .map(function (name) { return destinationOption(platform, name); })
        .filter(Boolean);
    }
    function addableOptions(platform) {
      const sent = cardState.destinations[platform].parameters;
      return capturedNames()
        .filter(function (name) { return sent.indexOf(name) === -1; })
        .map(function (name) { return destinationOption(platform, name); })
        .filter(Boolean);
    }

    function buildDestinationPanel(platform) {
      const destination = destinationById[platform];
      const route = cardState.destinations[platform];
      const panel = node('div', 'tracking-destination-panel');
      panel.dataset.platform = platform;

      const sendRow = node('label', 'tracking-destination-switch');
      const sendToggle = node('input');
      sendToggle.type = 'checkbox';
      sendToggle.checked = route.enabled;
      sendToggle.dataset.role = 'destination-enabled';
      sendToggle.dataset.platform = platform;
      sendRow.appendChild(sendToggle);
      sendRow.appendChild(node('span', '', 'Send this event to ' + destination.label));
      sendToggle.addEventListener('change', function () {
        route.enabled = sendToggle.checked;
        showPlatform(platform, true);
      });
      panel.appendChild(sendRow);

      const controls = node('div', 'tracking-destination-controls');
      const recommendationGroup = node('div', 'form-group');
      const recommendationLabel = node('label', 'form-label', 'Recommended mapping');
      const recommendationSelect = node('select', 'form-select');
      recommendationSelect.dataset.role = 'destination-recommendation';
      recommendationSelect.dataset.platform = platform;
      recommendationSelect.disabled = !route.enabled;
      recommendationSelect.id = 'destination-recommendation-' + platform + '-' + safeId(event.event_key);
      recommendationLabel.htmlFor = recommendationSelect.id;
      recommendationGroup.appendChild(recommendationLabel); recommendationGroup.appendChild(recommendationSelect);
      const valueGroup = node('div', 'form-group');
      const valueLabel = node('label', 'form-label', destination.field_label);
      const valueInput = node('input', 'form-input');
      valueInput.type = 'text'; valueInput.maxLength = destination.max_length || 80; valueInput.value = route.event_name;
      valueInput.dataset.role = 'destination-name'; valueInput.dataset.platform = platform;
      valueInput.disabled = !route.enabled;
      valueInput.id = 'destination-' + platform + '-' + safeId(event.event_key);
      valueLabel.htmlFor = valueInput.id;
      if (destinationErrors[platform]) valueInput.classList.add('invalid');
      valueGroup.appendChild(valueLabel); valueGroup.appendChild(valueInput);
      controls.appendChild(recommendationGroup); controls.appendChild(valueGroup);
      panel.appendChild(controls);
      panel.appendChild(node('p', 'form-help tracking-destination-format', destination.format_help));

      function setName(value) {
        route.event_name = value;
        destinationErrors[platform] = '';
        valueInput.classList.remove('invalid');
      }
      function syncRecommendation() {
        const value = valueInput.value.trim();
        const values = Array.from(recommendationSelect.options).map(function (option) { return option.value; });
        recommendationSelect.value = values.indexOf(value) !== -1 ? value : '__custom__';
      }
      function populateRecommendations() {
        clear(recommendationSelect);
        const recommendations = (destination.recommendations_by_group || {})[groupSelect.value] || destination.recommendations || [];
        recommendations.forEach(function (recommendation) {
          const option = node('option', '', recommendation);
          option.value = recommendation; recommendationSelect.appendChild(option);
        });
        const customOption = node('option', '', 'Custom name…');
        customOption.value = '__custom__'; recommendationSelect.appendChild(customOption);
        syncRecommendation();
      }
      populateRecommendations();
      valueInput.addEventListener('input', function () {
        setName(valueInput.value);
        syncRecommendation();
      });
      recommendationSelect.addEventListener('change', function () {
        if (recommendationSelect.value === '__custom__') {
          valueInput.focus();
          return;
        }
        valueInput.value = recommendationSelect.value;
        setName(recommendationSelect.value);
      });

      // Sends: this destination's subset of the captured parameters.
      const sends = node('fieldset', 'tracking-destination-sends');
      sends.appendChild(node('legend', '', 'Sends'));
      sends.appendChild(node('p', 'form-help', 'Only parameters captured above can be sent here.'));
      const sendsList = node('div', 'tracking-destination-sends-list');
      const sentOptions = sendsOptions(platform);
      sentOptions.forEach(function (option, index) {
        const chip = node('span', 'chip');
        chip.dataset.role = 'destination-parameter';
        chip.dataset.parameter = option.canonical_name;
        chip.appendChild(node('code', 'tracking-chip-value', option.canonical_name));
        if (option.destination_name && option.destination_name !== option.canonical_name) {
          chip.appendChild(node('span', 'destination-sends-target', '→ ' + option.destination_name));
        }
        const remove = node('button', 'chip-remove');
        remove.type = 'button';
        remove.disabled = !route.enabled;
        remove.setAttribute('aria-label', 'Stop sending ' + option.canonical_name + ' to ' + destination.label);
        remove.title = remove.getAttribute('aria-label');
        remove.appendChild(appIcon('x'));
        remove.addEventListener('click', function () {
          route.parameters = route.parameters.filter(function (name) { return name !== option.canonical_name; });
          renderDestinationPanel();
          // The next chip takes this one's place; after the last chip, focus goes to + Add.
          const removes = panelHost.querySelectorAll('.tracking-destination-sends-list .chip-remove');
          const target = removes[index] || panelHost.querySelector('.destination-add-button');
          if (target) target.focus();
        });
        chip.appendChild(remove);
        sendsList.appendChild(chip);
      });
      if (!sentOptions.length) sendsList.appendChild(node('p', 'form-help', 'Nothing is sent to ' + destination.label + ' yet.'));
      sends.appendChild(sendsList);

      const addWrap = node('div', 'tracking-parameter-add');
      const addButton = node('button', 'chip-add destination-add-button', '+ Add');
      addButton.type = 'button';
      addButton.disabled = !route.enabled;
      addButton.setAttribute('aria-haspopup', 'true');
      const addPanel = node('div', 'tracking-parameter-popover');
      addPanel.appendChild(node('p', 'tracking-parameter-popover-title', 'Captured by this event'));
      const addList = node('ul', 'tracking-parameter-options');
      const addMenu = window.TagVerityUI.bindPopover(addButton, addPanel);
      const addable = addableOptions(platform);
      if (!addable.length) {
        addList.appendChild(node('li', 'form-help', capturedNames().length
          ? 'Every captured parameter is already sent here.'
          : 'Capture a parameter above first.'));
      }
      addable.forEach(function (option) {
        const item = node('li');
        const choice = node('button', 'tracking-parameter-option');
        choice.type = 'button';
        choice.dataset.parameter = option.canonical_name;
        choice.appendChild(node('code', '', option.canonical_name));
        choice.appendChild(node('span', 'chip-auto', option.manual ? 'custom' : 'auto'));
        if (option.supported === false) {
          choice.disabled = true;
          choice.appendChild(node('span', 'form-help', platformLabel(platform) + " can't receive custom parameters"));
        } else {
          choice.addEventListener('click', function () {
            if (route.parameters.indexOf(option.canonical_name) === -1) route.parameters.push(option.canonical_name);
            addMenu.close();
            renderDestinationPanel();
            const next = panelHost.querySelector('.destination-add-button');
            if (next) next.focus();
          });
        }
        item.appendChild(choice);
        addList.appendChild(item);
      });
      addPanel.appendChild(addList);
      addWrap.appendChild(addButton); addWrap.appendChild(addPanel);
      sends.appendChild(addWrap);
      panel.appendChild(sends);

      const identifiers = node('fieldset', 'tracking-destination-identifiers');
      identifiers.appendChild(node('legend', '', 'User identifiers'));
      identifiers.appendChild(node('p', 'form-help', destination.identifier_notice));
      (destination.user_identifiers || []).forEach(function (identifier) {
        const identifierLabel = node('label', 'tracking-destination-parameter tracking-destination-identifier');
        const identifierToggle = node('input');
        identifierToggle.type = 'checkbox';
        identifierToggle.checked = route.identifiers.indexOf(identifier.canonical_name) !== -1;
        identifierToggle.dataset.role = 'destination-identifier';
        identifierToggle.dataset.platform = platform;
        identifierToggle.value = identifier.canonical_name;
        identifierToggle.disabled = !route.enabled;
        identifierToggle.addEventListener('change', function () {
          route.identifiers = route.identifiers.filter(function (name) { return name !== identifier.canonical_name; });
          if (identifierToggle.checked) route.identifiers.push(identifier.canonical_name);
        });
        identifierLabel.appendChild(identifierToggle);
        const identifierCopy = node('span');
        identifierCopy.appendChild(node('code', '', identifier.canonical_name));
        identifierCopy.appendChild(node('span', '', '→ SHA-256 →'));
        identifierCopy.appendChild(node('code', '', identifier.destination_name));
        identifierLabel.appendChild(identifierCopy);
        identifiers.appendChild(identifierLabel);
      });
      panel.appendChild(identifiers);

      const previewActions = node('div', 'tracking-destination-preview-actions');
      const previewButton = node('button', 'btn btn-secondary btn-sm', 'Preview saved payload');
      previewButton.type = 'button';
      previewButton.disabled = !route.enabled;
      previewButton.addEventListener('click', function () { previewDestinationPayload(event, platform, previewButton); });
      previewActions.appendChild(node('p', 'form-help', 'Uses generated sample values. No event is sent.'));
      previewActions.appendChild(previewButton);
      panel.appendChild(previewActions);
      return panel;
    }

    function renderDestinationPanel() {
      clear(panelHost);
      panelHost.hidden = !openPlatform;
      if (openPlatform) panelHost.appendChild(buildDestinationPanel(openPlatform));
      refreshDestinationButtons();
    }
    function showPlatform(platform, focusSwitch) {
      openPlatform = platform;
      renderDestinationPanel();
      if (focusSwitch) {
        const switchInput = panelHost.querySelector('[data-role="destination-enabled"]');
        if (switchInput) switchInput.focus();
      }
    }
    function togglePlatform(platform) {
      openPlatform = openPlatform === platform ? null : platform;
      renderDestinationPanel();
    }

    const destinationFieldset = node('fieldset', 'tracking-destination-fieldset');
    destinationFieldset.appendChild(node('legend', '', 'Destinations'));
    destinationFieldset.appendChild(node('p', 'form-help', 'Send this one event to one or more platforms. Each platform can use its own name.'));
    destinationFieldset.appendChild(pillRow);
    destinationFieldset.appendChild(panelHost);
    config.appendChild(destinationFieldset);
    groupSelect.addEventListener('change', function () { if (openPlatform) renderDestinationPanel(); });

    const firstEnabled = destinations.filter(function (destination) { return cardState.destinations[destination.platform].enabled; })[0];
    openPlatform = firstEnabled ? firstEnabled.platform : null;
    form._destinationPanel = {
      errors: destinationErrors,
      reveal: function (platform) {
        showPlatform(platform, false);
        const nameInput = panelHost.querySelector('[data-role="destination-name"]');
        if (nameInput) nameInput.focus();
      }
    };
    renderDestinationPanel();

    // Trigger: an always-visible summary; the chevron reveals the detail rows.
    const hasTriggerIntent = (event.available_trigger_intents || []).length > 0;
    const coverageText = event.coverage_label || (event.trigger_scope === 'global' ? 'Every matching page' : compactUrl(event.page_url));
    const triggerSummary = node('div', 'tracking-trigger-summary');
    const triggerMain = node('div', 'tracking-trigger-summary-main');
    triggerMain.appendChild(node('span', 'onboarding-chip', triggerLabel(trigger)));
    triggerMain.appendChild(node('span', 'tracking-trigger-runs', 'Runs on ' + coverageText));
    const triggerActions = node('div', 'tracking-trigger-summary-actions');
    if (event.mapping_id) {
      const editTrigger = node('a', 'btn btn-secondary btn-sm tracking-trigger-edit');
      editTrigger.href = 'element-mapper.php?client_id=' + encodeURIComponent(state.clientId) + '&mapping_id=' + encodeURIComponent(event.mapping_id) + '&return=workspace';
      editTrigger.appendChild(appIcon('pencil'));
      editTrigger.appendChild(node('span', '', 'Edit trigger'));
      triggerActions.appendChild(editTrigger);
    } else if (hasTriggerIntent) {
      const editTrigger = node('button', 'btn btn-secondary btn-sm tracking-trigger-edit');
      editTrigger.type = 'button';
      editTrigger.appendChild(appIcon('pencil'));
      editTrigger.appendChild(node('span', '', 'Edit trigger'));
      editTrigger.addEventListener('click', function () {
        const select = form.querySelector('[data-role="trigger-intent"]');
        if (select) select.focus();
      });
      triggerActions.appendChild(editTrigger);
    } else {
      triggerActions.appendChild(node('p', 'tracking-trigger-note', 'This trigger comes from your measurement plan'));
    }
    const triggerDetailsId = 'trigger-details-' + safeId(event.event_key);
    const triggerToggle = node('button', 'tracking-icon-action tracking-trigger-toggle');
    triggerToggle.type = 'button';
    triggerToggle.setAttribute('aria-expanded', 'false');
    triggerToggle.setAttribute('aria-controls', triggerDetailsId);
    triggerToggle.setAttribute('aria-label', 'Show trigger details');
    triggerToggle.title = 'Show trigger details';
    triggerToggle.appendChild(appIcon('chevron'));
    triggerActions.appendChild(triggerToggle);
    triggerSummary.appendChild(triggerMain); triggerSummary.appendChild(triggerActions);

    const triggerDetails = node('div', 'tracking-trigger-details');
    triggerDetails.id = triggerDetailsId;
    triggerDetails.hidden = true;
    if (trigger.selector) triggerDetails.appendChild(detailRow('Selector', trigger.selector, true));
    triggerDetails.appendChild(detailRow('Verification', trigger.requires_browser_preview ? 'Live preview required before publishing' : 'Automatic'));
    const tags = node('div', 'tracking-trigger-tags');
    (event.tag_templates || []).forEach(function (tag) {
      tags.appendChild(node('span', 'onboarding-chip subtle', tag.destination_event_name
        ? platformLabel(tag.platform) + ' → ' + tag.destination_event_name
        : platformLabel(tag.platform)));
    });
    triggerDetails.appendChild(tags);
    triggerToggle.addEventListener('click', function () {
      const opening = triggerDetails.hidden;
      triggerDetails.hidden = !opening;
      triggerToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });

    body.appendChild(triggerSummary); body.appendChild(triggerDetails); body.appendChild(config); form.appendChild(body);

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
    const cardState = form._cardState;
    const selectedParameters = cardState.parameters.slice();
    const manualParameters = cardState.manual.map(function (item) { return {name: item.name, value: item.value}; });
    const routableParameters = selectedParameters.concat(manualParameters.map(function (item) { return item.name; }));
    const eventGroup = form.querySelector('[data-role="event-group"]').value;
    // Each route is built entirely from cardState; the DOM is not read for destinations.
    const destinations = (event.destinations || []).map(function (destination) {
      const route = cardState.destinations[destination.platform];
      return {
        platform: destination.platform,
        enabled: route.enabled,
        event_name: route.event_name.trim(),
        parameters: route.parameters.filter(function (parameter) { return routableParameters.indexOf(parameter) !== -1; }),
        user_identifiers: route.identifiers.slice()
      };
    });
    const destinationErrors = form._destinationPanel.errors;
    let firstInvalid = null;
    destinations.forEach(function (destination) {
      const error = destination.enabled ? destinationNameError(destination.platform, destination.event_name) : '';
      destinationErrors[destination.platform] = error;
      if (error && !firstInvalid) firstInvalid = {platform: destination.platform, message: error};
    });
    if (firstInvalid) {
      form._destinationPanel.reveal(firstInvalid.platform);
      showToast(firstInvalid.message, 'error');
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
          manual_parameters: manualParameters,
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
      // Disabling the focused button drops focus to the body; hand it back so the drawer trap still applies.
      if (document.activeElement === document.body) button.focus();
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
    // The opener sits inside the privacy drawer, so focus returns there and not to the page.
    document.getElementById('tracking-hash-demo-open').focus();
  }

  // Keeps Tab and Shift+Tab inside the open SHA-256 example. The drawer trap in app.js is bypassed while this is open.
  function trapHashDemoTab(event, modal) {
    const items = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled])')).filter(function (item) {
      return item.getClientRects().length > 0;
    });
    if (!items.length) { event.preventDefault(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    const outside = !modal.contains(active);
    if (event.shiftKey && (active === first || outside)) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (active === last || outside)) {
      event.preventDefault(); first.focus();
    }
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
  // Privacy drawer: opened from the header lock button. TagVerityUI handles Escape, the backdrop and focus return;
  // the observer keeps aria-expanded in step however the drawer closes.
  const privacyDrawer = document.getElementById('privacy-drawer');
  const privacyOpenButton = document.getElementById('privacy-drawer-open');
  new MutationObserver(function () {
    privacyOpenButton.setAttribute('aria-expanded', privacyDrawer.classList.contains('open') ? 'true' : 'false');
  }).observe(privacyDrawer, {attributes: true, attributeFilter: ['class']});
  privacyOpenButton.addEventListener('click', function () { window.TagVerityUI.openDrawer(privacyDrawer, privacyOpenButton); });
  document.getElementById('privacy-drawer-close').addEventListener('click', function () { window.TagVerityUI.closeDrawer(privacyDrawer); });

  document.getElementById('tracking-privacy-form').addEventListener('submit', savePrivacy);
  document.getElementById('tracking-hash-demo-open').addEventListener('click', openHashDemo);
  document.getElementById('tracking-hash-close').addEventListener('click', closeHashDemo);
  document.getElementById('tracking-hash-cancel').addEventListener('click', closeHashDemo);
  document.getElementById('tracking-hash-generate').addEventListener('click', function () { showHashDemo().catch(function () { showToast('The local hashes could not be generated.', 'error'); }); });
  document.getElementById('tracking-hash-modal').addEventListener('click', function (event) { if (event.target === this) closeHashDemo(); });
  document.getElementById('tracking-payload-close').addEventListener('click', closePayloadPreview);
  document.getElementById('tracking-payload-done').addEventListener('click', closePayloadPreview);
  document.getElementById('tracking-payload-modal').addEventListener('click', function (event) { if (event.target === this) closePayloadPreview(); });
  // The SHA-256 example opens above the privacy drawer. Capture-phase handling lets it own Escape and Tab
  // while it is open, so the drawer's document-level handlers in app.js never see those keys.
  document.addEventListener('keydown', function (event) {
    const modal = document.getElementById('tracking-hash-modal');
    if (!modal.hidden) {
      if (event.key === 'Escape') {
        event.preventDefault(); event.stopPropagation();
        closeHashDemo();
        return;
      }
      if (event.key === 'Tab') {
        event.stopPropagation();
        trapHashDemoTab(event, modal);
        return;
      }
    }
    const payloadModal = document.getElementById('tracking-payload-modal');
    if (event.key === 'Escape' && !payloadModal.hidden) closePayloadPreview();
  }, true);
  load();
});
