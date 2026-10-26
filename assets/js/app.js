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
document.addEventListener('DOMContentLoaded', () => {
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
 * Poll /api/job-status.php every 8 seconds if there are any 'running' status dots.
 * When all jobs complete, reload the page to show fresh results.
 */
(function initAuditPoller() {
  const runningDots = document.querySelectorAll('.status-dot.running');
  if (!runningDots.length) return;

  let pollInterval = setInterval(async () => {
    try {
      const res  = await fetch('api/job-status.php');
      const data = await res.json();
      // If no more running jobs → reload to show results
      if (!data.has_running) {
        clearInterval(pollInterval);
        showToast('Audit complete! Refreshing results…', 'success');
        setTimeout(() => location.reload(), 1500);
      }
    } catch {
      // Network error — keep polling
    }
  }, 8000);
})();
