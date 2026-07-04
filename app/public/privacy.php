<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\I18n\Locale;
use App\View\ViewFactory;

$config = require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

$locale = Locale::resolve($config, null);
$view = ViewFactory::create(__DIR__ . '/../templates', $locale, (string) ($config['i18n']['default_locale'] ?? 'fr'));

echo $view->render('legal', [
    'page'      => 'privacy',
    'available' => Locale::available($config),
]);
