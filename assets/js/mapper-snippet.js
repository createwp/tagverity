'use strict';

(function () {
  // ---------------------------------------------------------------------
  // Ported verbatim from src/SitePreviewRenderer.php's previewScript() —
  // this is the same, already-shipped selector-generation logic used by
  // the static site preview mapper. Do not "improve" these bodies here;
  // any behaviour change belongs in SitePreviewRenderer.php first.
  // ---------------------------------------------------------------------

  function escapeCss(value) {
    if (window.CSS && CSS.escape) return CSS.escape(value);
    return String(value).replace(/[^a-zA-Z0-9_-]/g, function (char) { return '\\' + char; });
  }

  function stableValue(value) {
    return value && value.length <= 80 && !/^\d+$/.test(value) && !/[a-f0-9]{12,}/i.test(value);
  }

  function usefulClasses(element) {
    return Array.from(element.classList || []).filter(function (name) {
      return stableValue(name) && !/^(active|selected|hover|focus|open|closed|css-|sc-|tagverity-preview-)/i.test(name);
    }).slice(0, 2);
  }

  function segment(element) {
    var tag = element.tagName.toLowerCase();
    var classes = usefulClasses(element);
    var part = tag + classes.map(function (name) { return '.' + escapeCss(name); }).join('');
    var siblings = element.parentElement ? Array.from(element.parentElement.children).filter(function (item) { return item.tagName === element.tagName; }) : [];
    if (!classes.length && siblings.length > 1) part += ':nth-of-type(' + (siblings.indexOf(element) + 1) + ')';
    return part;
  }

  function selectorFor(element) {
    if (stableValue(element.id)) return '#' + escapeCss(element.id);
    var dataAttributes = ['data-testid', 'data-test', 'data-qa', 'data-id'];
    for (var i = 0; i < dataAttributes.length; i++) {
      var value = element.getAttribute(dataAttributes[i]);
      if (stableValue(value)) return element.tagName.toLowerCase() + '[' + dataAttributes[i] + '="' + String(value).replace(/"/g, '\\"') + '"]';
    }
    var parts = [];
    var current = element;
    // nodeType === 1 guards against walking past the top of the element
    // chain onto the Document node (nodeType 9) in a real DOM. Every real
    // Element already has nodeType 1, so also accepting "nodeType is
    // unset" changes nothing there — it only lets this run against a
    // minimal Node-stubbed test element that doesn't set nodeType.
    while (current && (current.nodeType === undefined || current.nodeType === 1) && current.tagName.toLowerCase() !== 'html' && parts.length < 5) {
      parts.unshift(segment(current));
      if (stableValue(current.id)) {
        parts[0] = '#' + escapeCss(current.id);
        break;
      }
      current = current.parentElement;
    }
    return parts.join(' > ');
  }

  function cleanText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 180);
  }

  function suggestedName(element) {
    var value = element.getAttribute('aria-label') || element.getAttribute('title') || element.getAttribute('alt') || cleanText(element.textContent) || element.getAttribute('placeholder') || element.getAttribute('name');
    return cleanText(value) || element.tagName.toLowerCase() + ' element';
  }

  function recommendedIntent(element, text) {
    var tag = element.tagName.toLowerCase();
    var type = (element.getAttribute('type') || '').toLowerCase();
    var signal = (text + ' ' + element.className + ' ' + element.id).toLowerCase();
    if (tag === 'form' || element.closest('form')) return 'form';
    if (type === 'search' || /search|filter|sort/.test(signal)) return 'site_search';
    if (/price|amount|currency|product[-_ ]?(name|id|title)|sku/.test(signal)) return 'ecommerce_value';
    if (tag === 'a' || tag === 'button' || type === 'button' || type === 'submit') return 'click';
    return 'visibility';
  }

  function labelFor(element) {
    if (stableValue(element.id)) {
      var explicit = document.querySelector('label[for="' + escapeCss(element.id) + '"]');
      if (explicit) return cleanText(explicit.textContent);
    }
    var wrapping = element.closest('label');
    return wrapping ? cleanText(wrapping.textContent) : '';
  }

  function payloadFor(element) {
    var text = cleanText(element.textContent);
    var form = element.tagName.toLowerCase() === 'form' ? element : element.closest('form');
    return {
      selector: selectorFor(element),
      tag: element.tagName.toLowerCase(),
      text: text,
      id: cleanText(element.id),
      class_name: cleanText(element.className),
      href: cleanText(element.getAttribute('href')),
      type: cleanText(element.getAttribute('type')),
      name: cleanText(element.getAttribute('name')),
      field_label: labelFor(element),
      autocomplete: cleanText(element.getAttribute('autocomplete')),
      form_id: form ? cleanText(form.id) : '',
      form_class_name: form ? cleanText(form.className) : '',
      form_name: form ? cleanText(form.getAttribute('name')) : '',
      form_selector: form ? selectorFor(form) : '',
      aria_label: cleanText(element.getAttribute('aria-label')),
      placeholder: cleanText(element.getAttribute('placeholder')),
      suggested_name: suggestedName(element),
      recommended_intent: recommendedIntent(element, text)
    };
  }

  // Pure check used by the live-mapper ack listener: an ack only counts for
  // the click whose selection_id it echoes back. No DOM access.
  function isMatchingAck(pendingSelectionId, data) {
    return typeof pendingSelectionId === 'string' && pendingSelectionId !== '' &&
      !!data && data.type === 'element_selected_ack' && data.selection_id === pendingSelectionId;
  }

  // 16 lowercase hex chars (8 random bytes) identifying one click.
  function newSelectionId() {
    var hex = '';
    var i;
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      var bytes = new Uint8Array(8);
      window.crypto.getRandomValues(bytes);
      for (i = 0; i < bytes.length; i++) {
        var pair = bytes[i].toString(16);
        hex += pair.length < 2 ? '0' + pair : pair;
      }
    } else {
      hex = Math.random().toString(16).slice(2, 18);
    }
    while (hex.length < 16) hex += '0';
    return hex;
  }

  window.TagVerityMapperSnippet = {
    isMatchingAck: isMatchingAck,
    newSelectionId: newSelectionId,
    escapeCss: escapeCss,
    stableValue: stableValue,
    usefulClasses: usefulClasses,
    segment: segment,
    selectorFor: selectorFor,
    cleanText: cleanText,
    suggestedName: suggestedName,
    recommendedIntent: recommendedIntent,
    labelFor: labelFor,
    payloadFor: payloadFor
  };

  // ---------------------------------------------------------------------
  // Snippet-specific logic: this is the new part. The snippet sits dormant
  // on a live site until TagVerity opens it with a one-time mapper token
  // in the URL hash. Only then does it call out to TagVerity, render an
  // overlay, and report clicks back to the opener window.
  // ---------------------------------------------------------------------

  var apiBase = (document.currentScript && document.currentScript.dataset.tagverityApi) || '';

  var hashMatch = String((typeof location !== 'undefined' && location.hash) || '').match(/__tagverity_mapper=([^&]+)/);
  if (!hashMatch) {
    // Dormant: no token present, so make zero network calls and attach
    // zero DOM listeners.
    return;
  }

  var token = decodeURIComponent(hashMatch[1]);
  history.replaceState(null, '', location.pathname + location.search);

  var CSS_HOVER_CLASS = 'tagverity-preview-hover';
  var CSS_SELECTED_CLASS = 'tagverity-preview-selected';

  function injectStyle() {
    var style = document.createElement('style');
    style.setAttribute('data-tagverity-ui', 'true');
    style.textContent =
      '.tagverity-preview-banner{position:fixed;top:0;left:0;right:0;z-index:2147483647;padding:10px 14px;background:#e8f0fe;color:#174ea6;border-bottom:2px solid #1a73e8;font:600 13px/1.4 Roboto,system-ui,sans-serif;text-align:center;box-shadow:0 2px 8px rgba(60,64,67,.18)}' +
      '.tagverity-preview-hover{outline:3px solid #00897b!important;outline-offset:2px!important;cursor:crosshair!important}' +
      '.tagverity-preview-selected{outline:3px solid #6750a4!important;outline-offset:2px!important;box-shadow:0 0 0 5px rgba(103,80,164,.2)!important}';
    document.head.appendChild(style);
    return style;
  }

  function injectBanner(text) {
    var banner = document.createElement('div');
    banner.setAttribute('data-tagverity-ui', 'true');
    banner.className = 'tagverity-preview-banner';
    banner.textContent = text;
    document.body.appendChild(banner);
    return banner;
  }

  function showInvalidBanner() {
    injectBanner('This TagVerity mapping link is invalid or has expired. Return to TagVerity and open the live mapper again.');
  }

  function showExpiredBanner() {
    injectBanner('This session has expired.');
  }

  fetch(apiBase + '/api/exchange-live-mapper-session.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({token: token})
  }).then(function (response) {
    return response.json().then(function (body) {
      return {ok: response.ok, body: body};
    });
  }).then(function (result) {
    if (!result.ok || result.body.error) {
      showInvalidBanner();
      return;
    }
    startOverlay(result.body.data);
  }).catch(function () {
    showInvalidBanner();
  });

  function startOverlay(sessionData) {
    var style = injectStyle();
    var banner = injectBanner('TagVerity live mapper: hover and click the element you want to track.');
    var confirmation = null;
    var hovered = null;
    var selected = null;
    var expiryTimer = null;
    var ackTimer = null;
    var pendingSelectionId = '';

    function isUiElement(target) {
      return !!(target && target.closest && target.closest('[data-tagverity-ui]'));
    }

    function showConfirmation() {
      if (confirmation) confirmation.parentNode && confirmation.parentNode.removeChild(confirmation);
      confirmation = injectBanner('Sent to TagVerity — switch tabs to confirm');
    }

    function showUnreachable() {
      if (confirmation) confirmation.parentNode && confirmation.parentNode.removeChild(confirmation);
      confirmation = injectBanner("Couldn't reach the TagVerity tab — reopen the live mapper from TagVerity.");
    }

    function onAckMessage(event) {
      if (event.source !== window.opener || event.origin !== apiBase) return;
      if (!event.data || event.data.type !== 'element_selected_ack') return;
      if (!isMatchingAck(pendingSelectionId, event.data)) return;
      pendingSelectionId = '';
      if (ackTimer) { clearTimeout(ackTimer); ackTimer = null; }
      showConfirmation();
    }
    window.addEventListener('message', onAckMessage);

    function onMouseOver(event) {
      var target = event.target;
      if (!(target instanceof Element) || isUiElement(target)) return;
      if (hovered && hovered !== selected) hovered.classList.remove(CSS_HOVER_CLASS);
      hovered = target;
      if (hovered !== selected) hovered.classList.add(CSS_HOVER_CLASS);
    }

    function onMouseOut() {
      if (hovered && hovered !== selected) hovered.classList.remove(CSS_HOVER_CLASS);
    }

    function onClick(event) {
      var target = event.target;
      if (!(target instanceof Element) || isUiElement(target)) return;
      event.preventDefault();
      event.stopPropagation();
      // payloadFor() is captured before the tagverity-preview-selected
      // class is applied below (same order as previewScript()'s click
      // handler), so class_name reflects the page's own classes only.
      var payload = Object.assign({}, payloadFor(target), {page_url: location.href});
      if (selected) selected.classList.remove(CSS_SELECTED_CLASS);
      selected = target;
      selected.classList.remove(CSS_HOVER_CLASS);
      selected.classList.add(CSS_SELECTED_CLASS);

      if (!window.opener) {
        // No opener reference at all (e.g. the client site sent
        // Cross-Origin-Opener-Policy, or the dashboard tab was reloaded) —
        // there is nothing to send to, so don't claim success.
        showUnreachable();
        return;
      }

      pendingSelectionId = window.TagVerityMapperSnippet.newSelectionId();
      window.opener.postMessage({
        source: 'tagverity-live-mapper',
        type: 'element_selected',
        selection_id: pendingSelectionId,
        payload: payload
      }, apiBase);
      if (ackTimer) clearTimeout(ackTimer);
      ackTimer = setTimeout(showUnreachable, 1500);
    }

    function onSubmit(event) {
      event.preventDefault();
      event.stopPropagation();
    }

    function teardown() {
      document.removeEventListener('mouseover', onMouseOver, true);
      document.removeEventListener('mouseout', onMouseOut, true);
      document.removeEventListener('click', onClick, true);
      document.removeEventListener('submit', onSubmit, true);
      window.removeEventListener('message', onAckMessage);
      if (hovered) hovered.classList.remove(CSS_HOVER_CLASS);
      if (selected) selected.classList.remove(CSS_SELECTED_CLASS);
      if (banner && banner.parentNode) banner.parentNode.removeChild(banner);
      if (confirmation && confirmation.parentNode) confirmation.parentNode.removeChild(confirmation);
      if (style && style.parentNode) style.parentNode.removeChild(style);
      if (expiryTimer) clearTimeout(expiryTimer);
      if (ackTimer) clearTimeout(ackTimer);
      showExpiredBanner();
    }

    document.addEventListener('mouseover', onMouseOver, true);
    document.addEventListener('mouseout', onMouseOut, true);
    document.addEventListener('click', onClick, true);
    document.addEventListener('submit', onSubmit, true);

    var remainingMs = new Date(sessionData.expires_at.replace(' ', 'T') + 'Z').getTime() - Date.now();
    expiryTimer = setTimeout(teardown, Math.max(remainingMs, 0));
  }
}());
