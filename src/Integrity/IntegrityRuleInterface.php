<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Integrity;

/**
 * One deterministic, read-only integrity rule.
 *
 * Rules must never modify data. Third-party bundles may add rules by
 * implementing this interface; the bundle tags them automatically and isolates
 * their failures so one broken rule never aborts a scan.
 */
interface IntegrityRuleInterface
{
    /**
     * Stable rule name; also decides the deterministic execution order together
     * with the priority.
     */
    public function getName(): string;

    /**
     * Higher priorities run first. Rules with an equal priority run in
     * alphabetical order of their name.
     */
    public function getPriority(): int;

    /**
     * Entity types this rule inspects, e.g. "page", "content", "language".
     *
     * @return list<string>
     */
    public function getSupportedEntities(): array;

    /**
     * Whether the rule can propose repair actions at all.
     */
    public function isRepairable(): bool;

    /**
     * Scans within the given scope and returns findings without writing
     * anything.
     */
    public function scan(IntegrityScope $scope, IntegrityDataSourceInterface $data): IntegrityIssueCollection;
}
