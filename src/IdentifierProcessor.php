<?php
namespace GTMAuditor;

final class IdentifierProcessor {
    public const VERSION = '1.1.0';
    public const FIELDS = ['email', 'phone', 'full_name', 'first_name', 'last_name'];
    private const ALIASES = [
        'email' => 'email',
        'email_address' => 'email',
        'emailaddress' => 'email',
        'phone' => 'phone',
        'phone_number' => 'phone',
        'phonenumber' => 'phone',
        'telephone' => 'phone',
        'tel' => 'phone',
        'name' => 'full_name',
        'full_name' => 'full_name',
        'fullname' => 'full_name',
        'firstname' => 'first_name',
        'first_name' => 'first_name',
        'fname' => 'first_name',
        'given_name' => 'first_name',
        'givenname' => 'first_name',
        'lastname' => 'last_name',
        'last_name' => 'last_name',
        'lname' => 'last_name',
        'family_name' => 'last_name',
        'familyname' => 'last_name',
    ];

    public function process(array $values, array $allowedFields, string $destination = 'common'): array {
        $allowedFields = array_values(array_intersect(self::FIELDS, array_unique(array_filter($allowedFields, 'is_string'))));
        $hashed = [];
        $errors = [];

        $canonicalValues = $this->canonicalValues($values);
        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $canonicalValues)) continue;
            $normalised = $this->normalise($field, $canonicalValues[$field], $destination);
            if (!$this->isValid($field, $normalised)) {
                $errors[$field] = $field === 'phone' ? 'e164_required' : 'invalid_value';
                continue;
            }
            $hashed[$field] = hash('sha256', $normalised);
        }

        return ['hashed' => $hashed, 'errors' => $errors, 'normalisation_version' => self::VERSION];
    }

    public function normalise(string $field, string $value, string $destination = 'common'): string {
        if (!in_array($field, self::FIELDS, true)) throw new \InvalidArgumentException('unsupported_identifier_field');
        $value = $this->unicodeTrim($value);

        if ($field === 'phone') return $this->normalisePhone($value);

        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        if (in_array($field, ['full_name', 'first_name', 'last_name'], true)) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        } elseif ($field === 'email' && $this->usesGoogleEmailRules($destination)) {
            $value = $this->normaliseGoogleEmail($value);
        }
        return $value;
    }

    public function rules(): array {
        return [
            'version' => self::VERSION,
            'processing_order' => ['consent_check', 'field_allowlist', 'normalise', 'validate', 'sha256', 'destination_map'],
            'email' => ['trim_unicode_whitespace', 'lowercase', 'google_gmail_rules_when_required'],
            'phone' => ['trim_unicode_whitespace', 'remove_formatting', 'require_e164'],
            'full_name' => ['trim_unicode_whitespace', 'lowercase', 'collapse_internal_whitespace'],
            'first_name' => ['trim_unicode_whitespace', 'lowercase', 'collapse_internal_whitespace'],
            'last_name' => ['trim_unicode_whitespace', 'lowercase', 'collapse_internal_whitespace'],
            'source_aliases' => self::ALIASES,
            'raw_values_persisted' => false,
        ];
    }

    public function sensitiveKeys(): array {
        return array_values(array_unique(array_merge(self::FIELDS, array_keys(self::ALIASES))));
    }

    private function canonicalValues(array $values): array {
        $canonical = [];
        foreach ($values as $field => $value) {
            if (!is_string($field) || !is_scalar($value)) continue;
            $normalisedKey = strtolower(trim($field));
            $target = self::ALIASES[$normalisedKey] ?? null;
            if ($target === null) continue;
            if (array_key_exists($target, $canonical) && $normalisedKey !== $target) continue;
            $canonical[$target] = (string)$value;
        }
        return $canonical;
    }

    private function unicodeTrim(string $value): string {
        return preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);
    }

    private function normalisePhone(string $value): string {
        $value = preg_replace('/^00/', '+', $value) ?? $value;
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return ($hasPlus ? '+' : '') . $digits;
    }

    private function normaliseGoogleEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2 || !in_array($parts[1], ['gmail.com', 'googlemail.com'], true)) return $email;
        $local = explode('+', $parts[0], 2)[0];
        return str_replace('.', '', $local) . '@' . $parts[1];
    }

    private function usesGoogleEmailRules(string $destination): bool {
        return in_array($destination, ['ga4', 'google_ads', 'google_customer_match', 'cm360'], true);
    }

    private function isValid(string $field, string $value): bool {
        if ($value === '' || strlen($value) > 320) return false;
        if ($field === 'email') return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        if ($field === 'phone') return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) === 1;
        return true;
    }
}
