<?php

declare(strict_types=1);

namespace SilverShop\ProductIdentity\Tests;

use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\ProductIdentity\Extension\ProductIdentityExtension;
use SilverShop\ProductIdentity\Extension\VariationIdentityExtension;
use SilverShop\ProductIdentity\GtinValidator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;

/**
 * Model-level coverage: the extensions are applied, empty identifiers are allowed, a bad GTIN blocks the write on
 * both Product and Variation (and warn mode does not), the GTIN is normalised to digits-only, and the resolved
 * accessors implement the variant→product fallback.
 */
class ProductIdentityExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static bool $use_draft_site = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(GtinValidator::class, 'validation_mode', GtinValidator::MODE_BLOCK);
        Config::modify()->set(Product::class, 'global_allow_purchase', true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function newProduct(array $data = []): Product
    {
        return Product::create(array_merge(['Title' => 'Test product', 'BasePrice' => 10.0], $data));
    }

    public function testExtensionsAreApplied(): void
    {
        $this->assertTrue(Product::singleton()->hasExtension(ProductIdentityExtension::class));
        $this->assertTrue(Variation::singleton()->hasExtension(VariationIdentityExtension::class));
    }

    public function testEmptyIdentifiersAreAllowed(): void
    {
        $product = $this->newProduct();
        $this->assertTrue($product->validate()->isValid());
        $product->write();
        $this->assertGreaterThan(0, $product->ID);

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 10.0, 'InternalItemID' => 'V1']);
        $this->assertTrue($variation->validate()->isValid());
        $variation->write();
        $this->assertGreaterThan(0, $variation->ID);
    }

    public function testValidGtinSavesOnProduct(): void
    {
        $product = $this->newProduct(['GTIN' => '4006381333931', 'MPN' => 'ABC-123']);
        $this->assertTrue($product->validate()->isValid());
        $product->write();

        $this->assertSame('4006381333931', $product->GTIN);
        $this->assertSame('ABC-123', $product->MPN);
    }

    public function testBadGtinBlocksProductWrite(): void
    {
        $product = $this->newProduct(['GTIN' => '4006381333930']); // bad check digit
        $this->assertFalse($product->validate()->isValid());

        $this->expectException(ValidationException::class);
        $product->write();
    }

    public function testBadGtinBlocksVariationWrite(): void
    {
        $product = $this->newProduct();
        $product->write();

        $variation = Variation::create([
            'ProductID' => $product->ID,
            'Price' => 10.0,
            'InternalItemID' => 'V1',
            'GTIN' => '12345', // wrong length
        ]);
        $this->assertFalse($variation->validate()->isValid());

        $this->expectException(ValidationException::class);
        $variation->write();
    }

    public function testWarnModeDoesNotBlockWrite(): void
    {
        Config::modify()->set(GtinValidator::class, 'validation_mode', GtinValidator::MODE_WARN);

        $product = $this->newProduct(['GTIN' => '4006381333930']); // invalid, but warn mode
        $result = $product->validate();
        $this->assertTrue($result->isValid(), 'Warn mode must not invalidate the record');
        $this->assertNotEmpty($result->getMessages());

        $product->write(); // must not throw
        $this->assertGreaterThan(0, $product->ID);
    }

    public function testGtinNormalisedOnWrite(): void
    {
        $product = $this->newProduct(['GTIN' => '40 06381-333931']);
        // Normalised on set (the create()/__set path).
        $this->assertSame('4006381333931', $product->GTIN);

        $product->write();

        $reloaded = Product::get()->byID($product->ID);
        $this->assertInstanceOf(Product::class, $reloaded);
        $this->assertSame('4006381333931', $reloaded->GTIN);
    }

    public function testVariationUsesOwnGtinWhenSet(): void
    {
        $product = $this->newProduct(['GTIN' => '4006381333931']);
        $product->write();

        $variation = Variation::create([
            'ProductID' => $product->ID,
            'Price' => 10.0,
            'InternalItemID' => 'V-OWN',
            'GTIN' => '96385074',
        ]);
        $variation->write();

        $this->assertSame('96385074', $variation->getResolvedGtin());
    }

    public function testVariationFallsBackToProductIdentifiers(): void
    {
        $product = $this->newProduct(['GTIN' => '4006381333931', 'MPN' => 'PROD-MPN']);
        $product->write();

        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 10.0, 'InternalItemID' => 'V-INH']);
        $variation->write();

        $this->assertSame('4006381333931', $variation->getResolvedGtin());
        $this->assertSame('PROD-MPN', $variation->getResolvedMpn());
        $this->assertTrue($variation->getHasIdentifier());
    }

    public function testProductResolvedAccessors(): void
    {
        $product = $this->newProduct(['GTIN' => '4006381333931']);
        $this->assertSame('4006381333931', $product->getResolvedGtin());
        $this->assertSame('', $product->getResolvedMpn());
        $this->assertTrue($product->getHasIdentifier());

        $this->assertFalse($this->newProduct()->getHasIdentifier());
    }

    /**
     * The product edit form must contain GTIN and MPN exactly once (getDataFields() throws on a duplicate name —
     * the "appears twice" fatal we must avoid because Product auto-scaffolds extension $db fields), and they must
     * sit directly after the SKU (InternalItemID) on the Content tab.
     */
    public function testProductCmsFieldsRenderGtinAndMpnOnceAfterSku(): void
    {
        $dataFields = $this->newProduct()->getCMSFields()->getDataFields();

        $this->assertArrayHasKey('GTIN', $dataFields);
        $this->assertArrayHasKey('MPN', $dataFields);

        $keys = array_keys($dataFields);
        $sku = array_search('InternalItemID', $keys, true);
        $this->assertNotFalse($sku, 'InternalItemID (SKU) should be present');
        $this->assertSame($sku + 1, array_search('GTIN', $keys, true), 'GTIN should directly follow the SKU');
        $this->assertSame($sku + 2, array_search('MPN', $keys, true), 'MPN should directly follow the GTIN');
    }

    /**
     * The variation edit form must contain GTIN and MPN exactly once, directly after the variant's product code.
     */
    public function testVariationCmsFieldsRenderGtinAndMpnOnceAfterCode(): void
    {
        $product = $this->newProduct();
        $product->write();
        $variation = Variation::create(['ProductID' => $product->ID, 'Price' => 10.0, 'InternalItemID' => 'V1']);

        $dataFields = $variation->getCMSFields()->getDataFields();

        $this->assertArrayHasKey('GTIN', $dataFields);
        $this->assertArrayHasKey('MPN', $dataFields);

        $keys = array_keys($dataFields);
        $code = array_search('InternalItemID', $keys, true);
        $this->assertNotFalse($code, 'InternalItemID (product code) should be present');
        $this->assertSame($code + 1, array_search('GTIN', $keys, true), 'GTIN should directly follow the code');
        $this->assertSame($code + 2, array_search('MPN', $keys, true), 'MPN should directly follow the GTIN');
    }
}
