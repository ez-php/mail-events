<?php

declare(strict_types=1);

namespace Tests\MailEvents;

use EzPhp\Testing\ApplicationTestCase as EzPhpApplicationTestCase;
use RuntimeException;

/**
 * Base class for mail-events tests that need a bootstrapped Application.
 *
 * Writes config/mail_events.php from mailEventsConfig() before bootstrap —
 * the provider registers its routes in boot(), so the config must be in
 * place before the application boots.
 *
 * @package Tests\MailEvents
 */
abstract class ApplicationTestCase extends EzPhpApplicationTestCase
{
    /**
     * The `mail_events` config array for this test class.
     *
     * @return array<string, mixed>
     */
    abstract protected function mailEventsConfig(): array;

    /**
     * @return string
     */
    protected function getBasePath(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ez-mail-events-test-' . uniqid('', true);
        $configDir = $path . DIRECTORY_SEPARATOR . 'config';

        mkdir($configDir, 0o777, true);

        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->mailEventsConfig(), true) . ";\n";

        if (file_put_contents($configDir . DIRECTORY_SEPARATOR . 'mail_events.php', $content) === false) {
            throw new RuntimeException('Failed to write mail_events.php for test at ' . $configDir);
        }

        return $path;
    }
}
