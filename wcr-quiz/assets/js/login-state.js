(function () {
  const storageKey = 'wcrqHasLoggedIn';

  function hasLoggedBefore() {
    try {
      return window.localStorage.getItem(storageKey) === '1';
    } catch (error) {
      return false;
    }
  }

  function rememberLogin() {
    try {
      window.localStorage.setItem(storageKey, '1');
    } catch (error) {
      // Ignore storage errors (e.g. private mode)
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    const loginState = window.wcrqLoginState || {};
    if (loginState.isLogged) {
      rememberLogin();
    }

    const form = document.querySelector('.wcrq-login');
    if (!form) {
      return;
    }

    const message = form.querySelector('.wcrq-login-message[data-message-type="no_session"]');
    if (message && !hasLoggedBefore()) {
      message.setAttribute('hidden', 'hidden');
    } else if (message) {
      message.removeAttribute('hidden');
    }
  });
})();
