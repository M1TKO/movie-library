<?php

declare(strict_types=1);

namespace App\Discovery;

final class ScanOptions
{
    public const EXTENSIONS = ['mkv','mp4','avi','mov','m4v','mpg','mpeg','wmv','ts','m2ts','webm'];
    public const EXCLUSIONS = ['sample', 'samples', 'trailer', 'trailers', '*-sample.*', '*.sample.*', '*-trailer.*', '*.trailer.*'];

    public static function extensions(string $input): array
    {
        $values = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower(trim($input))))));
        if (!$values || count($values) > 50) {
            throw new \InvalidArgumentException('Enter between 1 and 50 video extensions.');
        }
        foreach ($values as $value) {
            if (!preg_match('/^[a-z0-9]{1,10}$/D', $value)) {
                throw new \InvalidArgumentException('Extensions must contain only letters and numbers, without dots.');
            }
        }
        return $values;
    }

    public static function exclusions(string $input): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $input)))));
        if (count($values) > 100 || strlen($input) > 10000) {
            throw new \InvalidArgumentException('Use at most 100 exclusion rules (10,000 characters).');
        }
        foreach ($values as $value) {
            if (str_contains($value, "\0") || str_contains($value, '..')) {
                throw new \InvalidArgumentException('Exclusions cannot contain null bytes or parent-directory segments.');
            }
        }
        return $values;
    }

    /** Case-insensitive portable glob rules against a basename or root-relative path. */
    public static function excluded(string $relative, array $rules): bool
    {
        $relative = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $relative) : $relative;
        foreach (explode('/', $relative) as $part) {
            foreach ($rules as $rule) {
                $regex = '/^' . str_replace(['\*','\?'], ['.*','.'], preg_quote($rule, '/')) . '$/iuD';
                if (preg_match($regex, $part) || preg_match($regex, $relative)) {
                    return true;
                }
            }
        }
        return false;
    }
}
