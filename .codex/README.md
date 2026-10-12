# Configuration Codex du dépôt

Les consignes de travail Claude partagées ont été adaptées aux mécanismes Codex :

- `../AGENTS.md` contient les conventions du dépôt et les consignes réutilisables auparavant définies dans `/home/rayman/.claude/CLAUDE.md`.
- `skills/fix-issue/SKILL.md` adapte le workflow du skill Claude `.claude/skills/fix-issue/SKILL.md`.

Les fichiers Claude `.claude/settings.json` et `.claude/settings.local.json` mélangent des réglages propres à Claude Code, à son environnement local et à son modèle. Leurs règles métier transférables sont dans `AGENTS.md` et le skill. Les permissions `Bash(...)` et `WebFetch(...)`, le mode `plan`, les répertoires additionnels et l'attribution « Generated with Claude » ne sont pas recopiés : Codex gère l'accès aux commandes, au réseau et aux fichiers via son propre environnement de permissions; le modèle et l'attribution Claude ne s'appliquent pas à Codex. La permission locale de consulter `www.synergrid.be` n'est pas transformée en accès réseau permanent.

Les worktrees sous `.claude/worktrees/` sont des copies de travail du dépôt, pas des fichiers de configuration ou de connaissance à migrer.
