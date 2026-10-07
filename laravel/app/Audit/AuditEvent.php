<?php

namespace App\Audit;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class AuditEvent
{
    private const MAX_EVENT_LENGTH = 120;

    private const MAX_SUBJECT_TYPE_LENGTH = 100;

    private const MAX_PAYLOAD_DEPTH = 16;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'cookies',
        'session_id',
        'csrf_token',
    ];

    public string $event;

    public ?string $subjectType;

    public ?int $subjectId;

    /** @var array<array-key, mixed>|null */
    public ?array $oldValues;

    /** @var array<array-key, mixed>|null */
    public ?array $newValues;

    /** @var array<array-key, mixed>|null */
    public ?array $metadata;

    /**
     * Payloads are explicit allowlists selected by the caller. They must never
     * come from Model::toArray(), request payloads, or complete model attributes.
     *
     * @param  array<array-key, mixed>|null  $oldValues
     * @param  array<array-key, mixed>|null  $newValues
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function __construct(
        string $event,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
    ) {
        $this->event = $this->normalizeEvent($event);
        [$this->subjectType, $this->subjectId] = $this->normalizeSubject($subjectType, $subjectId);
        $this->oldValues = $this->normalizePayload($oldValues, 'oldValues');
        $this->newValues = $this->normalizePayload($newValues, 'newValues');
        $this->metadata = $this->normalizePayload($metadata, 'metadata');
    }

    private function normalizeEvent(string $event): string
    {
        $event = trim($event);

        if (
            $event === ''
            || mb_strlen($event) > self::MAX_EVENT_LENGTH
            || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $event) !== 1
        ) {
            throw new InvalidArgumentException('The audit event must be a lowercase semantic identifier of at most 120 characters.');
        }

        return $event;
    }

    /** @return array{?string, ?int} */
    private function normalizeSubject(?string $type, int|string|null $id): array
    {
        if ($type === null && $id === null) {
            return [null, null];
        }

        if ($type === null || $id === null) {
            throw new InvalidArgumentException('Audit subject type and ID must both be null or both be present.');
        }

        $type = trim($type);
        if (
            $type === ''
            || mb_strlen($type) > self::MAX_SUBJECT_TYPE_LENGTH
            || preg_match('/\A[a-z][a-z0-9_]*\z/', $type) !== 1
        ) {
            throw new InvalidArgumentException('The audit subject type must be a stable snake_case identifier of at most 100 characters.');
        }

        $normalizedId = filter_var($id, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($normalizedId === false || (is_string($id) && (string) $normalizedId !== $id)) {
            throw new InvalidArgumentException('The audit subject ID must be a positive integer.');
        }

        return [$type, $normalizedId];
    }

    /**
     * @param  array<array-key, mixed>|null  $payload
     * @return array<array-key, mixed>|null
     */
    private function normalizePayload(?array $payload, string $name): ?array
    {
        if ($payload === null) {
            return null;
        }

        /** @var array<array-key, mixed> */
        return $this->normalizeArray($payload, $name, 0);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $values, string $path, int $depth): array
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new InvalidArgumentException("The audit payload {$path} exceeds the maximum nesting depth.");
        }

        $normalized = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array($this->normalizeKey($key), self::FORBIDDEN_KEYS, true)) {
                throw new InvalidArgumentException("The audit payload {$path} contains a forbidden key.");
            }

            $valuePath = $path.'.'.(string) $key;
            $normalized[$key] = match (true) {
                is_array($value) => $this->normalizeArray($value, $valuePath, $depth + 1),
                $value instanceof BackedEnum => $value->value,
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                is_float($value) && ! is_finite($value) => throw new InvalidArgumentException("The audit payload {$valuePath} contains a non-finite number."),
                is_scalar($value), $value === null => $value,
                default => throw new InvalidArgumentException("The audit payload {$valuePath} contains an unsupported value."),
            };
        }

        return $normalized;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim($key)));
    }
}
