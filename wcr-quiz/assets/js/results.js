(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function buildModal() {
    var modal = document.createElement('div');
    modal.className = 'wcrq-modal';
    modal.setAttribute('hidden', 'hidden');
    modal.innerHTML =
      '<div class="wcrq-modal__backdrop" data-modal-close="true"></div>' +
      '<div class="wcrq-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wcrq-modal-title">' +
      '  <h2 class="wcrq-modal__title" id="wcrq-modal-title"></h2>' +
      '  <div class="wcrq-modal__body">' +
      '    <p class="wcrq-modal__message"></p>' +
      '    <div class="wcrq-modal__prompt" hidden>' +
      '      <label class="wcrq-modal__prompt-label" for="wcrq-modal-input"></label>' +
      '      <input type="text" id="wcrq-modal-input" autocomplete="off" />' +
      '      <p class="wcrq-modal__error" role="alert"></p>' +
      '    </div>' +
      '  </div>' +
      '  <div class="wcrq-modal__actions">' +
      '    <button type="button" class="button button-secondary" data-modal-cancel="true"></button>' +
      '    <button type="button" class="button button-primary" data-modal-confirm="true"></button>' +
      '  </div>' +
      '</div>';

    document.body.appendChild(modal);
    return modal;
  }

  function setupModal() {
    var modal = document.querySelector('.wcrq-modal');
    if (!modal) {
      modal = buildModal();
    }

    var dialog = modal.querySelector('.wcrq-modal__dialog');
    var titleEl = modal.querySelector('.wcrq-modal__title');
    var messageEl = modal.querySelector('.wcrq-modal__message');
    var promptEl = modal.querySelector('.wcrq-modal__prompt');
    var promptLabel = modal.querySelector('.wcrq-modal__prompt-label');
    var inputEl = modal.querySelector('#wcrq-modal-input');
    var errorEl = modal.querySelector('.wcrq-modal__error');
    var confirmButton = modal.querySelector('[data-modal-confirm]');
    var cancelButton = modal.querySelector('[data-modal-cancel]');
    var backdrop = modal.querySelector('[data-modal-close]');

    var activeOptions = null;
    var lastFocused = null;

    function closeModal() {
      modal.setAttribute('hidden', 'hidden');
      dialog.setAttribute('aria-hidden', 'true');
      errorEl.textContent = '';
      inputEl.value = '';
      activeOptions = null;
      if (lastFocused && typeof lastFocused.focus === 'function') {
        lastFocused.focus();
      }
    }

    function openModal(options) {
      activeOptions = options || {};
      lastFocused = document.activeElement;
      titleEl.textContent = activeOptions.title || '';
      messageEl.textContent = activeOptions.message || '';
      confirmButton.textContent = activeOptions.confirmLabel || 'OK';
      cancelButton.textContent = activeOptions.cancelLabel || 'Anuluj';
      errorEl.textContent = '';
      inputEl.value = '';

      if (activeOptions.prompt) {
        promptEl.hidden = false;
        promptLabel.textContent = activeOptions.prompt.label || '';
        inputEl.placeholder = activeOptions.prompt.placeholder || '';
      } else {
        promptEl.hidden = true;
      }

      modal.removeAttribute('hidden');
      dialog.removeAttribute('aria-hidden');
      window.setTimeout(function () {
        if (activeOptions.prompt) {
          inputEl.focus();
        } else {
          confirmButton.focus();
        }
      }, 50);
    }

    function handleConfirm() {
      if (!activeOptions) {
        return;
      }

      if (activeOptions.prompt) {
        var value = inputEl.value.trim();
        if (activeOptions.prompt.validate && !activeOptions.prompt.validate(value)) {
          errorEl.textContent = activeOptions.prompt.errorMessage || '';
          inputEl.focus();
          inputEl.select();
          return;
        }
        if (typeof activeOptions.onConfirm === 'function') {
          activeOptions.onConfirm(value);
        }
      } else if (typeof activeOptions.onConfirm === 'function') {
        activeOptions.onConfirm();
      }

      closeModal();
    }

    function handleCancel() {
      closeModal();
      if (activeOptions && typeof activeOptions.onCancel === 'function') {
        activeOptions.onCancel();
      }
    }

    confirmButton.addEventListener('click', handleConfirm);
    cancelButton.addEventListener('click', handleCancel);
    backdrop.addEventListener('click', handleCancel);

    modal.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        handleCancel();
      }
    });

    return openModal;
  }

  function init() {
    var openModal = setupModal();
    if (!openModal) {
      return;
    }

    var deleteLinks = document.querySelectorAll('.wcrq-result-delete');
    deleteLinks.forEach(function (link) {
      link.addEventListener('click', function (event) {
        event.preventDefault();
        var title = link.getAttribute('data-confirm-title') || '';
        var message = link.getAttribute('data-confirm-message') || '';
        openModal({
          title: title,
          message: message,
          confirmLabel: link.textContent.trim() || 'Usuń',
          cancelLabel: 'Anuluj',
          onConfirm: function () {
            window.location.href = link.href;
          },
        });
      });
    });

    var clearForm = document.querySelector('.wcrq-results-clear');
    if (clearForm) {
      var hiddenInput = clearForm.querySelector('.wcrq-results-clear__value');
      var submitButton = clearForm.querySelector('[type="submit"], button[type="submit"]');
      if (submitButton) {
        submitButton.addEventListener('click', function (event) {
          event.preventDefault();
          var title = clearForm.getAttribute('data-confirm-title') || '';
          var message = clearForm.getAttribute('data-confirm-message') || '';
          var promptLabel = clearForm.getAttribute('data-prompt-label') || 'Wynik równania 1+2';
          var promptError = clearForm.getAttribute('data-prompt-error') || 'Nieprawidłowy wynik. Spróbuj ponownie.';
          openModal({
            title: title,
            message: message,
            confirmLabel: submitButton.textContent.trim() || 'Usuń',
            cancelLabel: 'Anuluj',
            prompt: {
              label: promptLabel,
              placeholder: '',
              errorMessage: promptError,
              validate: function (value) {
                return value === '3';
              },
            },
            onConfirm: function (value) {
              if (hiddenInput) {
                hiddenInput.value = value;
              }
              clearForm.submit();
            },
          });
        });
      }
    }
  }

  ready(init);
})();
