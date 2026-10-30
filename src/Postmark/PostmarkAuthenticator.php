<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Postmark;

/**
 * Class PostmarkAuthenticator
 *
 * Postmark signs no webhooks; it authenticates them with HTTP Basic credentials
 * embedded in the webhook URL (`https://user:pass@example.com/webhooks/mail/postmark`).
 * This checks the request's `Authorization` header against the configured pair in
 * constant time. Empty credentials never authenticate.
 *
 * @package EzPhp\MailEvents\Postmark
 */
final readonly class PostmarkAuthenticator
{
    /**
     * @param string $username
     * @param string $password
     */
    public function __construct(
        private string $username,
        private string $password,
    ) {
    }

    /**
     * @param string|null $authorization The `Authorization` header value.
     *
     * @return bool
     */
    public function verify(?string $authorization): bool
    {
        if ($this->username === '' || $this->password === '' || $authorization === null) {
            return false;
        }

        return hash_equals('Basic ' . base64_encode($this->username . ':' . $this->password), trim($authorization));
    }
}
