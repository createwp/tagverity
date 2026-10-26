'use strict';

document.addEventListener('DOMContentLoaded', function () {
  const app = document.getElementById('onboarding-app');
  if (!app) return;

  const state = {
    clientId: Number(app.dataset.clientId),
    apiBase: (app.dataset.apiBase || '').replace(/\/$/, ''),
    scan: null,
    questionnaire: null,
    answers: null,
    plan: null,
    currentStep: 'scan',
    maxStep: 0
  };

  const elements = {
    scanForm: document.getElementById('scan-form'),
    siteUrl: document.getElementById('site-url'),
    scanButton: document.getElementById('scan-button'),
    progress: document.getElementById('scan-progress'),
    questionsForm: document.getElementById('questionnaire-form'),
    questions: document.getElementById('questionnaire-fields'),
    panels: Array.from(document.querySelectorAll('[data-step-panel]')),
    stepItems: Array.from(document.querySelectorAll('.onboarding-steps li')),
    stepButtons: Array.from(document.querySelectorAll('[data-step-target]'))
  };

  const stepOrder = ['scan', 'review', 'verify', 'ready'];

  function endpoint(path) {
    return state.apiBase + '/' + path.replace(/^\//, '');
  }

  function node(tag, className, text) {
    const item = document.createElement(tag);
    if (className) item.className = className;
    if (text !== undefined && text !== null) item.textContent = String(text);
    return item;
  }

  function clear(target) {
    if (!target) return;
    while (target.firstChild) target.removeChild(target.firstChild);
  }

  function csrfToken() {
    return app.querySelector('input[name="csrf_token"]').value;
  }

  async function request(path, options) {
    const response = await fetch(endpoint(path), options);
    let payload;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('The server returned an unreadable response.');
    }
    if (!response.ok || payload.error) {
      throw new Error(payload.message || 'Something went wrong.');
    }
    return payload.data;
  }

  function unlockStep(step) {
    const index = stepOrder.indexOf(step);
    if (index > state.maxStep) state.maxStep = index;
  }

  function showStep(step, options) {
    const current = stepOrder.indexOf(step);
    if (current < 0 || current > state.maxStep) return;
    state.currentStep = step;

    elements.panels.forEach(function (panel) {
      panel.hidden = panel.dataset.stepPanel !== step;
    });
    elements.stepItems.forEach(function (item) {
      const index = stepOrder.indexOf(item.dataset.step);
      item.classList.toggle('active', index === current);
      item.classList.toggle('complete', index < current);
      const button = item.querySelector('[data-step-target]');
      if (button) {
        button.disabled = index > state.maxStep;
        if (index === current) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
      }
    });

    if (!options || options.scroll !== false) {
      app.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
  }

  function setScanning(scanning) {
    elements.progress.hidden = !scanning;
    elements.scanButton.disabled = scanning;
    elements.scanButton.textContent = scanning ? 'Scanning...' : 'Scan website';
  }

  function addDiscoveryGroup(target, heading, items, emptyText) {
    const group = node('div', 'onboarding-discovery-group');
    group.appendChild(node('h4', '', heading));
    if (!items.length) {
      group.appendChild(node('p', 'text-muted text-sm', emptyText));
    } else {
      const list = node('ul', 'onboarding-plain-list');
      items.forEach(function (item) { list.appendChild(node('li', '', item)); });
      group.appendChild(list);
    }
    target.appendChild(group);
  }

  function renderScan(scan) {
    state.scan = scan;
    const data = scan.data || {};
    const summary = data.summary || {};
    const coverage = data.coverage || {};
    elements.siteUrl.value = data.root_url || elements.siteUrl.value;

    const coverageTarget = document.getElementById('coverage-summary');
    clear(coverageTarget);
    [
      [coverage.pages_scanned || 0, 'Pages inspected'],
      [coverage.pages_discovered || 0, 'Pages discovered'],
      [(summary.forms || []).length, 'Forms found'],
      [labelBusinessType(summary.suggested_business_type), 'Suggested model']
    ].forEach(function (metric) {
      const card = node('div', 'onboarding-metric');
      card.appendChild(node('strong', '', metric[0]));
      card.appendChild(node('span', '', metric[1]));
      coverageTarget.appendChild(card);
    });

    const offers = document.getElementById('offer-discoveries');
    clear(offers);
    addDiscoveryGroup(offers, 'Services', summary.services || [], 'No clear services were found in the sampled HTML.');
    addDiscoveryGroup(offers, 'Products', summary.products || [], 'No clear products were found in the sampled HTML.');

    const features = document.getElementById('feature-discoveries');
    clear(features);
    const formNames = (summary.forms || []).map(function (form) { return form.suggested_name; });
    addDiscoveryGroup(features, 'Forms', formNames, 'No public HTML forms were found.');
    const featureNames = [];
    if (summary.features && summary.features.site_search) featureNames.push('Site search');
    if (summary.features && summary.features.filters) featureNames.push('Filters or sorting');
    if (summary.features && summary.features.multistep_forms) featureNames.push('Possible multi-step form');
    if (summary.features && summary.features.spa_signals) featureNames.push('Possible SPA routes');
    addDiscoveryGroup(features, 'Behaviour', featureNames, 'No search, filter, multi-step, or SPA signals were confirmed.');

    const technologies = document.getElementById('technology-discoveries');
    clear(technologies);
    const tech = summary.technologies || [];
    if (!tech.length) technologies.appendChild(node('span', 'text-muted text-sm', 'No supported technology signatures were confirmed.'));
    tech.forEach(function (name) { technologies.appendChild(node('span', 'onboarding-chip', name)); });
    document.getElementById('coverage-note').textContent = coverage.note || '';
  }

  function labelBusinessType(value) {
    const labels = {
      lead_generation: 'Lead generation',
      ecommerce: 'Ecommerce',
      saas: 'SaaS / membership',
      hybrid: 'Hybrid'
    };
    return labels[value] || 'Needs review';
  }

  function renderQuestionnaire(questionnaire, answers) {
    state.questionnaire = questionnaire;
    state.answers = answers || {};
    clear(elements.questions);
    document.getElementById('questionnaire-intro').textContent = questionnaire.intro || '';

    (questionnaire.questions || []).forEach(function (question) {
      const wrapper = node('fieldset', 'card onboarding-question');
      wrapper.dataset.questionId = question.id;
      const legend = node('legend', 'onboarding-question-title', question.label);
      wrapper.appendChild(legend);
      if (question.help) wrapper.appendChild(node('p', 'form-help', question.help));
      const current = state.answers[question.id];

      if (question.type === 'single_choice' || question.type === 'multi_choice') {
        const choices = node('div', 'onboarding-choices');
        (question.options || []).forEach(function (option) {
          const label = node('label', 'onboarding-choice');
          const input = document.createElement('input');
          input.type = question.type === 'single_choice' ? 'radio' : 'checkbox';
          input.name = question.id + (question.type === 'multi_choice' ? '[]' : '');
          input.value = option.value;
          input.checked = question.type === 'single_choice'
            ? (current !== undefined ? current === option.value : question.suggested === option.value)
            : (Array.isArray(current) && current.includes(option.value));
          label.appendChild(input);
          label.appendChild(node('span', '', option.label));
          choices.appendChild(label);
        });
        wrapper.appendChild(choices);
      }

      if (question.type === 'list') {
        const textarea = node('textarea', 'form-textarea onboarding-list-input');
        textarea.rows = 5;
        textarea.value = (Array.isArray(current) ? current : (question.suggested || [])).join('\n');
        wrapper.appendChild(textarea);
      }

      if (question.type === 'form_labels') {
        const formList = node('div', 'onboarding-form-labels');
        const suggestions = question.suggested || [];
        if (!suggestions.length) {
          formList.appendChild(node('p', 'text-muted text-sm', 'No public forms were found. You can add form mapping during visual preview later.'));
        }
        suggestions.forEach(function (form) {
          const row = node('label', 'onboarding-form-label-row');
          const details = node('span', 'onboarding-form-source');
          details.appendChild(node('strong', '', form.suggested_name || 'Unnamed form'));
          details.appendChild(node('small', '', compactUrl(form.page_url || '')));
          const input = node('input', 'form-input');
          input.type = 'text';
          input.dataset.formKey = form.key;
          input.maxLength = 120;
          input.value = current && current[form.key] ? current[form.key] : (form.suggested_name || '');
          input.placeholder = 'Business name for this form';
          row.appendChild(details);
          row.appendChild(input);
          formList.appendChild(row);
        });
        wrapper.appendChild(formList);
      }
      elements.questions.appendChild(wrapper);
    });

  }

  function compactUrl(url) {
    try {
      const parsed = new URL(url);
      return parsed.pathname || '/';
    } catch (error) {
      return url;
    }
  }

  function collectAnswers() {
    const answers = {};
    elements.questions.querySelectorAll('[data-question-id]').forEach(function (wrapper) {
      const id = wrapper.dataset.questionId;
      const question = (state.questionnaire.questions || []).find(function (item) { return item.id === id; });
      if (!question) return;
      if (question.type === 'single_choice') {
        const selected = wrapper.querySelector('input:checked');
        answers[id] = selected ? selected.value : '';
      } else if (question.type === 'multi_choice') {
        answers[id] = Array.from(wrapper.querySelectorAll('input:checked')).map(function (input) { return input.value; });
      } else if (question.type === 'list') {
        answers[id] = wrapper.querySelector('textarea').value.split(/\r?\n/).map(function (item) { return item.trim(); }).filter(Boolean);
      } else if (question.type === 'form_labels') {
        answers[id] = {};
        wrapper.querySelectorAll('[data-form-key]').forEach(function (input) {
          if (input.value.trim()) answers[id][input.dataset.formKey] = input.value.trim();
        });
      }
    });
    return answers;
  }

  function renderPlan(plan) {
    state.plan = plan;
  }

  async function loadExisting() {
    try {
      const data = await request('api/get-onboarding.php?client_id=' + encodeURIComponent(state.clientId));
      if (!data) {
        showStep('scan', {scroll: false});
        return;
      }
      elements.siteUrl.value = data.profile.site_url || '';
      if (data.scan && data.scan.data) {
        renderScan(data.scan);
        unlockStep('review');
      }
      if (data.scan && data.scan.questionnaire) {
        renderQuestionnaire(data.scan.questionnaire, data.profile.answers);
        unlockStep('verify');
      }
      if (data.profile.measurement_plan) {
        renderPlan(data.profile.measurement_plan);
        unlockStep('ready');
        showStep('ready', {scroll: false});
      } else if (data.scan && data.scan.questionnaire) {
        showStep('verify', {scroll: false});
      } else if (data.scan && data.scan.data) {
        showStep('review', {scroll: false});
      } else {
        showStep('scan', {scroll: false});
      }
    } catch (error) {
      showToast(error.message, 'error');
    }
  }

  elements.scanForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    setScanning(true);
    try {
      const data = await request('api/start-site-scan.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({client_id: state.clientId, site_url: elements.siteUrl.value, csrf_token: csrfToken()})
      });
      state.answers = null;
      state.plan = null;
      state.maxStep = 0;
      renderScan(data.scan);
      renderQuestionnaire(data.scan.questionnaire, null);
      unlockStep('review');
      unlockStep('verify');
      showStep('review');
      showToast('Site scan complete. Review the visual summary before continuing.', 'success');
    } catch (error) {
      showToast(error.message, 'error', 7000);
    } finally {
      setScanning(false);
    }
  });

  elements.questionsForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    const button = document.getElementById('create-plan-button');
    const buttonLabel = document.getElementById('create-plan-label');
    button.disabled = true;
    buttonLabel.textContent = 'Preparing tracking...';
    try {
      const data = await request('api/save-onboarding-answers.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({client_id: state.clientId, answers: collectAnswers(), csrf_token: csrfToken()})
      });
      state.answers = data.answers;
      renderPlan(data.measurement_plan);
      unlockStep('ready');
      showStep('ready');
      showToast('Your tracking foundation is ready to review.', 'success');
    } catch (error) {
      showToast(error.message, 'error');
    } finally {
      button.disabled = false;
      buttonLabel.textContent = 'Prepare my tracking';
    }
  });

  document.getElementById('rescan-button').addEventListener('click', function () {
    showStep('scan');
    elements.siteUrl.focus();
  });

  document.getElementById('edit-answers-button').addEventListener('click', function () {
    showStep('verify');
  });

  elements.stepButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      showStep(button.dataset.stepTarget);
    });
  });

  document.querySelectorAll('[data-step-back], [data-step-next]').forEach(function (button) {
    button.addEventListener('click', function () {
      showStep(button.dataset.stepBack || button.dataset.stepNext);
    });
  });

  loadExisting();
});
