<?php

declare(strict_types=1);

namespace SilverShop\ProductIdentity\Tests;

use SilverShop\ProductIdentity\GtinValidator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;

/**
 * Unit coverage for the stateless {@link GtinValidator}: normalisation, the GS1 mod-10 check across all four GTIN
 * lengths, and the block/warn enforcement applied to a ValidationResult.
 */
class GtinValidatorTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testValidCodesAcrossAllLengths(): void
    {
        $this->assertTrue(GtinValidator::isValid('96385074'), 'EAN-8');
        $this->assertTrue(GtinValidator::isValid('036000291452'), 'UPC-A (12)');
        $this->assertTrue(GtinValidator::isValid('4006381333931'), 'EAN-13');
        $this->assertTrue(GtinValidator::isValid('14006381333938'), 'GTIN-14');
    }

    public function testRejectsBadChecksum(): void
    {
        $this->assertFalse(GtinValidator::isValid('4006381333930'), 'EAN-13 with wrong check digit');
        $this->assertFalse(GtinValidator::isValid('96385070'), 'EAN-8 with wrong check digit');
    }

    public function testRejectsWrongLength(): void
    {
        $this->assertFalse(GtinValidator::isValid('12345'), '5 digits');
        $this->assertFalse(GtinValidator::isValid('400638133'), '9 digits');
        $this->assertFalse(GtinValidator::isValid('400638133393100'), '15 digits');
    }

    public function testRejectsNonNumeric(): void
    {
        $this->assertFalse(GtinValidator::isValid('4006381A33931'));
        $this->assertFalse(GtinValidator::isValid('notanumber12'));
    }

    public function testRejectsEmpty(): void
    {
        $this->assertFalse(GtinValidator::isValid(''));
        $this->assertFalse(GtinValidator::isValid('   '));
    }

    public function testNormaliseStripsSpacesAndHyphens(): void
    {
        $this->assertSame('4006381333931', GtinValidator::normalise('40 06381-333931'));
        $this->assertSame('4006381333931', GtinValidator::normalise('4-0-0-6-3-8-1-3-3-3-9-3-1'));
        $this->assertSame('4006381333931', GtinValidator::normalise('  4006381333931  '));
    }

    public function testIsValidNormalisesSeparators(): void
    {
        $this->assertTrue(GtinValidator::isValid('40 06381-333931'));
        $this->assertTrue(GtinValidator::isValid('4-0-0-6-3-8-1-3-3-3-9-3-1'));
    }

    public function testValidateFieldAllowsEmpty(): void
    {
        $result = ValidationResult::create();
        GtinValidator::validateField('GTIN', '', $result);
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getMessages());
    }

    public function testValidateFieldAllowsValid(): void
    {
        $result = ValidationResult::create();
        GtinValidator::validateField('GTIN', '4006381333931', $result);
        $this->assertTrue($result->isValid());
    }

    public function testValidateFieldBlocksInvalidInBlockMode(): void
    {
        Config::modify()->set(GtinValidator::class, 'validation_mode', GtinValidator::MODE_BLOCK);

        $result = ValidationResult::create();
        GtinValidator::validateField('GTIN', '4006381333930', $result);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getMessages());
    }

    public function testValidateFieldWarnsButAllowsInWarnMode(): void
    {
        Config::modify()->set(GtinValidator::class, 'validation_mode', GtinValidator::MODE_WARN);

        $result = ValidationResult::create();
        GtinValidator::validateField('GTIN', '4006381333930', $result);

        $this->assertTrue($result->isValid(), 'A warning must not invalidate the result');
        $this->assertNotEmpty($result->getMessages());
    }

    public function testValidationModeDefaultsToBlock(): void
    {
        // SapphireTest resets config between tests, so with no override the private-static default applies.
        $this->assertSame(GtinValidator::MODE_BLOCK, GtinValidator::validationMode());
    }

    public function testValidationModeFallsBackToBlockForUnknownValue(): void
    {
        Config::modify()->set(GtinValidator::class, 'validation_mode', 'nonsense');
        $this->assertSame(GtinValidator::MODE_BLOCK, GtinValidator::validationMode());
    }
}
