<?php

declare(strict_types=1);

namespace SilverShop\ProductIdentity;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationResult;

/**
 * Stateless helper for validating and normalising GS1 product identifiers (EAN-8, UPC-A, EAN-13 and GTIN-14).
 *
 * All logic lives here so it is reusable outside the CMS (importers, POS, catalogue matching, tests) and shared
 * identically by the {@link \SilverShop\ProductIdentity\Extension\ProductIdentityExtension} (Product) and
 * {@link \SilverShop\ProductIdentity\Extension\VariationIdentityExtension} (Variation) hooks.
 *
 * A valid code is: digits only (after stripping spaces and hyphens), of length 8, 12, 13 or 14, with a correct
 * GS1 mod-10 check digit. The check proves the code is *well-formed* only — not that it is a real, registered
 * product (that would need a GS1 / third-party lookup, out of scope for v1).
 */
class GtinValidator
{
    use Injectable;
    use Configurable;

    /**
     * Blocking enforcement: a non-empty, invalid code fails validation and prevents the record being saved.
     */
    public const MODE_BLOCK = 'block';

    /**
     * Non-blocking enforcement: a non-empty, invalid code adds a warning but still allows the record to be saved
     * (useful for shops mid data-cleanup).
     */
    public const MODE_WARN = 'warn';

    /**
     * The GTIN lengths accepted: EAN-8, UPC-A (12), EAN-13 and GTIN-14.
     *
     * @var array<int, int>
     */
    public const VALID_LENGTHS = [8, 12, 13, 14];

    /**
     * How a non-empty, invalid identifier is enforced in {@see self::validateField()}: self::MODE_BLOCK (default)
     * or self::MODE_WARN. Override in YAML:
     *
     * <code>
     * SilverShop\ProductIdentity\GtinValidator:
     *   validation_mode: warn
     * </code>
     */
    private static string $validation_mode = self::MODE_BLOCK;

    /**
     * Strip spaces and hyphens so a code entered as "40 06381-333931" is treated the same as "4006381333931".
     * Only separators are removed; any remaining non-digit characters are left intact so {@see self::isValid()}
     * can still reject them.
     */
    public static function normalise(string $code): string
    {
        return (string) preg_replace('/[\s-]/', '', $code);
    }

    /**
     * Whether $code is a well-formed GS1 identifier: digits only, a valid GTIN length, and a correct check digit.
     * An empty string is not "valid" here; empty values are allowed by {@see self::validateField()} separately.
     */
    public static function isValid(string $code): bool
    {
        $code = self::normalise($code);

        if ($code === '' || !ctype_digit($code)) {
            return false;
        }

        if (!in_array(strlen($code), self::VALID_LENGTHS, true)) {
            return false;
        }

        return self::hasValidCheckDigit($code);
    }

    /**
     * Apply the CMS/programmatic enforcement rule for one identifier field to a ValidationResult: an empty value
     * is always allowed; a non-empty value that fails {@see self::isValid()} is reported against $fieldName as an
     * error (block mode) or a warning (warn mode). Shared by the Product and Variation extensions'
     * {@code updateValidate()} hooks.
     */
    public static function validateField(string $fieldName, ?string $value, ValidationResult $result): void
    {
        $value = trim((string) $value);

        if ($value === '' || self::isValid($value)) {
            return;
        }

        $message = _t(
            self::class . '.INVALID',
            'Enter a valid EAN/GTIN: 8, 12, 13 or 14 digits with a correct check digit.'
        );

        if (self::validationMode() === self::MODE_WARN) {
            $result->addFieldMessage($fieldName, $message, ValidationResult::TYPE_WARNING);

            return;
        }

        $result->addFieldError($fieldName, $message);
    }

    /**
     * The configured enforcement mode, normalised to self::MODE_BLOCK or self::MODE_WARN (defaults to block for
     * any unrecognised value).
     */
    public static function validationMode(): string
    {
        $mode = strtolower((string) static::config()->get('validation_mode'));

        return $mode === self::MODE_WARN ? self::MODE_WARN : self::MODE_BLOCK;
    }

    /**
     * GS1 mod-10 check: right-aligned alternating weights 3,1,3,1,… over every digit except the last; the computed
     * check digit must equal the trailing digit. One routine covers all four GTIN lengths. $code is assumed to be
     * digits-only of a valid length (guaranteed by the callers above).
     */
    private static function hasValidCheckDigit(string $code): bool
    {
        $digits = str_split($code);
        $check = (int) array_pop($digits);

        $sum = 0;
        foreach (array_reverse($digits) as $index => $digit) {
            $weight = ($index % 2 === 0) ? 3 : 1;
            $sum += ((int) $digit) * $weight;
        }

        $computed = (10 - ($sum % 10)) % 10;

        return $computed === $check;
    }
}
