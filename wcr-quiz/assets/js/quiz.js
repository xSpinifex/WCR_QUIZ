document.addEventListener('DOMContentLoaded', function() {
  var form = document.querySelector('.wcrq-quiz');
  if (!form) return;
  form.classList.remove('wcrq-no-js');

  var seconds = parseInt(form.dataset.duration || '0', 10);
  if (!isNaN(seconds) && seconds > 0) {
    var end = Date.now() + seconds * 1000;
    var timer = document.createElement('div');
    timer.className = 'wcrq-timer';
    form.insertBefore(timer, form.firstChild);
    var tick = function() {
      var remain = Math.max(0, Math.round((end - Date.now()) / 1000));
      var m = Math.floor(remain / 60);
      var s = ('0' + (remain % 60)).slice(-2);
      timer.textContent = m + ':' + s;
      if (remain <= 0) {
        form.submit();
      } else {
        setTimeout(tick, 1000);
      }
    };
    tick();
  }

  var questions = Array.from(form.querySelectorAll('.wcrq-question'));
  if (!questions.length) return;

  var tabs = Array.from(form.querySelectorAll('.wcrq-question-tab'));
  var prevBtn = form.querySelector('.wcrq-prev');
  var nextBtn = form.querySelector('.wcrq-next');
  var submitBtn = form.querySelector('.wcrq-submit');
  var allowNavigation = form.dataset.allowNavigation === '1';
  var current = 0;
  var quizData = window.wcrqQuizData || {};
  var resultId = parseInt(quizData.resultId || 0, 10);
  var warningBox = form.querySelector('.wcrq-quiz-warning');
  var violationCount = 0;
  var lastViolationAt = 0;

  function updateWarning(count) {
    violationCount = count;
    if (!warningBox) {
      return;
    }
    if (violationCount > 0) {
      warningBox.textContent = (quizData.violationMessage || '') + ' (' + violationCount + ')';
      warningBox.removeAttribute('hidden');
    } else {
      warningBox.textContent = '';
      warningBox.setAttribute('hidden', 'hidden');
    }
  }

  function postFormData(action, data) {
    if (!quizData.ajaxUrl) {
      return Promise.reject();
    }
    var formData = new FormData();
    formData.append('action', action);
    Object.keys(data).forEach(function(key) {
      formData.append(key, data[key]);
    });
    return fetch(quizData.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
  }

  function saveAnswer(index, value) {
    if (!quizData.saveNonce || !resultId) {
      return;
    }
    postFormData('wcrq_save_answer', {
      nonce: quizData.saveNonce,
      question: index,
      answer: value,
      resultId: resultId
    }).catch(function() {
      return null;
    });
  }

  function logViolation() {
    if (!quizData.violationNonce || !resultId) {
      updateWarning(violationCount + 1);
      return;
    }
    postFormData('wcrq_log_violation', {
      nonce: quizData.violationNonce
    }).then(function(response) {
      if (!response) {
        updateWarning(violationCount + 1);
        return;
      }
      return response.json();
    }).then(function(payload) {
      if (payload && payload.success && payload.data && typeof payload.data.count !== 'undefined') {
        updateWarning(parseInt(payload.data.count, 10) || 0);
      } else if (payload !== undefined) {
        updateWarning(violationCount + 1);
      }
    }).catch(function() {
      updateWarning(violationCount + 1);
    });
  }

  function shouldRegisterViolation() {
    var now = Date.now();
    if (now - lastViolationAt < 1000) {
      return false;
    }
    lastViolationAt = now;
    return true;
  }

  function handleVisibilityChange() {
    if (!document.hidden) {
      return;
    }
    if (!shouldRegisterViolation()) {
      return;
    }
    logViolation();
  }

  if (warningBox) {
    document.addEventListener('visibilitychange', handleVisibilityChange);
    window.addEventListener('blur', function() {
      if (shouldRegisterViolation()) {
        logViolation();
      }
    });
  }

  function updateButtons() {
    if (prevBtn) {
      if (allowNavigation) {
        prevBtn.style.display = '';
        prevBtn.disabled = current === 0;
      } else {
        prevBtn.style.display = 'none';
      }
    }
    if (nextBtn) {
      nextBtn.style.display = current >= questions.length - 1 ? 'none' : '';
    }
    if (submitBtn) {
      submitBtn.style.display = current === questions.length - 1 ? '' : 'none';
    }
  }

  function syncTabState(index) {
    if (!tabs.length) return;
    tabs.forEach(function(tab, i) {
      tab.classList.toggle('is-active', i === index);
      if (!allowNavigation) {
        tab.disabled = i !== index;
      }
    });
  }

  function markAnswered(index) {
    if (!tabs.length) return;
    var answered = !!questions[index].querySelector('input[type="radio"]:checked');
    tabs[index].classList.toggle('is-answered', answered);
  }

  function setActive(index) {
    if (index < 0 || index >= questions.length) return;
    current = index;
    questions.forEach(function(q, i) {
      q.classList.toggle('is-active', i === index);
    });
    syncTabState(index);
    updateButtons();
  }

  questions.forEach(function(question, index) {
    question.addEventListener('change', function() {
      markAnswered(index);
      var checked = question.querySelector('input[type="radio"]:checked');
      if (checked) {
        var value = parseInt(checked.value || '0', 10);
        if (!isNaN(value)) {
          saveAnswer(index, value);
        }
      }
    });
  });

  questions.forEach(function(_, index) {
    markAnswered(index);
  });

  if (allowNavigation) {
    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        setActive(current - 1);
      });
    }
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        var idx = parseInt(tab.dataset.index || '0', 10);
        if (!isNaN(idx)) {
          setActive(idx);
        }
      });
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function() {
      setActive(current + 1);
    });
  }

  setActive(0);
});
