<?php

declare(strict_types=1);

namespace EzPhp\MailEvents;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\RouterInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\MailEvents\Mailgun\MailgunSignature;
use EzPhp\MailEvents\Mailgun\MailgunWebhookController;
use EzPhp\MailEvents\SendGrid\SendGridSignature;
use EzPhp\MailEvents\SendGrid\SendGridWebhookController;

/**
 * Class MailEventsServiceProvider
 *
 * Binds the signature verifiers from config and, in boot(), registers a POST
 * route per provider that has both a key and a path configured. The routes
 * are CSRF-exempt — the provider's signature replaces the CSRF token.
 *
 * Events are dispatched through the container's EventDispatcher: register
 * ez-php/events' EventServiceProvider, or listeners never see them.
 *
 * Configuration keys (in config/mail_events.php or environment):
 *   - mail_events.tolerance            — max. webhook age in seconds (default 300)
 *   - mail_events.mailgun.signing_key  — Mailgun HTTP webhook signing key ('' = no route)
 *   - mail_events.mailgun.path         — route path (default /webhooks/mail/mailgun; '' = no route)
 *   - mail_events.sendgrid.public_key  — SendGrid verification key ('' = no route)
 *   - mail_events.sendgrid.path        — route path (default /webhooks/mail/sendgrid; '' = no route)
 *
 * @package EzPhp\MailEvents
 */
final class MailEventsServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(MailgunSignature::class, function (ContainerInterface $app): MailgunSignature {
            $config = $app->make(ConfigInterface::class);

            return new MailgunSignature($this->string($config, 'mail_events.mailgun.signing_key', ''), $this->tolerance($config));
        });

        $this->app->bind(SendGridSignature::class, function (ContainerInterface $app): SendGridSignature {
            $config = $app->make(ConfigInterface::class);

            return new SendGridSignature($this->string($config, 'mail_events.sendgrid.public_key', ''), $this->tolerance($config));
        });
    }

    /**
     * Register the webhook routes of the configured providers.
     *
     * @return void
     */
    public function boot(): void
    {
        try {
            $config = $this->app->make(ConfigInterface::class);
            $router = $this->app->make(RouterInterface::class);
        } catch (\Throwable) {
            return; // Config or router not bound (CLI or isolated test context) — no routes.
        }

        $routes = [
            [$this->string($config, 'mail_events.mailgun.signing_key', ''), $this->string($config, 'mail_events.mailgun.path', '/webhooks/mail/mailgun'), MailgunWebhookController::class],
            [$this->string($config, 'mail_events.sendgrid.public_key', ''), $this->string($config, 'mail_events.sendgrid.path', '/webhooks/mail/sendgrid'), SendGridWebhookController::class],
        ];

        foreach ($routes as [$key, $path, $controller]) {
            if ($key === '' || $path === '') {
                continue;
            }

            $route = $router->post($path, [$controller, '__invoke']);

            // withoutCsrf() lives on the framework's Route; RouterInterface only promises an object.
            if (method_exists($route, 'withoutCsrf')) {
                $route->withoutCsrf();
            }
        }
    }

    /**
     * @param ConfigInterface $config
     * @param string          $key
     * @param string          $default
     *
     * @return string
     */
    private function string(ConfigInterface $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * @param ConfigInterface $config
     *
     * @return int
     */
    private function tolerance(ConfigInterface $config): int
    {
        $value = $config->get('mail_events.tolerance', 300);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : 300;
    }
}
