<?php
namespace GTMAuditor;

final class ManualParameters {
    public const MAX = 10;
    public const MAX_VALUE_LENGTH = 100;

    private const IDENTIFIER_NAMES = ['email', 'phone', 'first_name', 'last_name', 'name', 'full_name', 'address', 'postcode', 'zip', 'dob', 'user_id', 'ip'];
    private const IDENTIFIER_FRAGMENTS = ['email', 'phone', 'password'];

    /**
     * Validates user-entered literal parameters against the event's standard parameters.
     * Returns a list of ['name' => string, 'value' => string]. Throws InvalidArgumentException
     * with one of the manual_parameter_* codes.
     */
    public static function clean(array $input, array $availableParameters): array {
        if (count($input) > self::MAX) throw new \InvalidArgumentException('manual_parameter_limit');
        $available = array_values(array_filter($availableParameters, 'is_string'));
        $clean = [];
        $seen = [];
        foreach ($input as $item) {
            $name = is_array($item) && is_string($item['name'] ?? null) ? trim($item['name']) : '';
            if (!self::validName($name)) throw new \InvalidArgumentException('manual_parameter_name');
            if (in_array($name, $available, true) || isset($seen[$name])) throw new \InvalidArgumentException('manual_parameter_conflict');
            $value = is_array($item) && is_scalar($item['value'] ?? null) ? trim((string)$item['value']) : '';
            if (!self::validValue($value)) throw new \InvalidArgumentException('manual_parameter_value');
            $seen[$name] = true;
            $clean[] = ['name' => $name, 'value' => $value];
        }
        return $clean;
    }

    /** Storage form: NULL when empty, so cleared and legacy rows look the same. */
    public static function encode(array $clean): ?string {
        if ($clean === []) return null;
        return json_encode(array_values($clean), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Reads a stored column back into the list shape. Missing or malformed values give []. */
    public static function decode(?string $stored): array {
        $decoded = json_decode($stored ?? '', true);
        if (!is_array($decoded)) return [];
        $list = [];
        foreach ($decoded as $item) {
            if (is_array($item) && is_string($item['name'] ?? null) && is_string($item['value'] ?? null)) {
                $list[] = ['name' => $item['name'], 'value' => $item['value']];
            }
        }
        return $list;
    }

    private static function validName(string $name): bool {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) return false;
        if (in_array($name, self::IDENTIFIER_NAMES, true)) return false;
        foreach (self::IDENTIFIER_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) return false;
        }
        return true;
    }

    private static function validValue(string $value): bool {
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) return false;
        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
