<?php

declare(strict_types=1);

/** @var list<string> $available @var ?string $discordUrl @var ?string $donateUrl
 * @var \App\Seo\PageMeta $meta @var int $raised @var int $goal @var int $stripeCents
 * @var int $githubCents @var int $year @var list<array{name:string}> $supporters
 * @var bool $dbAvailable @var array{badge:bool,public:bool} $member @var bool $loggedIn @var bool $isAdmin
 * @var ?string $stripeUrl @var ?string $githubUrl @var string $csrfField
 */
$progress = $goal > 0 ? min(100, (int) floor($raised * 100 / $goal)) : 0;
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head><?= $this->partial('_head', ['title' => $title . ' — ' . $this->t('app.title'), 'css' => ['assets/css/app-header.css', 'assets/css/lang-switcher.css', 'assets/css/support.css'], 'meta' => $meta]) ?></head>
<body><div class="support-wrap">
  <?= $this->partial('_header', ['subtitle' => $title, 'current' => 'support', 'authenticated' => $loggedIn, 'discordUrl' => $discordUrl, 'donateUrl' => $donateUrl, 'available' => $available]) ?>
  <main>
    <section class="support-hero"><p class="eyebrow">Energy Cost Management</p><h1><?= $this->te('support.title') ?></h1><p><?= $this->te('support.intro') ?></p></section>
    <section class="support-card"><h2><?= $this->te('support.costs') ?></h2><p><?= $this->te('support.costs_text') ?></p>
      <?php if ($dbAvailable && $goal > 0): ?><div class="support-goal"><h2><?= $this->te('support.goal', ['year' => $year]) ?></h2><div class="support-numbers"><strong><?= $this->e($this->money($raised / 100)) ?></strong><span><?= $this->te('support.raised') ?> / <?= $this->e($this->money($goal / 100)) ?></span></div><progress max="100" value="<?= $progress ?>" aria-label="<?= $progress ?>%"></progress><p class="support-note"><?= $this->te('support.fees') ?></p></div><?php endif; ?>
      <?php if (!$dbAvailable): ?><p class="support-note" role="status"><?= $this->te('support.unavailable') ?></p><?php endif; ?>
    </section>
    <section class="support-methods">
      <?php if ($stripeUrl !== null): ?><article class="support-card"><h2><?= $this->te('support.stripe') ?></h2><a class="support-button" href="<?= $this->e($stripeUrl) ?>" rel="noopener noreferrer" target="_blank"><?= $this->te('support.stripe') ?></a></article><?php endif; ?>
      <?php if ($githubUrl !== null): ?><article class="support-card"><h2><?= $this->te('support.github') ?></h2><a class="support-button secondary" href="<?= $this->e($githubUrl) ?>" rel="noopener noreferrer" target="_blank"><?= $this->te('support.github') ?></a></article><?php endif; ?>
    </section>
    <section class="support-card"><h2><?= $this->te('support.free_help') ?></h2><p><?= $this->te('support.free_text') ?></p><p><a href="https://github.com/Rayman223/energy-cost-management/issues" target="_blank" rel="noopener noreferrer"><?= $this->te('support.report_issue') ?></a></p></section>
    <?php if ($member['badge']): ?><section class="support-card"><h2>⚡ <?= $this->te('support.badge') ?></h2><p><?= $this->te('support.badge_description') ?></p>
      <form method="post" action="<?= $this->e($this->url('support/visibility')) ?>"><?= $csrfField ?><label><input type="checkbox" name="public_opt_in" value="1"<?= $member['public'] ? ' checked' : '' ?>> <?= $this->te('support.wall_consent') ?></label><button type="submit"><?= $this->te('support.save') ?></button></form>
    </section><?php endif; ?>
    <section class="support-card"><h2><?= $this->te('support.wall') ?></h2><?php if ($supporters === []): ?><p><?= $this->te('support.no_names') ?></p><?php else: ?><ul class="supporter-list"><?php foreach ($supporters as $supporter): ?><li><?= $this->e($supporter['name']) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
    <?php if ($isAdmin): ?><section class="support-card"><h2><?= $this->te('support.admin_badge') ?></h2><form method="post" action="<?= $this->e($this->url('support/visibility')) ?>"><?= $csrfField ?><label><?= $this->te('support.user_id') ?> <input type="number" name="admin_user_id" min="1" required></label><label><?= $this->te('support.badge') ?> <select name="badge_enabled"><option value="1"><?= $this->te('common.yes') ?></option><option value="0"><?= $this->te('common.no') ?></option></select></label><button type="submit"><?= $this->te('common.save') ?></button></form></section><?php endif; ?>
  </main>
</div></body></html>
