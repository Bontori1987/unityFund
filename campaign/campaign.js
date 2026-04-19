document.addEventListener('DOMContentLoaded', function () {
    const dots = document.querySelectorAll('#dotNav .dot'); // ← thêm #dotNav để chỉ lấy dots trong slider
    const slides = document.querySelectorAll('.slide-img');
    let activeDot = 0;

    console.log('dots:', dots.length, 'slides:', slides.length); // kiểm tra

    if (dots.length === 0 || slides.length === 0) return;

    function setDot(index) {
        // Giới hạn index không vượt quá array
        if (index < 0 || index >= dots.length) return;

        dots[activeDot].classList.remove('active');
        slides[activeDot].classList.remove('active');
        activeDot = index;
        dots[activeDot].classList.add('active');
        slides[activeDot].classList.add('active');
    }

    function nextDot() { setDot((activeDot + 1) % dots.length); }
    function prevDot() { setDot((activeDot - 1 + dots.length) % dots.length); }

    window.setDot = setDot;
    window.nextDot = nextDot;
    window.prevDot = prevDot;

    function showToast() {
        const t = document.getElementById('toastMsg');
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2500);
    }

    window.handleShare = function () {
        const url = window.location.href;
        const title = document.getElementById('campaignTitle')?.textContent || '';
        if (navigator.share) {
            navigator.share({ title, url });
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(showToast);
        } else {
            showToast();
        }
    }
});
