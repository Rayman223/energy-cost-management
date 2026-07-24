// ── Horloge du header applicatif (#193, #200) ───────────────────────────────
// Alimente l'horloge du bandeau commun (partial _header.php) présent sur toutes
// les pages de la coquille app. Chargé en `defer`. Défensif : ne fait rien si le
// header (donc l'horloge) est absent de la page.
//
// L'heure affichée suit le fuseau du PROFIL de l'utilisateur (attribut
// `data-timezone` posé par _header.php depuis user_profiles.timezone), et non
// celui du navigateur (#200). On lit les parties de l'heure murale du fuseau via
// `Intl.DateTimeFormat`, puis on les réassemble en Date UTC pour indexer les
// libellés jour/mois. Repli sur le fuseau navigateur si l'identifiant est absent
// ou invalide (Intl lève alors `RangeError`) — jamais d'exception à l'affichage.
(function clock() {
  const timeEl = document.getElementById('clock-time');
  const dateEl = document.getElementById('clock-date');
  if (!timeEl || !dateEl) return;
  const DAYS   = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
  const MONTHS = ['jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

  const clockEl = timeEl.closest('.clock');
  const tz = (clockEl && clockEl.dataset.timezone) || undefined;
  const opts = {
    hour12: false,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  };
  let dtf;
  try {
    dtf = new Intl.DateTimeFormat('en-US', tz ? { ...opts, timeZone: tz } : opts);
  } catch (e) {
    dtf = new Intl.DateTimeFormat('en-US', opts); // fuseau invalide → navigateur
  }

  const pad = (n) => (n < 10 ? '0' : '') + n;

  function tick() {
    const p = {};
    dtf.formatToParts(new Date()).forEach((part) => { p[part.type] = part.value; });
    // Réassemble l'heure murale en Date UTC, puis relit TOUTES les parties depuis
    // `wall`. Passer un éventuel '24' (minuit rendu par Safari, jour = veille) à
    // Date.UTC bascule proprement au lendemain : heure ET date restent cohérentes.
    const wall = new Date(Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second));
    timeEl.textContent = `${pad(wall.getUTCHours())}:${pad(wall.getUTCMinutes())}:${pad(wall.getUTCSeconds())}`;
    dateEl.textContent = `${DAYS[wall.getUTCDay()]} ${wall.getUTCDate()} ${MONTHS[wall.getUTCMonth()]} ${wall.getUTCFullYear()}`;
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
