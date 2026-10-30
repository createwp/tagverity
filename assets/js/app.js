/* ─── GTM Auditor — App JavaScript ─────────────────────────────────────────── */

'use strict';

// ─── Toast Notifications ──────────────────────────────────────────────────────

/**
 * Display a non-blocking toast notification.
 *
 * @param {string} message  - Text to display
 * @param {'success'|'error'|'info'} type - Toast type
 * @param {number} duration - Auto-dismiss after ms (default 4000)
 */
function showToast(message, type = 'info', duration = 4000) {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = message;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(16px)';
    toast.style.transition = 'all 0.25s ease';
    setTimeout(() => toast.remove(), 280);
  }, duration);
}

// ─── CSRF Token helper (for fetch() calls) ────────────────────────────────────

/**
 * Returns the CSRF token stored in the hidden input with name="csrf_token".
 * Always include this in POST requests made via fetch().
 *
 * @returns {string}
 */
function getCsrfToken() {
  const el = document.querySelector('input[name="csrf_token"]');
  return el ? el.value : '';
}

window.showConfirm = function (options = {}) {
  return new Promise(resolve => {
    const overlay = document.createElement('div');
    overlay.className = 'app-modal-overlay';
    const dialog = document.createElement('div');
    dialog.className = 'app-modal';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');

    const marker = document.createElement('div');
    marker.className = 'app-modal-marker' + (options.destructive ? ' destructive' : '');
    marker.innerHTML = options.destructive
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7h16M10 11v6M14 11v6M9 7l1-3h4l1 3M6 7l1 14h10l1-14"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>';
    const copy = document.createElement('div');
    const title = document.createElement('h2');
    title.textContent = options.title || 'Confirm action';
    const message = document.createElement('p');
    message.textContent = options.message || 'Are you sure you want to continue?';
    copy.append(title, message);
    const header = document.createElement('div');
    header.className = 'app-modal-header';
    header.append(marker, copy);

    const actions = document.createElement('div');
    actions.className = 'app-modal-actions';
    const cancel = document.createElement('button');
    cancel.className = 'btn btn-secondary';
    cancel.type = 'button';
    cancel.textContent = options.cancelLabel || 'Cancel';
    const confirm = document.createElement('button');
    confirm.className = options.destructive ? 'btn btn-danger' : 'btn btn-primary';
    confirm.type = 'button';
    confirm.textContent = options.confirmLabel || 'Confirm';
    actions.append(cancel, confirm);
    dialog.append(header, actions);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    function close(result) {
      document.removeEventListener('keydown', onKeydown);
      overlay.classList.add('closing');
      setTimeout(() => overlay.remove(), 150);
      resolve(result);
    }
    function onKeydown(event) { if (event.key === 'Escape') close(false); }
    cancel.addEventListener('click', () => close(false));
    confirm.addEventListener('click', () => close(true));
    overlay.addEventListener('click', event => { if (event.target === overlay) close(false); });
    document.addEventListener('keydown', onKeydown);
    requestAnimationFrame(() => { overlay.classList.add('visible'); cancel.focus(); });
  });
};

function confirmAction(message = 'Are you sure?') {
  return window.showConfirm({message});
}

// ─── Auto-dismiss flash alerts ────────────────────────────────────────────────
// Guarded so the file also loads in the Node test harness, which has no DOM events.
if (typeof document.addEventListener === 'function') document.addEventListener('DOMContentLoaded', () => {
  // Auto-dismiss .alert elements after 6 seconds
  document.querySelectorAll('.alert[data-auto-dismiss]').forEach(el => {
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.4s ease';
      setTimeout(() => el.remove(), 400);
    }, 6000);
  });

  // Shared mobile navigation
  const toggle = document.getElementById('mobile-sidebar-toggle');
  const closeButton = document.getElementById('mobile-sidebar-close');
  const backdrop = document.getElementById('mobile-sidebar-backdrop');
  const sidebar = document.getElementById('sidebar');
  if (toggle && closeButton && backdrop && sidebar) {
    const mobileViewport = window.matchMedia('(max-width: 768px)');
    let previousFocus = null;

    function focusableSidebarItems() {
      return Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'))
        .filter(item => !item.hidden && item.offsetParent !== null);
    }

    function setSidebarOpen(open, restoreFocus = true) {
      const shouldOpen = open && mobileViewport.matches;
      sidebar.classList.toggle('open', shouldOpen);
      backdrop.classList.toggle('open', shouldOpen);
      document.body.classList.toggle('mobile-sidebar-open', shouldOpen);
      toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
      toggle.setAttribute('aria-label', shouldOpen ? 'Close navigation' : 'Open navigation');

      if (mobileViewport.matches) {
        sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        sidebar.inert = !shouldOpen;
      } else {
        sidebar.removeAttribute('aria-hidden');
        sidebar.inert = false;
      }

      if (shouldOpen) {
        previousFocus = document.activeElement;
        window.setTimeout(() => closeButton.focus(), 50);
      } else if (restoreFocus && previousFocus instanceof HTMLElement && mobileViewport.matches) {
        previousFocus.focus();
      }
    }

    toggle.addEventListener('click', () => setSidebarOpen(!sidebar.classList.contains('open')));
    closeButton.addEventListener('click', () => setSidebarOpen(false));
    backdrop.addEventListener('click', () => setSidebarOpen(false));
    sidebar.querySelectorAll('a[href]').forEach(link => {
      link.addEventListener('click', () => {
        if (mobileViewport.matches) setSidebarOpen(false, false);
      });
    });

    document.addEventListener('keydown', event => {
      if (!sidebar.classList.contains('open')) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        setSidebarOpen(false);
        return;
      }
      if (event.key !== 'Tab') return;
      const items = focusableSidebarItems();
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    const syncSidebarViewport = () => setSidebarOpen(false, false);
    if (typeof mobileViewport.addEventListener === 'function') {
      mobileViewport.addEventListener('change', syncSidebarViewport);
    } else {
      mobileViewport.addListener(syncSidebarViewport);
    }
    setSidebarOpen(false, false);
  }

  // Animate health score ring on load
  document.querySelectorAll('.health-score-ring circle[stroke-dasharray]').forEach(circle => {
    const targetOffset = circle.getAttribute('stroke-dashoffset');
    circle.style.strokeDashoffset = circle.getAttribute('stroke-dasharray');
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        circle.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(0.4,0,0.2,1)';
        circle.style.strokeDashoffset = targetOffset;
      });
    });
  });
});

// ─── Polling: auto-refresh page when a running audit job completes ────────────

/**
 * Poll /api/job-status.php every 8 seconds if there are any 'running' or 'queued' status dots.
 * When all jobs finish, reload the page to show fresh results.
 */
(function initAuditPoller() {
  if (typeof document.querySelectorAll !== 'function') return;
  const activeDots = document.querySelectorAll('.status-dot.running, .status-dot.queued');
  if (!activeDots.length) return;

  let pollInterval = setInterval(async () => {
    try {
      const res  = await fetch('api/job-status.php');
      const data = await res.json();
      // If no more queued or running jobs → reload to show results
      if (!data.has_active) {
        clearInterval(pollInterval);
        showToast('Audit complete! Refreshing results…', 'success');
        setTimeout(() => location.reload(), 1500);
      }
    } catch {
      // Network error — keep polling
    }
  }, 8000);
})();

// ─── Reveal patterns: shared dialog, drawer and popover helpers ──────────────
//
// modal   (<dialog>, openDialog/closeDialog): configure a thing picked from a collection.
// drawer  (openDrawer/closeDrawer): page-level settings.
// popover (bindPopover): pick from overflow or add from a list.
// Scroll lock is body.scroll-locked, shared by every reveal and held until the last one closes.

window.TagVerityUI = (function () {
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var DRAWER_CLOSE_MS = 240;

  var dialogOpeners = new WeakMap();
  var dialogsBound = new WeakSet();
  var drawerOpeners = new WeakMap();
  var drawersBound = new WeakSet();
  var openDrawers = [];
  var scrollLockOwners = [];
  var activePopover = null;
  var popoverSeq = 0;
  var globalListenersBound = false;

  function visibleFocusables(container) {
    return Array.prototype.slice.call(container.querySelectorAll(FOCUSABLE)).filter(function (node) {
      return node.getClientRects().length > 0;
    });
  }

  function lockScroll(owner) {
    if (scrollLockOwners.indexOf(owner) === -1) scrollLockOwners.push(owner);
    document.body.classList.add('scroll-locked');
  }

  function unlockScroll(owner) {
    var index = scrollLockOwners.indexOf(owner);
    if (index !== -1) scrollLockOwners.splice(index, 1);
    if (scrollLockOwners.length === 0) document.body.classList.remove('scroll-locked');
  }

  // Cycles Tab and Shift+Tab between the first and last focusable element of container.
  function trapTab(container, event) {
    var items = visibleFocusables(container);
    if (!items.length) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    var active = document.activeElement;
    var outside = !container.contains(active);
    if (event.shiftKey && (active === first || outside)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || outside)) {
      event.preventDefault();
      first.focus();
    }
  }

  function ensureGlobalListeners() {
    if (globalListenersBound) return;
    globalListenersBound = true;
    document.addEventListener('keydown', onGlobalKeydown);
    document.addEventListener('click', onGlobalClick);
  }

  function onGlobalKeydown(event) {
    if (activePopover && event.key === 'Escape') {
      event.preventDefault();
      var popover = activePopover;
      popover.api.close();
      popover.button.focus();
      return;
    }
    var drawer = openDrawers[openDrawers.length - 1];
    if (!drawer) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeDrawer(drawer);
    } else if (event.key === 'Tab') {
      trapTab(drawer, event);
    }
  }

  function onGlobalClick(event) {
    if (activePopover && isOutside(event.target, [activePopover.button, activePopover.panel])) {
      activePopover.api.close();
    }
  }

  function bindDialog(dialog) {
    if (dialogsBound.has(dialog)) return;
    dialogsBound.add(dialog);

    // Backdrop click: the dialog element itself is the target when the backdrop is clicked.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) closeDialog(dialog);
    });

    dialog.addEventListener('keydown', function (event) {
      if (event.key === 'Tab') trapTab(dialog, event);
    });

    // Native Escape closes the dialog; this restores scroll and returns focus to the opener.
    dialog.addEventListener('close', function () {
      unlockScroll(dialog);
      var opener = dialogOpeners.get(dialog) || null;
      dialogOpeners.delete(dialog);
      if (opener && document.body.contains(opener)) opener.focus();
    });
  }

  function openDialog(dialog, opener, initialFocus) {
    if (!dialog) return;
    bindDialog(dialog);
    if (!dialog.open) dialog.showModal();
    dialogOpeners.set(dialog, opener || null);
    lockScroll(dialog);
    var target = initialFocus || visibleFocusables(dialog)[0];
    if (target) target.focus();
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (dialog.open) dialog.close();
    unlockScroll(dialog);
  }

  function drawerBackdrop(drawer) {
    var parent = drawer.parentNode;
    if (!parent) return null;
    return Array.prototype.slice.call(parent.children).find(function (node) {
      return node.classList.contains('reveal-drawer-backdrop');
    }) || null;
  }

  function openDrawer(drawer, opener) {
    if (!drawer) return;
    ensureGlobalListeners();
    var backdrop = drawerBackdrop(drawer);
    if (backdrop && !drawersBound.has(backdrop)) {
      drawersBound.add(backdrop);
      backdrop.addEventListener('click', function () { closeDrawer(drawer); });
    }
    if (openDrawers.indexOf(drawer) !== -1) {
      var current = visibleFocusables(drawer)[0];
      if (current) current.focus();
      return;
    }

    drawerOpeners.set(drawer, opener || null);
    drawer.hidden = false;
    if (backdrop) backdrop.hidden = false;
    // Force a style flush so the slide-in transition runs from the closed position.
    void drawer.offsetWidth;
    drawer.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
    openDrawers.push(drawer);
    lockScroll(drawer);

    var target = drawer.querySelector('[autofocus]') || visibleFocusables(drawer)[0];
    if (target) target.focus();
  }

  function closeDrawer(drawer) {
    if (!drawer || openDrawers.indexOf(drawer) === -1) return;
    var backdrop = drawerBackdrop(drawer);
    openDrawers.splice(openDrawers.indexOf(drawer), 1);
    drawer.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
    unlockScroll(drawer);

    // Hide after the slide-out; skipped if the drawer was reopened in the meantime.
    window.setTimeout(function () {
      if (drawer.classList.contains('open')) return;
      drawer.hidden = true;
      if (backdrop) backdrop.hidden = true;
    }, DRAWER_CLOSE_MS);

    var opener = drawerOpeners.get(drawer) || null;
    drawerOpeners.delete(drawer);
    if (opener && document.body.contains(opener)) opener.focus();
  }

  // Places the panel under the button, inside the nearest positioned ancestor.
  function positionPopover(button, panel) {
    var host = panel.parentNode;
    while (host && host !== document.body && window.getComputedStyle(host).position === 'static') {
      host = host.parentNode;
    }
    if (!host || host === document.body) host = null;

    var buttonRect = button.getBoundingClientRect();
    var hostRect = host ? host.getBoundingClientRect() : { left: -window.pageXOffset, top: -window.pageYOffset };
    var offsetLeft = host ? host.clientLeft - host.scrollLeft : 0;
    var offsetTop = host ? host.clientTop - host.scrollTop : 0;
    var panelWidth = panel.getBoundingClientRect().width;

    var left = buttonRect.left;
    if (left + panelWidth > window.innerWidth - 8) left = buttonRect.right - panelWidth;
    left = Math.max(8, left);

    panel.style.left = (left - hostRect.left + offsetLeft) + 'px';
    panel.style.top = (buttonRect.bottom - hostRect.top + offsetTop + 6) + 'px';
  }

  function bindPopover(button, panel) {
    if (!button || !panel) return null;
    ensureGlobalListeners();
    if (!panel.id) panel.id = 'reveal-popover-' + (++popoverSeq);
    panel.classList.add('reveal-popover');
    button.setAttribute('aria-controls', panel.id);
    button.setAttribute('aria-expanded', 'false');
    panel.hidden = true;

    var api = {
      open: function () {
        if (activePopover && activePopover.api !== api) activePopover.api.close();
        panel.hidden = false;
        positionPopover(button, panel);
        button.setAttribute('aria-expanded', 'true');
        activePopover = { api: api, button: button, panel: panel };
      },
      close: function () {
        if (activePopover && activePopover.api === api) activePopover = null;
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
      }
    };

    button.addEventListener('click', function () {
      if (panel.hidden) api.open();
      else api.close();
    });
    return api;
  }

  // True when target is not inside any of the given nodes.
  function isOutside(target, elements) {
    return !(elements || []).some(function (element) {
      return Boolean(element) && typeof element.contains === 'function' && element.contains(target);
    });
  }

  return {
    openDialog: openDialog,
    closeDialog: closeDialog,
    openDrawer: openDrawer,
    closeDrawer: closeDrawer,
    bindPopover: bindPopover,
    isOutside: isOutside
  };
})();
