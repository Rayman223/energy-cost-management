-- ============================================================
-- Migration 2026-08-30 — parc de batteries domestiques (Issue #26)
-- NON baselinée : idempotente (CREATE TABLE IF NOT EXISTS), laissée hors du seed.
--
-- L'utilisateur veut savoir ce que sa batterie lui a fait économiser et en
-- combien de temps elle sera amortie. L'économie est CONTREFACTUELLE : les index
-- `import_t1/t2` du compteur intègrent déjà l'effet de la batterie — ce qu'elle a
-- évité de prélever au réseau n'y figure nulle part. Il faut donc ses propres
-- index, saisis à part du compteur.
--
-- Deux tables plutôt que des registres sur `meters` : le parc peut compter
-- PLUSIEURS batteries, chacune avec son matériel (marque, capacité), son
-- investissement et ses hypothèses de calcul — ce que le modèle à registres, qui
-- ne porte qu'une clé et une unité, ne sait pas représenter.
--
-- `pv_charge_share` et `discharge_profile` sont les deux HYPOTHÈSES du calcul,
-- assumées comme telles :
--   * l'origine de l'énergie chargée (photovoltaïque ou réseau) n'est pas
--     mesurable depuis les index de la batterie seule ; l'utilisateur la déclare ;
--   * la répartition jour/nuit de la DÉCHARGE ne l'est pas davantage à une
--     granularité journalière, d'où un profil choisi plutôt que deviné.
-- Les deux restent modifiables : ce sont des paramètres d'affichage du bilan, pas
-- des données mesurées, et les recalculer sur tout l'historique est voulu.
--
-- `decommissioned_on` est une borne de fin EXCLUE — premier jour où la batterie
-- n'est plus en service (#1, cf. app/docs/date-bounds.md), comme `valid_to`
-- ailleurs dans le schéma.
-- ============================================================

CREATE TABLE IF NOT EXISTS batteries (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              BIGINT UNSIGNED NOT NULL,
    brand                VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'Marque (libre)',
    model                VARCHAR(120) NOT NULL DEFAULT '' COMMENT 'Modele (libre)',
    capacity_kwh         DECIMAL(8,3) NOT NULL COMMENT 'Capacite nominale annoncee (kWh)',
    usable_capacity_kwh  DECIMAL(8,3) NULL COMMENT 'Capacite utile (profondeur de decharge) ; NULL = non renseignee',
    purchase_price       DECIMAL(10,2) NULL COMMENT 'Investissement TTC, primes deduites (devise du profil) ; NULL = amortissement non calculable',
    commissioned_on      DATE NOT NULL COMMENT 'Date de mise en service : origine de l amortissement',
    decommissioned_on    DATE NULL COMMENT 'Fin EXCLUE : premier jour hors service (#1) ; NULL = toujours en service',
    warranty_until       DATE NULL COMMENT 'Fin de garantie constructeur (informatif)',
    rated_cycles         SMALLINT UNSIGNED NULL COMMENT 'Nombre de cycles annonce par le constructeur (informatif)',
    pv_charge_share      TINYINT UNSIGNED NOT NULL DEFAULT 100
        COMMENT 'Hypothese : part de la charge venant du photovoltaique, en % (0-100). Le complement vient du reseau',
    discharge_profile    ENUM('import_mix', 't1', 't2', 'ratio') NOT NULL DEFAULT 'import_mix'
        COMMENT 'Hypothese de repartition jour/nuit de la decharge : mix reel des imports, tout T1, tout T2, ou ratio fixe',
    discharge_t1_share   TINYINT UNSIGNED NULL
        COMMENT 'Part T1 en % (0-100), utilisee UNIQUEMENT si discharge_profile = ratio',
    note                 VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Annotation libre (onduleur, installateur, remarque)',
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_batteries_user (user_id, commissioned_on),
    CONSTRAINT fk_batteries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Index CUMULÉS d'entrée et de sortie, à l'image des autres compteurs de l'app :
-- on stocke le relevé, jamais l'énergie d'une période — un delta se recalcule,
-- un index perdu ne se retrouve pas.
--
-- Les DEUX colonnes sont NULLABLES : beaucoup d'onduleurs n'exposent qu'un seul
-- des deux compteurs. Le delta d'une colonne se prend donc entre relevés
-- consécutifs NON NULS de CETTE colonne, indépendamment de l'autre.
--
-- `reading_at` est en UTC (fuseau de stockage du projet, cf. README) ; le
-- plafonnement à un relevé par jour civil se fait dans le fuseau de l'utilisateur,
-- côté applicatif, comme pour les index électriques.
CREATE TABLE IF NOT EXISTS battery_readings (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    battery_id          BIGINT UNSIGNED NOT NULL,
    reading_at          DATETIME NOT NULL COMMENT 'Horodatage du releve (UTC)',
    charge_index_kwh    DECIMAL(12,3) NULL COMMENT 'Index CUMULE de l energie entree dans la batterie (kWh) ; NULL = non releve',
    discharge_index_kwh DECIMAL(12,3) NULL COMMENT 'Index CUMULE de l energie sortie de la batterie (kWh) ; NULL = non releve',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_battery_readings (battery_id, reading_at),
    CONSTRAINT fk_battery_readings_battery FOREIGN KEY (battery_id) REFERENCES batteries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
