<?php

declare(strict_types=1);

namespace EzPhp\MailEvents;

use DateTimeImmutable;

/**
 * Class Payload
 *
 * Typed reads from decoded webhook JSON, shared by the provider parsers.
 *
 * @internal
 *
 * @package EzPhp\MailEvents
 */
final class Payload
{
    /**
     * Read a nested value by path, or null when any step is missing.
     *
     * @param array<array-key, mixed> $data
     * @param string                  ...$path
     *
     * @return mixed
     */
    public static function get(array $data, string ...$path): mixed
    {
        $value = $data;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A non-empty string at $path, or null.
     *
     * @param array<array-key, mixed> $data
     * @param string                  ...$path
     *
     * @return string|null
     */
    public static function string(array $data, string ...$path): ?string
    {
        $value = self::get($data, ...$path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A required non-empty string at $path.
     *
     * @param array<array-key, mixed> $data
     * @param string                  ...$path
     *
     * @throws MailEventsException When it is missing.
     *
     * @return string
     */
    public static function requireString(array $data, string ...$path): string
    {
        return self::string($data, ...$path)
            ?? throw new MailEventsException(sprintf('Webhook payload lacks "%s".', implode('.', $path)));
    }

    /**
     * A required Unix timestamp (int, float or numeric string) at $path.
     *
     * @param array<array-key, mixed> $data
     * @param string                  ...$path
     *
     * @throws MailEventsException When it is missing or not numeric.
     *
     * @return DateTimeImmutable
     */
    public static function requireTime(array $data, string ...$path): DateTimeImmutable
    {
        $value = self::get($data, ...$path);

        if (!is_numeric($value)) {
            throw new MailEventsException(sprintf('Webhook payload lacks a numeric "%s".', implode('.', $path)));
        }

        return (new DateTimeImmutable())->setTimestamp((int) $value);
    }

    /**
     * The ISO 8601 timestamp at `$path` (Postmark, Amazon SES).
     *
     * @param array<array-key, mixed> $data
     * @param string                  ...$path
     *
     * @return DateTimeImmutable
     * @throws MailEventsException When the value is missing or not a date-time.
     */
    public static function requireIsoTime(array $data, string ...$path): DateTimeImmutable
    {
        $value = self::string($data, ...$path);

        if ($value === null) {
            throw new MailEventsException(sprintf('Webhook payload lacks "%s".', implode('.', $path)));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new MailEventsException(sprintf('Webhook payload "%s" is not a date-time.', implode('.', $path)));
        }
    }

    /**
     * Decode a JSON body into an array.
     *
     * @param string $json
     *
     * @throws MailEventsException When the body is not a JSON object or array.
     *
     * @return array<array-key, mixed>
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : throw new MailEventsException('Webhook body is not JSON.');
    }
}
