<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Form;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RebuildForm;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Form
 * @group RebuildFormTest
 * Add your own group annotations below this line
 * @group Portable
 */
class RebuildFormTest extends Unit
{
    public function testSubmittingWithAllFieldsPresentIsValid(): void
    {
        $form = $this->createFormFactory()->create(RebuildForm::class);

        $form->submit([
            RebuildForm::FIELD_ALIAS_NAME => 'myshop_de_page',
            RebuildForm::FIELD_OPTIMIZE_FOR_BULK_LOAD => '1',
            RebuildForm::FIELD_REDIRECT_TO => '/search-index-alias/index?source=page&store=DE',
        ]);

        $this->assertTrue($form->isValid());
        $this->assertSame('myshop_de_page', $form->getData()[RebuildForm::FIELD_ALIAS_NAME]);
        $this->assertTrue($form->getData()[RebuildForm::FIELD_OPTIMIZE_FOR_BULK_LOAD]);
    }

    public function testOptimizeForBulkLoadDefaultsToUncheckedWhenOmitted(): void
    {
        // A checkbox that isn't present in submitted data (e.g. an unchecked HTML checkbox, which the
        // browser simply never includes in the POST body) must resolve to false, not throw or leave
        // the field unset.
        $form = $this->createFormFactory()->create(RebuildForm::class);

        $form->submit([
            RebuildForm::FIELD_ALIAS_NAME => 'myshop_de_page',
            RebuildForm::FIELD_REDIRECT_TO => '',
        ]);

        $this->assertTrue($form->isValid());
        $this->assertFalse($form->getData()[RebuildForm::FIELD_OPTIMIZE_FOR_BULK_LOAD]);
    }

    public function testRedirectToIsNullWhenOmittedFromSubmittedData(): void
    {
        // A partial submit (redirectTo simply absent from the POST body, as happens whenever the caller
        // relies on the controller's own default) resolves the untouched field to null, not an empty
        // string -- the field is still valid either way since it's `required: false`.
        $form = $this->createFormFactory()->create(RebuildForm::class);

        $form->submit([
            RebuildForm::FIELD_ALIAS_NAME => 'myshop_de_page',
        ]);

        $this->assertTrue($form->isValid());
        $this->assertNull($form->getData()[RebuildForm::FIELD_REDIRECT_TO]);
    }

    public function testSubmittingWithoutAnAliasNameIsInvalid(): void
    {
        $form = $this->createFormFactory()->create(RebuildForm::class);

        $form->submit([
            RebuildForm::FIELD_ALIAS_NAME => '',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertGreaterThan(0, $form->getErrors(true)->count());
    }

    protected function createFormFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
