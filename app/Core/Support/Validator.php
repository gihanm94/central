<?php
declare(strict_types=1);

namespace App\Core\Support;

/**
 * Validator::validate($data, ['email' => 'required|email|max:190|unique:users,email,5']);
 * Rules: required nullable email min:n max:n numeric integer date in:a,b,c same:field
 *        unique:table,column[,ignoreId[,idColumn]] exists:table,column password(strong) boolean
 */
final class Validator
{
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleString) {
            $rulesList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value     = $data[$field] ?? null;
            $value     = is_string($value) ? trim($value) : $value;
            $label     = $labels[$field] ?? str_replace('_', ' ', $field);
            $empty     = $value === null || $value === '' || $value === [];

            if (in_array('boolean', $rulesList, true)) {
                $clean[$field] = filter_var($value, FILTER_VALIDATE_BOOL);
                continue;
            }

            if ($empty) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = __(':Field is required.', ['Field' => ucfirst(__($label))]);
                } else {
                    $clean[$field] = null;
                }
                continue;
            }

            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : __('Enter a valid e-mail address.'),
                    'min'      => mb_strlen((string) $value) >= (int) $arg ? null : __(':Field must be at least :n characters.', ['Field' => ucfirst(__($label)), 'n' => $arg]),
                    'max'      => mb_strlen((string) $value) <= (int) $arg ? null : __(':Field must be :n characters or fewer.', ['Field' => ucfirst(__($label)), 'n' => $arg]),
                    'numeric'  => is_numeric($value) ? null : __(':Field must be a number.', ['Field' => ucfirst(__($label))]),
                    'integer'  => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : __(':Field must be a whole number.', ['Field' => ucfirst(__($label))]),
                    'date'     => strtotime((string) $value) ? null : __(':Field must be a date.', ['Field' => ucfirst(__($label))]),
                    'in'       => in_array((string) $value, explode(',', (string) $arg), true) ? null : __('Pick :field from the list.', ['field' => __($label)]),
                    'same'     => $value === ($data[$arg] ?? null) ? null : __(':Field does not match.', ['Field' => ucfirst(__($label))]),
                    'password' => preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', (string) $value) ? null : __('Use at least 8 characters with upper and lower case letters and a number.'),
                    'unique'   => self::unique($value, (string) $arg) ? null : __('This :field is already used.', ['field' => __($label)]),
                    'exists'   => self::exists($value, (string) $arg) ? null : __('Pick :field from the list.', ['field' => __($label)]),
                    default    => null,
                };
                if ($error) {
                    $errors[$field] = $error;
                    break;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    private static function unique(mixed $value, string $arg): bool
    {
        [$table, $column, $ignore, $idColumn] = array_pad(explode(',', $arg), 4, null);
        $idColumn ??= 'id';
        $sql = "SELECT 1 FROM {$table} WHERE lower({$column}::text) = lower(?)".($ignore ? " AND {$idColumn} <> ?" : '');

        return ! DB::scalar($sql, $ignore ? [$value, (int) $ignore] : [$value]);
    }

    private static function exists(mixed $value, string $arg): bool
    {
        [$table, $column] = array_pad(explode(',', $arg), 2, 'id');

        return (bool) DB::scalar("SELECT 1 FROM {$table} WHERE {$column} = ?", [$value]);
    }
}
