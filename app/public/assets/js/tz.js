// ── Conversion de fuseau horaire (affichage & saisie) ───────────────────────
// La base stocke toutes les dates en UTC (chaînes 'YYYY-MM-DD HH:MM:SS' sans
// offset). L'utilisateur, lui, raisonne dans SON fuseau (window.APP_TIMEZONE,
// identifiant IANA injecté par le template depuis user_profiles.timezone).
//
// Ce module expose window.TZ :
//   - formatReadingAt(dbUtc)      : 'YYYY-MM-DD HH:MM' affiché dans le fuseau user
//   - dateFromDbUtc(dbUtc)        : objet Date (instant absolu) depuis une chaîne UTC
//   - localInputToDbUtc(date,time): saisie locale (fuseau user) → chaîne UTC pour l'API
//
// Chargé en `defer` AVANT dashboard.js / meter-readings.js : l'ordre des scripts
// defer est garanti, donc window.TZ est prêt à leur exécution. Sans APP_TIMEZONE
// (fallback), on retombe sur UTC — comportement neutre, jamais d'exception.
(function () {
  'use strict';

  function appTz() {
    return (typeof window !== 'undefined' && window.APP_TIMEZONE) ? window.APP_TIMEZONE : 'UTC';
  }

  // Une chaîne DB UTC 'YYYY-MM-DD HH:MM:SS' → instant absolu (Date).
  function dateFromDbUtc(dbUtc) {
    if (typeof dbUtc !== 'string' || dbUtc.length < 16) return new Date(NaN);
    // 'YYYY-MM-DD HH:MM[:SS]' → ISO UTC en remplaçant l'espace et en suffixant 'Z'.
    return new Date(dbUtc.slice(0, 19).replace(' ', 'T') + 'Z');
  }

  // Décalage (ms) entre l'heure murale d'un fuseau IANA et l'UTC, à l'instant `date`.
  // Positif à l'est de Greenwich (ex. Europe/Brussels été = +2h = +7200000).
  function tzOffsetMs(date, tz) {
    var dtf = new Intl.DateTimeFormat('en-US', {
      timeZone: tz, hour12: false,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
    var p = {};
    dtf.formatToParts(date).forEach(function (part) { p[part.type] = part.value; });
    var h = p.hour === '24' ? 0 : +p.hour; // Safari peut rendre '24' à minuit.
    var asUtc = Date.UTC(+p.year, +p.month - 1, +p.day, h, +p.minute, +p.second);
    return asUtc - date.getTime();
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  // Affiche une chaîne DB UTC dans le fuseau user, au format 'YYYY-MM-DD HH:MM'.
  function formatReadingAt(dbUtc) {
    var d = dateFromDbUtc(dbUtc);
    if (isNaN(d.getTime())) {
      return typeof dbUtc === 'string' ? dbUtc.slice(0, 16) : ''; // repli défensif
    }
    var off = tzOffsetMs(d, appTz());
    var local = new Date(d.getTime() + off); // parts UTC de `local` = heure murale user
    return local.getUTCFullYear() + '-' + pad(local.getUTCMonth() + 1) + '-' + pad(local.getUTCDate())
      + ' ' + pad(local.getUTCHours()) + ':' + pad(local.getUTCMinutes());
  }

  // Saisie locale (dans le fuseau user) 'YYYY-MM-DD' + 'HH:MM' → chaîne UTC
  // 'YYYY-MM-DD HH:MM:SS' à envoyer à l'API.
  function localInputToDbUtc(dateStr, timeStr) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateStr || '');
    var t = /^(\d{2}):(\d{2})/.exec(timeStr || '');
    if (!m || !t) return (dateStr || '') + ' ' + (timeStr || '') + ':00'; // repli : envoi brut
    // Instant supposant d'abord que la saisie est en UTC…
    var guessMs = Date.UTC(+m[1], +m[2] - 1, +m[3], +t[1], +t[2], 0);
    // …puis on retire le décalage du fuseau user à ce moment pour obtenir l'UTC réel.
    var off = tzOffsetMs(new Date(guessMs), appTz());
    var d = new Date(guessMs - off);
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate())
      + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
  }

  window.TZ = {
    appTz: appTz,
    dateFromDbUtc: dateFromDbUtc,
    formatReadingAt: formatReadingAt,
    localInputToDbUtc: localInputToDbUtc,
  };
})();
