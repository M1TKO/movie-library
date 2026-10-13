# Coding agent instructions

## PHP formatting

PHP code in `src/`, `tests/`, `migrations/`, `bin/`, and `public/` is formatted with PHP-CS-Fixer using the latest `@PER-CS` ruleset.

When adding or changing PHP code:

1. Run `composer format` to apply the formatter.
2. Run `composer format-check` to verify there are no formatting changes left.
3. Run the relevant tests, or `composer test` when the change affects shared behavior.

Do not hand-edit `.php-cs-fixer.php` or introduce formatter exceptions without explaining the reason in the change.
