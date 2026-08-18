Closes #

## Summary

<!-- What changes and why. One paragraph is usually enough. -->

## Local checks

<!-- Paste the outcome, not just a tick. The CI reruns all of them anyway. -->

- [ ] `find app -name '*.php' -print0 | xargs -0 -n1 php -l` — syntax lint
- [ ] `vendor/bin/phpunit` — integration tests auto-skip without a DB
- [ ] `phpstan analyse --configuration=phpstan.dist.neon` — level 6, no new baseline entry

## Notes

<!--
Anything a reviewer needs: a schema migration, a new config key, a behaviour
change for existing deployments, a deliberate limitation. Delete if empty.
-->
