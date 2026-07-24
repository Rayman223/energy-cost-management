// ── Horloge du header applicatif (#193) ─────────────────────────────────────
// Alimente l'horloge du bandeau commun (partial _header.php) présent sur toutes
// les pages de la coquille app. Chargé en `defer`. Défensif : ne fait rien si le
// header (donc l'horloge) est absent de la page.
(function clock() {
  const timeEl = document.getElementById('clock-time');
  const dateEl = document.getElementById('clock-date');
  if (!timeEl || !dateEl) return;
  const DAYS   = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
  const MONTHS = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
  function tick() {
    const now = new Date();
    timeEl.textContent = now.toTimeString().slice(0, 8);
    dateEl.textContent = `${DAYS[now.getDay()]} ${now.getDate()} ${MONTHS[now.getMonth()]} ${now.getFullYear()}`;
  }
  tick();
  setInterval(tick, 1000);
})();

// ── Sélecteur de langue : fermeture au clic extérieur / Échap ────────────────
// Amélioration progressive du <details class="lang-menu"> : sans ce code le menu
// reste pleinement fonctionnel (ouverture/fermeture au clic sur le résumé).
(function langMenu() {
  const menu = document.querySelector('details.lang-menu');
  if (!menu) return;
  document.addEventListener('click', (e) => {
    if (menu.open && !menu.contains(e.target)) menu.open = false;
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && menu.open) menu.open = false;
  });
})();
