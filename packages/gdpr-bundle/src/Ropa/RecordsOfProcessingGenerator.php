<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Ropa;

use Opus125\GdprBundle\Registry\EntityClassLocator;
use Opus125\GdprBundle\Registry\PersonalDataRegistry;

/**
 * Generates a records-of-processing scaffold (GDPR Art. 30) from the registry.
 *
 * Because every personal-data field declares its `category`, `purpose` and
 * `basis`, the processing activities can be *read out of the code* instead of
 * maintained in a table that drifts. Activities are grouped by purpose, each
 * listing its categories, legal bases and contributing fields.
 *
 * **Honest limit:** this covers only the *code-visible* processing — fields on
 * mapped entities. Processing outside the code (manual workflows, paper, third
 * parties) must be added by the controller. It is a scaffold, not a complete
 * register.
 */
final class RecordsOfProcessingGenerator
{
    public const string SCOPE_NOTE = 'Generated from code-visible personal-data fields only; processing outside the codebase (manual, paper, third parties) must be added separately.';

    public function __construct(
        private readonly PersonalDataRegistry $registry,
        private readonly EntityClassLocator $locator,
    ) {
    }

    /**
     * @return array{
     *     scope_note: string,
     *     activities: list<array{
     *         purpose: string|null,
     *         categories: list<string>,
     *         legal_bases: list<string>,
     *         has_sensitive: bool,
     *         fields: list<array{entity: class-string, property: string, category: string, basis: string|null, sensitive: bool}>
     *     }>
     * }
     */
    public function generate(): array
    {
        /** @var array<string, array{purpose: string|null, categories: array<string, true>, legal_bases: array<string, true>, has_sensitive: bool, fields: list<array{entity: class-string, property: string, category: string, basis: string|null, sensitive: bool}>}> $byPurpose */
        $byPurpose = [];

        foreach ($this->locator->relevantClasses() as $class) {
            foreach ($this->registry->personalData($class) as $field) {
                $purposeKey = $field->purpose ?? '';

                $byPurpose[$purposeKey] ??= [
                    'purpose' => $field->purpose,
                    'categories' => [],
                    'legal_bases' => [],
                    'has_sensitive' => false,
                    'fields' => [],
                ];

                $byPurpose[$purposeKey]['categories'][$field->category] = true;
                if (null !== $field->basis) {
                    $byPurpose[$purposeKey]['legal_bases'][$field->basis] = true;
                }
                $byPurpose[$purposeKey]['has_sensitive'] = $byPurpose[$purposeKey]['has_sensitive'] || $field->sensitive;
                $byPurpose[$purposeKey]['fields'][] = [
                    'entity' => $class,
                    'property' => $field->property,
                    'category' => $field->category,
                    'basis' => $field->basis,
                    'sensitive' => $field->sensitive,
                ];
            }
        }

        ksort($byPurpose);

        $activities = [];
        foreach ($byPurpose as $activity) {
            $categories = array_keys($activity['categories']);
            sort($categories);
            $bases = array_keys($activity['legal_bases']);
            sort($bases);

            $activities[] = [
                'purpose' => $activity['purpose'],
                'categories' => $categories,
                'legal_bases' => $bases,
                'has_sensitive' => $activity['has_sensitive'],
                'fields' => $activity['fields'],
            ];
        }

        return ['scope_note' => self::SCOPE_NOTE, 'activities' => $activities];
    }
}
