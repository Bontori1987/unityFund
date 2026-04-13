// ── Dot navigation ──
    let activeDot = 4;
    const dots = document.querySelectorAll('.dot');
 
    function setDot(index) {
      dots[activeDot].classList.remove('active');
      activeDot = index;
      dots[activeDot].classList.add('active');
    }
 
    function nextDot() { setDot((activeDot + 1) % dots.length); }
    function prevDot() { setDot((activeDot - 1 + dots.length) % dots.length); }

//SHARE
    function handleShare() {
      const url = window.location.href;
      if (navigator.share) {
        navigator.share({ title: document.getElementById('campaignTitle').textContent, url });
      } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(showToast);
      } else {
        showToast();
      }
    }
    
    function showToast() {
      const t = document.getElementById('toastMsg');
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 2500);
    }
