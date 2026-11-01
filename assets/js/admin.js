(() => {
  'use strict';
  document.querySelectorAll('[data-filter-list]').forEach(input => input.addEventListener('input', () => {
    const list = document.getElementById(input.dataset.filterList);
    list?.querySelectorAll('[data-search-item]').forEach(item => { item.hidden = !item.textContent.toLowerCase().includes(input.value.toLowerCase()); });
  }));
  document.querySelectorAll('[data-filter-select]').forEach(input => input.addEventListener('input', () => {
    const select = document.getElementById(input.dataset.filterSelect);
    Array.from(select?.options || []).forEach(option => { option.hidden = !!option.value && !option.selected && !option.textContent.toLowerCase().includes(input.value.toLowerCase()); });
  }));
  document.querySelectorAll('.upload-drop').forEach(zone => {
    ['dragenter', 'dragover'].forEach(name => zone.addEventListener(name, event => { event.preventDefault(); zone.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(name => zone.addEventListener(name, event => { event.preventDefault(); zone.classList.remove('is-dragging'); }));
    zone.addEventListener('drop', event => {
      const input = zone.querySelector('input[type=file]');
      if (!input || !event.dataTransfer?.files.length) return;
      if (!input.multiple && event.dataTransfer.files.length > 1) { alert('Choose one file for this attachment.'); return; }
      input.files = event.dataTransfer.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
  document.querySelectorAll('[data-upload-form]').forEach(form => {
    form.addEventListener('submit', event => {
      if (!window.FormData || !window.XMLHttpRequest) return;
      event.preventDefault();
      const feedback = form.querySelector('[data-upload-feedback]');
      const progress = form.querySelector('[data-upload-progress]');
      const buttons = Array.from(form.querySelectorAll('button'));
      const files = Array.from(form.querySelectorAll('input[type=file]')).flatMap(input => Array.from(input.files));
      const max = Number(form.dataset.maxBytes);
      const postMax = Number(form.dataset.postBytes);
      const oversized = files.find(file => file.size > max);
      if (oversized) { feedback.textContent = `${oversized.name} exceeds the ${(max / 1048576).toFixed(1)} MB file limit.`; return; }
      if (postMax && files.reduce((sum, file) => sum + file.size, 0) + 65536 > postMax) { feedback.textContent = 'The combined attachments exceed the server limit. Upload smaller files or add them separately in the media library.'; return; }
      const data = new FormData(form);
      if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
      buttons.forEach(button => { button.disabled = true; });
      feedback.textContent = files.length ? 'Uploading… please keep this page open.' : 'Saving…';
      if (progress) { progress.hidden = false; progress.value = 0; }
      const request = new XMLHttpRequest();
      request.open('POST', form.action);
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      request.upload.addEventListener('progress', event => {
        if (event.lengthComputable && progress) progress.value = Math.round(event.loaded * 100 / event.total);
      });
      request.upload.addEventListener('load', () => { feedback.textContent = 'Upload received. Validating and saving…'; });
      const release = () => { buttons.forEach(button => { button.disabled = false; }); if (progress) progress.hidden = true; };
      request.addEventListener('load', () => {
        const redirectedToLogin = /\/admin\/login\.php(?:[?#]|$)/i.test(request.responseURL);
        let result;
        try {
          result = JSON.parse(request.responseText);
        } catch (_) {
          if (request.status === 419 || redirectedToLogin) feedback.textContent = 'Your session expired. Reload the page and sign in before retrying.';
          else if ([408, 413, 502, 503, 504].includes(request.status)) feedback.textContent = `The server could not accept this upload (HTTP ${request.status}). Check the hosting upload-size and timeout limits, or try a smaller file.`;
          else if (request.status === 404) {
            let endpoint = location.pathname;
            try { endpoint = new URL(request.responseURL, location.href).pathname; } catch (_) {}
            feedback.textContent = `The upload endpoint was not found (HTTP 404): ${endpoint}. Check that this exact path exists on the live site and that admin/index.php was deployed.`;
          }
          else feedback.textContent = `The server returned an unexpected response (HTTP ${request.status}). It may be an HTML error page instead of the upload response. Check Hostinger's PHP error log and verify the upload before retrying.`;
          release();
          return;
        }
        if (result.error) { feedback.textContent = result.message || 'The server rejected the upload.'; release(); return; }
        if (typeof result.redirect !== 'string') { feedback.textContent = 'The server response was incomplete. Check the hosting error log and verify the upload before retrying.'; release(); return; }
        const target = new URL(result.redirect, location.href);
        if (target.origin !== location.origin) { feedback.textContent = 'The server returned an invalid destination. Verify the upload before retrying.'; release(); return; }
        location.assign(target.href);
      });
      request.addEventListener('error', () => { feedback.textContent = 'Connection lost. Check the library/content list before retrying to avoid duplicates.'; release(); });
      request.send(data);
    });
  });
})();
