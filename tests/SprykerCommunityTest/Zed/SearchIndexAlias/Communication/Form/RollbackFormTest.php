<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunityTest\Zed\SearchIndexAlias\Communication\Form;

use Codeception\Test\Unit;
use SprykerCommunity\Zed\SearchIndexAlias\Communication\Form\RollbackForm;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

/**
 * Representative of the shared two-required-hidden-field shape this package's other action forms
 * (AliasScopeForm, AbortForm, DeleteIndexForm) also use -- covering the pattern once here rather than
 * duplicating a near-identical test per form.
 *
 * Auto-generated group annotations
 *
 * @group SprykerCommunityTest
 * @group Zed
 * @group SearchIndexAlias
 * @group Communication
 * @group Form
 * @group RollbackFormTest
 * Add your own group annotations below this line
 * @group Portable
 */
class RollbackFormTest extends Unit
{
    public function testSubmittingWithBothRequiredFieldsIsValid(): void
    {
        $form = $this->createFormFactory()->create(RollbackForm::class);

        $form->submit([
            RollbackForm::FIELD_ALIAS_NAME => 'myshop_de_page',
            RollbackForm::FIELD_TARGET_INDEX_NAME => 'myshop_de_page_20260101_120000',
        ]);

        $this->assertTrue($form->isValid());
        $this->assertSame('myshop_de_page', $form->getData()[RollbackForm::FIELD_ALIAS_NAME]);
        $this->assertSame('myshop_de_page_20260101_120000', $form->getData()[RollbackForm::FIELD_TARGET_INDEX_NAME]);
    }

    public function testSubmittingWithoutAnAliasNameIsInvalid(): void
    {
        $form = $this->createFormFactory()->create(RollbackForm::class);

        $form->submit([
            RollbackForm::FIELD_ALIAS_NAME => '',
            RollbackForm::FIELD_TARGET_INDEX_NAME => 'myshop_de_page_20260101_120000',
        ]);

        $this->assertFalse($form->isValid());
    }

    public function testSubmittingWithoutATargetIndexNameIsInvalid(): void
    {
        $form = $this->createFormFactory()->create(RollbackForm::class);

        $form->submit([
            RollbackForm::FIELD_ALIAS_NAME => 'myshop_de_page',
            RollbackForm::FIELD_TARGET_INDEX_NAME => '',
        ]);

        $this->assertFalse($form->isValid());
    }

    public function testRedirectToFieldIsOptional(): void
    {
        $form = $this->createFormFactory()->create(RollbackForm::class);

        $form->submit([
            RollbackForm::FIELD_ALIAS_NAME => 'myshop_de_page',
            RollbackForm::FIELD_TARGET_INDEX_NAME => 'myshop_de_page_20260101_120000',
        ]);

        $this->assertTrue($form->isValid());
    }

    protected function createFormFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
