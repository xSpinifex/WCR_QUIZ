document.addEventListener('DOMContentLoaded', function() {
  var form = document.querySelector('.wcrq-quiz');
  if (!form) return;
  var seconds = parseInt(form.dataset.duration, 10);
  if (!seconds) return;
  var end = Date.now() + seconds * 1000;
  var timer = document.createElement('div');
  timer.className = 'wcrq-timer';
  form.insertBefore(timer, form.firstChild);
  function tick() {
    var remain = Math.max(0, Math.round((end - Date.now()) / 1000));
    var m = Math.floor(remain / 60);
    var s = ('0' + (remain % 60)).slice(-2);
    timer.textContent = m + ':' + s;
    if (remain <= 0) {
      form.submit();
    } else {
      setTimeout(tick, 1000);
    }
  }
  tick();
});
