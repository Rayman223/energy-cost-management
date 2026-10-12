-- Mise en service des compteurs (#81), borne incluse dans le fuseau du foyer.
-- Ne pas déduire la pose de created_at ou du premier relevé : ces dates peuvent
-- correspondre à une déclaration tardive ou à un historique incomplet.
-- NULL conserve donc le début inconnu du parc existant. Migration rejouable.
ALTER TABLE meters
    ADD COLUMN IF NOT EXISTS opened_on DATE NULL
        COMMENT 'Mise en service du compteur, borne INCLUSE ; NULL = debut inconnu (#81)'
        AFTER timezone;
