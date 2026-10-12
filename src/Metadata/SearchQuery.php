<?php
declare(strict_types=1);
namespace App\Metadata;
final class SearchQuery
{
    public static function validate(string $query): void
    {
        if ($query==='' || mb_strlen($query)>200 || str_contains($query,'\\') || str_starts_with($query,'/') ||
            preg_match('~^(?:[A-Za-z]:|[a-z]+://)|[\x00-\x1f]~i',$query)) {
            throw new \InvalidArgumentException('Enter a title without a file path (up to 200 characters).');
        }
    }
}
