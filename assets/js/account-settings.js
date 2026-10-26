'use strict';

document.addEventListener('DOMContentLoaded', function () {
  var tabs = Array.from(document.querySelectorAll('[data-settings-tab]'));
  var panels = Array.from(document.querySelectorAll('[data-settings-panel]'));

  function activateTab(tabName, updateUrl) {
    tabs.forEach(function (tab) {
      var selected = tab.dataset.settingsTab === tabName;
      tab.classList.toggle('active', selected);
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.setAttribute('tabindex', selected ? '0' : '-1');
    });

    panels.forEach(function (panel) {
      panel.hidden = panel.dataset.settingsPanel !== tabName;
    });

    if (updateUrl && window.history.replaceState) {
      var url = new URL(window.location.href);
      url.searchParams.set('tab', tabName);
      window.history.replaceState({}, '', url.toString());
    }
  }

  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function (event) {
      event.preventDefault();
      activateTab(tab.dataset.settingsTab, true);
    });

    tab.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      event.preventDefault();
      var nextIndex = event.key === 'ArrowRight'
        ? (index + 1) % tabs.length
        : (index - 1 + tabs.length) % tabs.length;
      activateTab(tabs[nextIndex].dataset.settingsTab, true);
      tabs[nextIndex].focus();
    });
  });
});
