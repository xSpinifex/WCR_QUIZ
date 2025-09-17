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
