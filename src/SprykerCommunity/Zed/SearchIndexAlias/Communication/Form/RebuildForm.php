<?php

/**
 * This file is part of the spryker-community/search-index-alias package.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace SprykerCommunity\Zed\SearchIndexAlias\Communication\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The Overview page's Rebuild action bar button -- like AliasScopeForm but with an extra opt-in checkbox
 * to disable the target index's refresh interval/replicas for the duration of the bulk load (see
 * IndexCloner::disableRefreshAndReplicasForBulkLoad()).
 */
class RebuildForm extends AbstractType
{
    /**
     * @var string
     */
    public const FIELD_ALIAS_NAME = 'aliasName';

    /**
     * @var string
     */
    public const FIELD_OPTIMIZE_FOR_BULK_LOAD = 'optimizeForBulkLoad';

    /**
     * @var string
     */
    public const FIELD_REDIRECT_TO = 'redirectTo';

    /**
     * @param \Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add(static::FIELD_ALIAS_NAME, HiddenType::class, [
            'constraints' => [new NotBlank()],
        ]);
        $builder->add(static::FIELD_OPTIMIZE_FOR_BULK_LOAD, CheckboxType::class, [
            'required' => false,
            'label' => 'Optimize for large bulk load',
        ]);
        $builder->add(static::FIELD_REDIRECT_TO, HiddenType::class, [
            'required' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'search_index_alias_rebuild';
    }
}
