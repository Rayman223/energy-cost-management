// Confirmation générique intégrée au design du site, sans handler inline (CSP).
// Les formulaires ou liens portant data-confirm="<message>" ouvrent une modale.
(function () {
  var activeModal = null;
  var previouslyFocused = null;
  var previousBodyOverflow = '';

  function label(name, fallback) {
    return document.documentElement.dataset[name] || fallback;
  }

  function removeModal() {
    if (!activeModal) return;
    document.removeEventListener('keydown', onKeydown);
    activeModal.remove();
    activeModal = null;
    document.body.style.overflow = previousBodyOverflow;
    previousBodyOverflow = '';
    if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
      previouslyFocused.focus();
    }
    previouslyFocused = null;
  }

  function onKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      removeModal();
      return;
    }

    if (e.key !== 'Tab' || !activeModal) return;
    var focusable = activeModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function openConfirm(message, onConfirm, opts) {
    opts = opts || {};
    removeModal();
    previouslyFocused = document.activeElement;
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    var backdrop = document.createElement('div');
    backdrop.className = 'site-modal-backdrop';
    backdrop.dataset.confirmModal = 'true';

    var dialog = document.createElement('div');
    dialog.className = 'site-modal';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'site-confirm-title');
    dialog.setAttribute('aria-describedby', 'site-confirm-message');

    var title = document.createElement('h2');
    title.className = 'site-modal-title';
    title.id = 'site-confirm-title';
    title.textContent = label('confirmTitle', 'Confirmation');

    var text = document.createElement('p');
    text.className = 'site-modal-message';
    text.id = 'site-confirm-message';
    text.textContent = message;

    var actions = document.createElement('div');
    actions.className = 'site-modal-actions';

    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'site-modal-cancel';
    cancel.textContent = label('confirmCancel', 'Cancel');

    var confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = opts.danger ? 'site-modal-confirm is-danger' : 'site-modal-confirm';
    confirm.textContent = opts.confirmText || label('confirmOk', 'Confirm');

    cancel.addEventListener('click', removeModal);
    confirm.addEventListener('click', function () {
      removeModal();
      onConfirm();
    });
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) removeModal();
    });

    actions.append(cancel, confirm);
    dialog.append(title, text, actions);
    backdrop.appendChild(dialog);
    document.body.appendChild(backdrop);
    activeModal = backdrop;
    document.addEventListener('keydown', onKeydown);
    cancel.focus();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmAccepted === 'true') {
      return;
    }

    e.preventDefault();
    openConfirm(form.dataset.confirm, function () {
      form.dataset.confirmAccepted = 'true';
      // requestSubmit()/submit() ne transmettent pas le name/value du bouton
      // cliqué : les formulaires concernés portent leur intention dans un champ
      // caché <input name="action">, jamais dans le bouton d'envoi.
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
      delete form.dataset.confirmAccepted;
    }, { confirmText: form.dataset.confirmOk, danger: form.hasAttribute('data-confirm-danger') });
  });

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('a[data-confirm]') : null;
    if (!link || link.dataset.confirmAccepted === 'true') return;

    e.preventDefault();
    openConfirm(link.dataset.confirm, function () {
      link.dataset.confirmAccepted = 'true';
      window.location.href = link.href;
    }, { confirmText: link.dataset.confirmOk, danger: link.hasAttribute('data-confirm-danger') });
  });
})();
