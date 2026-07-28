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

// ─── Confirm Dialog helper ────────────────────────────────────────────────────

/**
 * Show a native confirm and return true if user confirms.
 * Use before any destructive action.
 *
 * @param {string} message
 * @returns {boolean}
 */
function confirmAction(message = 'Are you sure?') {
  return window.confirm(message);
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

  // Mobile sidebar toggle
  const toggle = document.getElementById('sidebar-toggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
    });
    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
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
