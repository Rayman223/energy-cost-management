// ── Parc de batteries (#26) : afficher la part T1 quand elle sert ───────────
// Amélioration progressive du formulaire rendu par app/templates/batteries.php.
// La part T1 n'entre dans le calcul que pour le profil de décharge « ratio » ;
// la laisser visible ailleurs ferait saisir un chiffre inerte.
//
// Sans ce script, le champ reste affiché et saisissable : le serveur l'ignore
// hors profil « ratio » (BatteryRepository::params()), la page demeure donc
// pleinement utilisable. Chargé en `defer`, défensif — ne fait rien si le
// formulaire est absent.
(function batteryDischargeProfile() {
  const select = document.querySelector('select[data-t1-target]');
  if (!select) return;

  const row = document.getElementById(select.dataset.t1Target);
  if (!row) return;

  const input = row.querySelector('input');

  const sync = () => {
    const option = select.options[select.selectedIndex];
    const needed = option && option.dataset.requiresT1 === '1';
    row.classList.toggle('bat-row-hidden', !needed);
    // `required` posé ici et non dans le HTML : un champ masqué mais requis bloque
    // la soumission sur un message que le navigateur ne peut pas montrer.
    if (input) input.required = Boolean(needed);
  };

  select.addEventListener('change', sync);
  sync();
})();
