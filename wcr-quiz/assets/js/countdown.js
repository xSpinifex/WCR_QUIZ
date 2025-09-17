(function(){
  function format(seconds){
    var total = Math.max(0, seconds);
    var hours = Math.floor(total / 3600);
    var minutes = Math.floor((total % 3600) / 60);
    var secs = total % 60;
    var parts = [hours, minutes, secs].map(function(part){
      return String(part).padStart(2, '0');
    });
    return parts.join(':');
  }

  function initCountdown(el){
    var secondsAttr = el.getAttribute('data-countdown-seconds');
    if(!secondsAttr){
      return;
    }
    var remaining = parseInt(secondsAttr, 10);
    if(isNaN(remaining)){
      return;
    }
    var timeEl = el.querySelector('.wcrq-countdown-time');
    if(!timeEl){
      timeEl = document.createElement('span');
      timeEl.className = 'wcrq-countdown-time';
      el.appendChild(timeEl);
    }

    function tick(){
      timeEl.textContent = format(remaining);
      if(remaining <= 0){
        clearInterval(timer);
        el.classList.add('wcrq-countdown-finished');
        return;
      }
      remaining -= 1;
    }

    tick();
    var timer = setInterval(tick, 1000);
  }

  document.addEventListener('DOMContentLoaded', function(){
    var countdowns = document.querySelectorAll('.wcrq-countdown[data-countdown-seconds]');
    countdowns.forEach(initCountdown);
  });
})();
