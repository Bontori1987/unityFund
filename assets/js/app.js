// UnityFund — shared JS utilities

// Auto-dismiss alerts after 5 s
document.querySelectorAll('.alert-dismissible').forEach(el => {
    setTimeout(() => el.classList.add('fade'), 4500);
});

// Confirm before destructive actions (data-confirm attribute)
document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
        if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
});
