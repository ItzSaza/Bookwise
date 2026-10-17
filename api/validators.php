<?php

declare(strict_types=1);

/**
 * Custom Validation Exception supporting single or multi-field errors.
 */
class ValidationError extends Exception
{
    private string $field;
    private array $errors;

    public function __construct(string $fieldOrMessage, string|array $messageOrErrors = '', int $code = 422, ?Throwable $previous = null)
    {
        if (is_array($messageOrErrors)) {
            $this->field = '';
            $this->errors = $messageOrErrors;
            $firstMessage = reset($messageOrErrors) ?: 'Validation failed.';
            parent::__construct((string) $firstMessage, $code, $previous);
        } else {
            $this->field = $fieldOrMessage;
            $msg = $messageOrErrors !== '' ? (string) $messageOrErrors : $fieldOrMessage;
            $this->errors = $this->field !== '' ? [$this->field => $msg] : [];
            parent::__construct($msg, $code, $previous);
        }
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

/**
 * Validates and trims a string field with min and max character lengths.
 */
function v_str(array $in, string $key, int $min, int $max, bool $required = true, string $label = ''): ?string
{
    $displayName = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key));
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$displayName is required.");
        }
        return null;
    }

    $val = trim((string) $raw);
    if ($val === '') {
        if ($required) {
            throw new ValidationError($key, "$displayName cannot be whitespace-only.");
        }
        return null;
    }

    $len = mb_strlen($val);
    if ($len < $min || $len > $max) {
        throw new ValidationError($key, "$displayName must be between $min and $max characters.");
    }

    return $val;
}

/**
 * Validates an integer field within optional min and max bounds.
 */
function v_int(array $in, string $key, ?int $min = null, ?int $max = null, bool $required = true, string $label = ''): ?int
{
    $displayName = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key));
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$displayName is required.");
        }
        return null;
    }

    $val = filter_var($raw, FILTER_VALIDATE_INT);
    if ($val === false) {
        throw new ValidationError($key, "$displayName must be an integer.");
    }

    if ($min !== null && $val < $min) {
        throw new ValidationError($key, "$displayName cannot be less than $min.");
    }
    if ($max !== null && $val > $max) {
        throw new ValidationError($key, "$displayName cannot exceed $max.");
    }

    return $val;
}

/**
 * Validates a float/decimal field within optional min and max bounds.
 */
function v_float(array $in, string $key, ?float $min = null, ?float $max = null, bool $required = true, string $label = ''): ?float
{
    $displayName = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key));
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$displayName is required.");
        }
        return null;
    }

    if (!is_numeric($raw)) {
        throw new ValidationError($key, "$displayName must be a numeric value.");
    }

    $val = (float) $raw;
    if (!is_finite($val)) {
        throw new ValidationError($key, "$displayName must be a finite number.");
    }

    if ($min !== null && $val < $min) {
        throw new ValidationError($key, "$displayName cannot be less than $min.");
    }
    if ($max !== null && $val > $max) {
        throw new ValidationError($key, "$displayName cannot exceed $max.");
    }

    return $val;
}

/**
 * Validates that a string is within a predefined whitelist of allowed values.
 */
function v_enum(array $in, string $key, array $allowed, bool $required = true, string $label = ''): ?string
{
    $displayName = $label !== '' ? $label : ucfirst(str_replace('_', ' ', $key));
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$displayName is required.");
        }
        return null;
    }

    $val = (string) $raw;
    if (!in_array($val, $allowed, true)) {
        throw new ValidationError($key, "Invalid $displayName. Allowed: " . implode(', ', $allowed) . '.');
    }

    return $val;
}

/**
 * Validates an email address and checks maximum length.
 */
function v_email(array $in, string $key, bool $required = true, int $max = 150, string $label = 'Email'): ?string
{
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$label is required.");
        }
        return null;
    }

    $val = trim((string) $raw);
    if ($val === '') {
        if ($required) {
            throw new ValidationError($key, "$label cannot be whitespace-only.");
        }
        return null;
    }

    if (mb_strlen($val) > $max) {
        throw new ValidationError($key, "$label cannot exceed $max characters.");
    }

    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
        throw new ValidationError($key, "Enter a valid email address.");
    }

    return $val;
}

/**
 * Validates a Sri Lankan phone number (supports +947XXXXXXXX, 07XXXXXXXX, 011XXXXXXX, etc.).
 */
function v_phone(string $raw, bool $required = true, string $label = 'Phone number'): ?string
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        if ($required) {
            throw new ValidationError('phone', "$label is required.");
        }
        return null;
    }

    $clean = preg_replace('/[\s-]/', '', $trimmed);
    if (!preg_match('/^(?:\+94|0)\d{9}$/', $clean)) {
        throw new ValidationError('phone', 'Enter a valid Sri Lankan phone number (e.g. 0771234567 or +94771234567).');
    }

    return $clean;
}

/**
 * Validates a date string against a specific format (default: Y-m-d).
 */
function v_date(array $in, string $key, bool $required = true, string $format = 'Y-m-d', string $label = 'Date'): ?string
{
    $raw = $in[$key] ?? null;
    if ($raw === null || $raw === '') {
        if ($required) {
            throw new ValidationError($key, "$label is required.");
        }
        return null;
    }

    $val = trim((string) $raw);
    $parsed = DateTimeImmutable::createFromFormat("!$format", $val);
    if ($parsed === false || $parsed->format($format) !== $val) {
        throw new ValidationError($key, "Enter a valid $label in $format format.");
    }

    return $val;
}

/**
 * Validates that an items list is a non-empty array.
 */
function v_items_array(array $in, string $key = 'items', int $minItems = 1, string $label = 'Items'): array
{
    $items = $in[$key] ?? null;
    if (!is_array($items) || count($items) < $minItems) {
        throw new ValidationError($key, "At least $minItems " . strtolower($label) . " is required.");
    }

    return $items;
}
