document.addEventListener('DOMContentLoaded', function() {
  var form = document.querySelector('.wcrq-quiz');
  if (!form) return;
  form.classList.remove('wcrq-no-js');

  var dataset = form.dataset || {};
  var startTimestamp = parseInt(dataset.startTimestamp || '0', 10);
  var endTimestamp = parseInt(dataset.endTimestamp || '0', 10);
  var serverNow = parseInt(dataset.serverNow || '0', 10);
  var allowNavigation = dataset.allowNavigation === '1';
  var hasEnd = !isNaN(endTimestamp) && endTimestamp > 0;
  var offsetMs = !isNaN(serverNow) && serverNow > 0 ? Date.now() - serverNow * 1000 : 0;

  var timerPanel = form.querySelector('.wcrq-timer-panel');
  var remainingEl = timerPanel ? timerPanel.querySelector('.wcrq-timer-remaining') : null;
  var elapsedEl = timerPanel ? timerPanel.querySelector('.wcrq-timer-elapsed') : null;
  var noLimitText = remainingEl ? remainingEl.textContent : '';

  function getCurrentTimestamp() {
    if (!offsetMs) {
      return Math.round(Date.now() / 1000);
    }
    return Math.round((Date.now() - offsetMs) / 1000);
  }

  function formatDuration(totalSeconds) {
    totalSeconds = Math.max(0, totalSeconds);
    var hours = Math.floor(totalSeconds / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var seconds = totalSeconds % 60;
    var parts = [hours, minutes, seconds].map(function(value) {
      return value < 10 ? '0' + value : String(value);
    });
    return parts.join(':');
  }

  function updateTimers() {
    if (!timerPanel) {
      return;
    }
    var nowTs = getCurrentTimestamp();
    if (elapsedEl && !isNaN(startTimestamp) && startTimestamp > 0) {
      var elapsedSeconds = Math.max(0, nowTs - startTimestamp);
      elapsedEl.textContent = formatDuration(elapsedSeconds);
    }
    if (!remainingEl) {
      return;
    }
    if (!hasEnd) {
      remainingEl.textContent = noLimitText || remainingEl.textContent;
      return;
    }
    var remainingSeconds = Math.max(0, endTimestamp - nowTs);
    remainingEl.textContent = formatDuration(remainingSeconds);
    if (remainingSeconds <= 0 && form.dataset.submitted !== '1') {
      form.dataset.submitted = '1';
      form.submit();
    }
  }

  if (timerPanel) {
    updateTimers();
    setInterval(updateTimers, 1000);
  }

  var questions = Array.from(form.querySelectorAll('.wcrq-question'));
  if (!questions.length) return;

  var tabs = Array.from(form.querySelectorAll('.wcrq-question-tab'));
  var prevBtn = form.querySelector('.wcrq-prev');
  var nextBtn = form.querySelector('.wcrq-next');
  var submitBtn = form.querySelector('.wcrq-submit');
  var current = 0;
  var quizData = window.wcrqQuizData || {};
  var resultId = parseInt(quizData.resultId || 0, 10);
  var shouldTrackViolations = !!quizData.trackViolations;
  var showViolationMessage = !!quizData.showViolationMessage;
  var warningBox = showViolationMessage ? form.querySelector('.wcrq-quiz-warning') : null;
  var violationCount = 0;
  var lastViolationAt = 0;
  var requiredMessage = quizData.needAnswerMessage || '';

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
    if (!shouldTrackViolations) {
      return;
    }
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

  if (shouldTrackViolations) {
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

  function getQuestionErrorMessage(question) {
    if (!question) {
      return requiredMessage;
    }
    var message = question.dataset ? question.dataset.requiredMessage : '';
    if (typeof message === 'string' && message.length) {
      return message;
    }
    return requiredMessage;
  }

  function hideQuestionError(question) {
    if (!question) {
      return;
    }
    question.classList.remove('is-error');
    var messageEl = question.querySelector('.wcrq-question-error-message');
    if (messageEl) {
      messageEl.textContent = '';
      messageEl.setAttribute('hidden', 'hidden');
    }
  }

  function showQuestionError(question) {
    if (!question) {
      return;
    }
    question.classList.add('is-error');
    var messageEl = question.querySelector('.wcrq-question-error-message');
    if (!messageEl) {
      return;
    }
    messageEl.textContent = getQuestionErrorMessage(question);
    messageEl.removeAttribute('hidden');
  }

  function markAnswered(index) {
    if (!tabs.length) return;
    var question = questions[index];
    if (!question) {
      return;
    }
    var answered = !!question.querySelector('input[type="radio"]:checked');
    tabs[index].classList.toggle('is-answered', answered);
    if (answered) {
      hideQuestionError(question);
    }
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

  function ensureAnswered(index) {
    if (index < 0 || index >= questions.length) {
      return true;
    }
    var question = questions[index];
    if (!question) {
      return true;
    }
    if (question.querySelector('input[type="radio"]:checked')) {
      hideQuestionError(question);
      return true;
    }
    showQuestionError(question);
    return false;
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
          if (idx > current && !ensureAnswered(current)) {
            return;
          }
          setActive(idx);
        }
      });
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function() {
      if (!ensureAnswered(current)) {
        return;
      }
      setActive(current + 1);
    });
  }

  setActive(0);
});
