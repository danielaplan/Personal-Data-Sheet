'use strict';

if (!window.jQuery) {
  document.querySelector('#records-status').textContent = 'jQuery could not load. Reload the page to try again.';
} else {
  jQuery(function ($) {
    const form = document.querySelector('#pds-form');
    const fields = [...form.querySelectorAll('input, select')];
    let currentId = null;
    let version = null;
    let csrf = '';
    let dirty = false;
    let busy = false;
    let page = 1;
    let searchRequest = null;
    let searchSequence = 0;
    let searchTimer;
    let activeView = 'form';

    function showView(view, updateHash = true) {
      activeView = view;
      const isRecords = view === 'records';
      if (isRecords) $('#records-action-status').empty();
      $('#records-view').prop('hidden', !isRecords);
      $('#form-view, #form-sidebar, .print-button').prop('hidden', isRecords);
      $('#form-tab').attr({ 'aria-selected': String(!isRecords), tabindex: isRecords ? -1 : 0 });
      $('#records-tab').attr({ 'aria-selected': String(isRecords), tabindex: isRecords ? 0 : -1 });
      if (updateHash) history.replaceState(null, '', isRecords ? '#records' : '#form-view');
      if (isRecords && csrf) loadRecords(page);
    }
    function focusStatus() {
      document.querySelector(activeView === 'records' ? '#records-action-status' : '#form-status').focus();
    }

    function request(method, data, id) {
      return $.ajax({
        url: 'api/records.php' + (id ? '?id=' + encodeURIComponent(id) : ''),
        method,
        dataType: 'json',
        timeout: 15000,
        cache: false,
        headers: { 'X-CSRF-Token': csrf },
        contentType: 'application/json; charset=utf-8',
        data: method === 'GET' ? data : JSON.stringify(data),
        processData: method === 'GET'
      });
    }
    function message(text, error = false) {
      $(activeView === 'records' ? '#records-action-status' : '#form-status').text(text).toggleClass('is-error', error);
    }
    function errorMessage(xhr) {
      return xhr.responseJSON?.message || 'The request could not be completed. Check your connection and try again. Your entries are still here.';
    }
    function setBusy(value) {
      busy = value;
      $('#pds-form :input, #new-record, #records-body button, .view-tabs button').prop('disabled', value);
      $('#save-record').prop('disabled', value || !csrf);
      $('#pds-form').attr('aria-busy', String(value));
    }
    function updateProgress() {
      const filled = fields.filter(field => field.type === 'radio' ? field.checked : field.value.trim() !== '').length;
      $('#field-count').text(`${filled} / ${fields.length}`);
      $('#field-progress').attr('max', fields.length).val(filled);
    }
    function clearErrors() {
      fields.forEach(field => { field.setCustomValidity(''); field.removeAttribute('aria-invalid'); });
    }
    function populate(record = null) {
      clearErrors();
      fields.forEach(field => {
        const value = record?.fields[field.name] ?? '';
        if (field.type === 'radio') field.checked = field.value === value;
        else field.value = value;
      });
      currentId = record?.id ?? null;
      version = record?.version ?? null;
      dirty = false;
      $('#editor-title').text(currentId ? `Editing record #${currentId}` : 'Add a new record');
      $('#record-mode').text(currentId ? `PERSONAL RECORD / EDIT #${currentId}` : 'PERSONAL RECORD / NEW ENTRY');
      $('#save-record').text(currentId ? 'Save changes' : 'Save record');
      updateProgress();
    }
    function canDiscard() {
      return !dirty || window.confirm('Discard your unsaved changes?');
    }
    function startNew() {
      if (busy || !canDiscard()) return;
      populate();
      showView('form');
      message('New record. Complete the form, then select Save record.');
      document.querySelector('#surname').focus();
    }
    function renderRecords(result) {
      page = result.page;
      const body = $('#records-body').empty();
      result.records.forEach(record => {
        const name = `${record.surname}, ${record.firstname}${record.middlename ? ' ' + record.middlename : ''}`;
        const row = $('<tr>');
        $('<td>').append($('<strong>').text(name), $('<small>').text(`Record #${record.id}`)).appendTo(row);
        $('<td>').text(record.dateofbirth || '—').appendTo(row);
        $('<td>').append($('<span>').text(record.mobile || '—'), $('<small>').text(record.email || '')).appendTo(row);
        $('<td>').text(record.updated_at).appendTo(row);
        const actions = $('<td>').addClass('record-actions');
        $('<button>', { type: 'button', class: 'button', text: 'Edit', 'aria-label': `Edit ${name}` }).prop('disabled', busy).on('click', () => editRecord(record.id)).appendTo(actions);
        $('<button>', { type: 'button', class: 'button delete-button', text: 'Delete', 'aria-label': `Delete ${name}` }).prop('disabled', busy).on('click', () => deleteRecord(record, name)).appendTo(actions);
        actions.appendTo(row); row.appendTo(body);
      });
      if (!result.records.length) $('<tr>').append($('<td>', { colspan: 5 }).text($('#record-search').val().trim() ? 'No records match your search.' : 'No saved records yet. Select Add new record to get started.')).appendTo(body);
      $('#records-status').text(`${result.total} saved record${result.total === 1 ? '' : 's'} found.`).removeClass('is-error');
      $('#page-label').text(`Page ${page} of ${result.pages}`);
      $('#previous-page').prop('disabled', page <= 1);
      $('#next-page').prop('disabled', page >= result.pages);
    }
    function loadRecords(targetPage = 1) {
      clearTimeout(searchTimer);
      const sequence = ++searchSequence;
      if (searchRequest) searchRequest.abort();
      $('#records-status').text('Loading records…').removeClass('is-error');
      $('#previous-page, #next-page').prop('disabled', true);
      $('#records-body').empty();
      searchRequest = request('GET', { q: $('#record-search').val().trim(), page: targetPage })
        .done(result => { if (sequence === searchSequence) renderRecords(result); })
        .fail((xhr, state) => {
          if (state === 'abort' || sequence !== searchSequence) return;
          $('#records-status').text(errorMessage(xhr) + ' Select Search to retry.').addClass('is-error');
        });
    }
    function editRecord(id) {
      if (busy || !canDiscard()) return;
      setBusy(true);
      message('Loading record…');
      request('GET', {}, id).done(result => {
        populate(result.record);
        showView('form');
        message(`Record #${currentId} loaded. Make your changes, then select Save changes.`);
      }).fail(xhr => message(errorMessage(xhr), true)).always(() => {
        setBusy(false);
        focusStatus();
      });
    }
    function deleteRecord(record, name) {
      if (busy || !csrf) return;
      if (!window.confirm(`Delete ${name} (record #${record.id})? This permanently removes the saved record.`)) return;
      setBusy(true);
      request('DELETE', { version: Number(record.version) }, record.id).done(() => {
        if (Number(currentId) === Number(record.id)) {
          // Keep the user's visible values as an unsaved new record.
          const draft = collectFields();
          populate({ fields: draft }); dirty = true;
        }
        message(`Record #${record.id} deleted.`);
        loadRecords(page);
      }).fail(xhr => message(errorMessage(xhr), true)).always(() => {
        setBusy(false);
        focusStatus();
      });
    }
    function collectFields() {
      const data = {};
      fields.forEach(field => {
        if (field.type === 'radio') {
          data[field.name] ??= '';
          if (field.checked) data[field.name] = field.value;
        } else data[field.name] = field.value;
      });
      return data;
    }
    $(form).on('input change', 'input, select', function () {
      dirty = true;
      this.setCustomValidity(''); this.removeAttribute('aria-invalid');
      updateProgress();
    });
    $(form).on('submit', event => {
      event.preventDefault();
      if (busy || !csrf || !form.reportValidity()) return;
      const data = { fields: collectFields(), version };
      setBusy(true);
      message('Saving record…');
      request(currentId ? 'PUT' : 'POST', data, currentId).done(result => {
        populate(result.record);
        message(`Record #${currentId} saved successfully.`);
        loadRecords(page);
      }).fail(xhr => {
        message(errorMessage(xhr), true);
        Object.entries(xhr.responseJSON?.errors || {}).forEach(([key, error]) => {
          const field = fields.find(item => item.name === key);
          if (field) { field.setCustomValidity(error); field.setAttribute('aria-invalid', 'true'); }
        });
      }).always(() => {
        setBusy(false);
        focusStatus();
        form.reportValidity();
      });
    });
    $(form).on('reset', event => {
      event.preventDefault();
      if (busy || !window.confirm('Clear the form and start a new record? Saved records will not be deleted.')) return;
      populate(); message('Form cleared. Saved records are unchanged.');
      document.querySelector('#surname').focus();
    });
    $('#new-record').on('click', startNew);
    $('.view-tabs button').on('click', function () {
      if (!busy) showView(this.id === 'records-tab' ? 'records' : 'form');
    }).on('keydown', function (event) {
      const tabs = [...document.querySelectorAll('.view-tabs button')];
      let next;
      if (event.key === 'ArrowDown' || event.key === 'ArrowRight') next = (tabs.indexOf(this) + 1) % tabs.length;
      if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') next = (tabs.indexOf(this) + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next !== undefined) { event.preventDefault(); tabs[next].focus(); }
    });
    $('a[href="#records"]').on('click', event => {
      event.preventDefault();
      if (busy) return;
      showView('records');
      document.querySelector('#record-search').focus();
    });
    $('a[href="#personal"]').on('click', () => showView('form', false));
    window.addEventListener('hashchange', () => {
      showView(location.hash === '#records' || location.hash === '#records-view' ? 'records' : 'form', false);
    });
    $('#search-form').on('submit', event => { event.preventDefault(); loadRecords(); });
    $('#record-search').on('input', () => {
      clearTimeout(searchTimer);
      // Invalidate pending responses immediately, before the debounce elapses.
      searchSequence++;
      if (searchRequest) searchRequest.abort();
      searchTimer = setTimeout(() => loadRecords(), 300);
    });
    $('#clear-search').on('click', () => { $('#record-search').val(''); loadRecords(); });
    $('#previous-page').on('click', () => loadRecords(page - 1));
    $('#next-page').on('click', () => loadRecords(page + 1));
    $('[data-print]').on('click', () => window.print());
    window.addEventListener('beforeunload', event => {
      if (dirty || busy) { event.preventDefault(); event.returnValue = ''; }
    });
    const links = [...document.querySelectorAll('#form-sidebar nav a')];
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(entries => {
        const visible = entries.find(entry => entry.isIntersecting);
        if (!visible) return;
        links.forEach(link => {
          if (link.hash === `#${visible.target.id}`) link.setAttribute('aria-current', 'location');
          else link.removeAttribute('aria-current');
        });
      }, { rootMargin: '-10% 0px -65% 0px', threshold: 0 });
      document.querySelectorAll('#pds-form .section').forEach(section => observer.observe(section));
    }
    updateProgress();
    showView(location.hash === '#records' || location.hash === '#records-view' ? 'records' : 'form', false);
    request('GET', { action: 'session' }).done(result => {
      csrf = result.csrf;
      setBusy(false);
      loadRecords();
    }).fail(xhr => {
      const text = location.protocol === 'file:' ? 'Open this app through the PHP server to save and search records.' : errorMessage(xhr);
      $('#records-status').text(text).addClass('is-error');
      message(text, true);
    });
  });
}
