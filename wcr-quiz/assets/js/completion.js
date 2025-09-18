(function() {
  function init() {
    var button = document.querySelector('.wcrq-completion-login-button');
    if (!button) {
      return;
    }
    var container = document.querySelector('.wcrq-relogin-container');
    if (!container) {
      return;
    }
    button.addEventListener('click', function() {
      if (container.hasAttribute('hidden')) {
        container.removeAttribute('hidden');
        button.setAttribute('disabled', 'disabled');
        var input = container.querySelector('input[name="wcrq_login"]');
        if (input) {
          input.focus();
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
