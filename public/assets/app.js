// Conferme di sicurezza per le azioni distruttive + invio dei form DELETE via fetch.
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
        e.preventDefault();
    }
});
