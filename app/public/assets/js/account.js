// ── Pré-remplissage du fuseau horaire (page compte, #200) ────────────────────
// Le défaut serveur d'un profil non configuré est 'UTC' (neutre, cohérent avec
// le stockage). Ce module pré-sélectionne alors le fuseau du NAVIGATEUR dans le
// <select>, pour que le 1er enregistrement capture le vrai fuseau de l'utilisateur
// plutôt que de figer UTC. Défensif et non destructif :
//   - ne touche à rien si le profil a déjà un fuseau explicite (valeur ≠ 'UTC') ;
//   - conserve 'UTC' si le navigateur ne fournit rien d'exploitable, ou si le
//     fuseau détecté n'est pas une option de la liste ;
//   - ne modifie que la sélection affichée : rien n'est enregistré sans un save.
// Chargé en `defer` : le <select> est déjà parsé à l'exécution.
(function timezonePrefill() {
  'use strict';

  const sel = document.querySelector('select[name="timezone"]');
  if (!sel || sel.value !== 'UTC') return; // profil déjà configuré → ne pas toucher

  let tz;
  try {
    tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
  } catch (e) {
    tz = '';
  }
  if (!tz || tz === 'UTC') return; // navigateur muet ou déjà UTC → repli UTC conservé

  // Ne bascule que si le fuseau détecté existe comme option (sinon UTC conservé).
  const exists = Array.prototype.some.call(sel.options, (o) => o.value === tz);
  if (exists) sel.value = tz;
})();
