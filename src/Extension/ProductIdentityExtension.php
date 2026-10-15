<?php

declare(strict_types=1);

namespace SilverShop\ProductIdentity\Extension;

use SilverShop\ProductIdentity\GtinValidator;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;

/**
 * Adds the product-identity master data — EAN/GTIN and MPN — to {@link \SilverShop\Page\Product}.
 *
 * These are locale-invariant codes, so they are deliberately plain (non-translatable) {@code $db} fields. The GTIN
 * is normalised to digits-only on set/write and validated (GS1 mod-10) through {@see GtinValidator}; an empty value
 * is always allowed. For a simple product the GTIN is the product's own barcode; for a product with variations it
 * acts as the group-level fallback for variants that have no GTIN of their own (see
 * {@link \SilverShop\ProductIdentity\Extension\VariationIdentityExtension}).
 *
 * @extends Extension<\SilverShop\Page\Product>
 * @property string $GTIN
 * @property string $MPN
 */
class ProductIdentityExtension extends Extension
{
    private static array $db = [
        'GTIN' => 'Varchar(14)',
        'MPN' => 'Varchar(70)',
    ];

    /**
     * Normalise the GTIN as it is set (importers, POS, {@code Product::create([...])}, {@code $product->GTIN = …})
     * so the record always holds a digits-only code before validation and storage. The CMS save path normalises
     * via {@see self::onBeforeWrite()} instead.
     */
    public function setGTIN(mixed $value): void
    {
        $this->getOwner()->setField('GTIN', GtinValidator::normalise((string) $value));
    }

    /**
     * Resolved GTIN for this buyable: a simple product resolves to its own code. This is a stable accessor so
     * consumers (feed, SEO JSON-LD, tracking, POS) never read the raw field directly.
     */
    public function getResolvedGtin(): string
    {
        return trim((string) $this->getOwner()->getField('GTIN'));
    }

    /**
     * Resolved MPN for this buyable: a product resolves to its own MPN.
     */
    public function getResolvedMpn(): string
    {
        return trim((string) $this->getOwner()->getField('MPN'));
    }

    /**
     * Whether this buyable carries any product identifier (a GTIN or an MPN). Supports consumers' feed
     * {@code identifier_exists} logic.
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
     * Enforce GTIN validity on save (and programmatic {@code ->write()}): empty is allowed; a non-empty invalid
     * code blocks the save (or warns, per {@code GtinValidator.validation_mode}). Fired by
     * {@see \SilverStripe\ORM\DataObject::validate()}.
     */
    protected function updateValidate(ValidationResult $result): void
    {
        GtinValidator::validateField('GTIN', (string) $this->getOwner()->getField('GTIN'), $result);
    }

    /**
     * Place the GTIN + MPN fields directly after the SKU ({@code InternalItemID}) on the "Content" tab, next to the
     * other product-identity codes.
     *
     * {@link \SilverShop\Page\Product} auto-scaffolds extension {@code $db} fields into the edit form, so the GTIN
     * and MPN are already present when this hook runs; removing them first and then re-inserting them once
     * guarantees {@link \SilverStripe\Forms\FieldList::getDataFields()} can never see a field twice ("appears
     * twice" fatal). {@code insertAfter()} recurses into the tab that holds {@code InternalItemID} and appends as a
     * fallback if that field is ever absent.
     */
    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName(['GTIN', 'MPN']);

        $gtin = TextField::create('GTIN', _t(GtinValidator::class . '.GTINLabel', 'EAN / GTIN'))
            ->setAttribute('pattern', '\d{8}|\d{12,14}')
            ->setAttribute('inputmode', 'numeric')
            ->setDescription(_t(
                GtinValidator::class . '.GTINDesc',
                'Barcode number (EAN-8, UPC-A, EAN-13 or GTIN-14). Digits only; the check digit is validated.'
            ));
        $mpn = TextField::create('MPN', _t(GtinValidator::class . '.MPNLabel', 'MPN (manufacturer part number)'))
            ->setDescription(_t(
                GtinValidator::class . '.MPNDesc',
                'The manufacturer part number, if the product has one.'
            ));

        $fields->insertAfter('InternalItemID', $gtin);
        $fields->insertAfter('GTIN', $mpn);
    }
}
