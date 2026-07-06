<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\UploadLimits;
use PHPUnit\Framework\TestCase;

final class UploadLimitsTest extends TestCase
{
    public function testDetectsTruncatedPost(): void
    {
        // POST au-dessus de post_max_size : corps envoyé mais $_POST/$_FILES vidés.
        self::assertTrue(UploadLimits::postExceededLimit(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '20000000'],
            [],
            [],
        ));
    }

    public function testIgnoresNormalPostWithData(): void
    {
        self::assertFalse(UploadLimits::postExceededLimit(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '512'],
            ['_csrf' => 'x'],
            [],
        ));
    }

    public function testIgnoresPostWithUploadedFiles(): void
    {
        self::assertFalse(UploadLimits::postExceededLimit(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '512'],
            [],
            ['import_file' => ['name' => 'x.csv']],
        ));
    }

    public function testIgnoresNonPostAndEmptyBody(): void
    {
        self::assertFalse(UploadLimits::postExceededLimit(['REQUEST_METHOD' => 'GET'], [], []));
        self::assertFalse(UploadLimits::postExceededLimit(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0'], [], []));
    }
}
