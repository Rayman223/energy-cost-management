<?php

declare(strict_types=1);

use App\Http\SecurityHeaders;
use App\View\View;

require __DIR__ . '/../bootstrap.php';

SecurityHeaders::send();

echo (new View(__DIR__ . '/../templates'))->render('legal', [
    'title' => 'Politique de confidentialité',
    'page'  => 'privacy',
]);
