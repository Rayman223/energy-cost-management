// Confirmation de soumission générique, sans handler inline (CSP enforced, #73).
// Tout formulaire portant data-confirm="<message>" demande confirmation avant envoi ;
// remplace les ex-attributs onsubmit="return confirm(...)".
(function () {
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm) {
      if (!window.confirm(form.dataset.confirm)) {
        e.preventDefault();
      }
    }
  });
})();
