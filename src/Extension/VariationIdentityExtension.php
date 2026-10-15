<?php

declare(strict_types=1);

namespace SilverShop\ProductIdentity\Extension;

use SilverShop\ProductIdentity\GtinValidator;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;

/**
 * Adds the product-identity master data — EAN/GTIN and MPN — to {@link \SilverShop\Model\Variation\Variation}.
 *
 * EAN is per-variant: each sellable variant has its own barcode, so the variation field is the authoritative GTIN.
 * When a variant leaves its GTIN/MPN blank, the resolved accessors fall back to the parent product's code — this is
 * the single place that variant→product fallback lives, so consumers never re-implement it. As on the product the
 * codes are locale-invariant plain {@code $db} fields, normalised to digits-only and GS1-validated via
 * {@see GtinValidator}.
 *
 * @extends Extension<\SilverShop\Model\Variation\Variation>
 * @property string $GTIN
 * @property string $MPN
 */
class VariationIdentityExtension extends Extension
{
    private static array $db = [
        'GTIN' => 'Varchar(14)',
        'MPN' => 'Varchar(70)',
    ];

    /**
     * Normalise the GTIN as it is set (importers, POS, {@code Variation::create([...])}, {@code $variation->GTIN = …})
     * so the record always holds a digits-only code before validation and storage. The CMS save path normalises via
     * {@see self::onBeforeWrite()} instead.
     */
    public function setGTIN(mixed $value): void
    {
        $this->getOwner()->setField('GTIN', GtinValidator::normalise((string) $value));
    }

    /**
     * Resolved GTIN for this variant: its own code when set, otherwise the parent product's. The one place the
     * variant→product fallback is implemented.
     */
    public function getResolvedGtin(): string
    {
        $own = trim((string) $this->getOwner()->getField('GTIN'));
        if ($own !== '') {
            return $own;
        }

        $product = $this->getOwner()->Product();

        return ($product && $product->exists()) ? trim((string) $product->getField('GTIN')) : '';
    }

    /**
     * Resolved MPN for this variant: its own code when set, otherwise the parent product's.
     */
    public function getResolvedMpn(): string
    {
        $own = trim((string) $this->getOwner()->getField('MPN'));
        if ($own !== '') {
            return $own;
        }

        $product = $this->getOwner()->Product();

        return ($product && $product->exists()) ? trim((string) $product->getField('MPN')) : '';
    }

    /**
     * Whether this variant carries any product identifier — its own or an inherited one.
     */
    public function getHasIdentifier(): bool
    {
        return $this->getResolvedGtin() !== '' || $this->getResolvedMpn() !== '';
    }

    /**
     * Keep the stored GTIN digits-only on every write (covers the CMS form save path, which bypasses the field
     * setter). Not calling parent::onBeforeWrite() — the Extension base does not define it.
     */
    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $gtin = (string) $owner->getField('GTIN');
        if ($gtin !== '') {
            $owner->setField('GTIN', GtinValidator::normalise($gtin));
        }
    }

    /**
     * Enforce GTIN validity on save (and programmatic {@code ->write()}): empty is allowed; a non-empty invalid code
     * blocks the save (or warns, per {@code GtinValidator.validation_mode}). Fired by
     * {@see \SilverStripe\ORM\DataObject::validate()}.
     */
    protected function updateValidate(ValidationResult $result): void
    {
        GtinValidator::validateField('GTIN', (string) $this->getOwner()->getField('GTIN'), $result);
    }

    /**
     * Place the GTIN + MPN fields directly after the variant's product code ({@code InternalItemID}) on the
     * variation edit form.
     *
     * {@link \SilverShop\Model\Variation\Variation} builds its CMS fields as a flat {@link FieldList} and does NOT
     * scaffold extension {@code $db} fields, so these are added by hand. The leading {@code removeByName()} keeps the
     * hook idempotent and mirrors the product hook; {@code insertAfter()} appends as a fallback if the code field is
     * ever absent.
     */
    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['GTIN', 'MPN']);

        $gtin = TextField::create('GTIN', _t(GtinValidator::class . '.GTINLabel', 'EAN / GTIN'))
            ->setAttribute('pattern', '\d{8}|\d{12,14}')
            ->setAttribute('inputmode', 'numeric')
            ->setDescription(_t(
                GtinValidator::class . '.GTINDescVariation',
                'Barcode for this specific variant (EAN-8, UPC-A, EAN-13 or GTIN-14). Digits only; the check '
                . 'digit is validated. Leave blank to use the product\'s GTIN.'
            ));
        $mpn = TextField::create('MPN', _t(GtinValidator::class . '.MPNLabel', 'MPN (manufacturer part number)'))
            ->setDescription(_t(
                GtinValidator::class . '.MPNDescVariation',
                'Manufacturer part number for this variant. Leave blank to use the product\'s MPN.'
            ));

        $fields->insertAfter('InternalItemID', $gtin);
        $fields->insertAfter('GTIN', $mpn);
    }
}
