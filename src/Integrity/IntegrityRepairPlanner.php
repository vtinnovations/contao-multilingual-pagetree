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

use Vtinnovations\ContaoMultilingualPagetree\Review\ReviewStatus;
use Vtinnovations\ContaoMultilingualPagetree\Review\TranslationReviewResolver;

/**
 * Turns issues into a repair plan.
 *
 * The planner is deliberately conservative: it never guesses a source relation,
 * never merges translations, never rewrites a meaningful alias and never deletes
 * source or default-language content. Anything ambiguous stays unresolved and
 * needs an editor decision.
 */
final class IntegrityRepairPlanner
{
    /**
     * @param list<string> $selectedFingerprints Only these issues are planned; empty means all repairable ones
     */
    public function plan(IntegrityReport $report, array $selectedFingerprints = [], bool $includeConfirmationRequired = true): IntegrityRepairPlan
    {
        $actions = [];
        $unresolved = [];

        foreach ($report->issues as $issue) {
            if ([] !== $selectedFingerprints && !in_array($issue->fingerprint(), $selectedFingerprints, true)) {
                continue;
            }

            if (!$issue->isRepairable()) {
                if (IntegrityIssue::REPAIR_MANUAL === $issue->repairability) {
                    $unresolved[] = $issue->code;
                }

                continue;
            }

            if ($issue->requiresConfirmation() && !$includeConfirmationRequired) {
                $unresolved[] = $issue->code;

                continue;
            }

            $action = $this->actionFor($issue);

            if (null === $action) {
                $unresolved[] = $issue->code;

                continue;
            }

            $actions[] = $action;
        }

        return IntegrityRepairPlan::create($report->scope, $actions, $unresolved);
    }

    /**
     * Rebuilds the plan from a fresh scan and rejects it when anything changed.
     */
    public function isStale(IntegrityRepairPlan $plan, IntegrityReport $freshReport): bool
    {
        $fingerprints = [];

        foreach ($freshReport->issues as $issue) {
            $fingerprints[$issue->fingerprint()] = true;
        }

        foreach ($plan->actions as $action) {
            if (!isset($fingerprints[$action->issueFingerprint])) {
                return true;
            }
        }

        $replanned = $this->plan($freshReport, array_map(
            static fn (IntegrityRepairAction $action): string => $action->issueFingerprint,
            $plan->actions,
        ));

        return $replanned->checksum !== $plan->checksum;
    }

    private function actionFor(IntegrityIssue $issue): ?IntegrityRepairAction
    {
        return match ($issue->code) {
            // Metadata is normalised in place; translated values are untouched.
            IntegrityIssueCode::INVALID_FIELD_STATES => IntegrityRepairAction::fromIssue(
                $issue,
                IntegrityRepairAction::TYPE_NORMALISE,
                ['fieldStates' => (string) ($issue->context['normalised'] ?? '{}')],
                false,
            ),
            IntegrityIssueCode::INVALID_REVIEW_METADATA => IntegrityRepairAction::fromIssue(
                $issue,
                IntegrityRepairAction::TYPE_NORMALISE,
                [
                    TranslationReviewResolver::FIELD_STATUS => ReviewStatus::Unreviewed->value,
                    TranslationReviewResolver::FIELD_REVISION => '',
                ],
                false,
            ),
            // A translation without a source cannot function; it carries no
            // information that could be recovered without its source record.
            IntegrityIssueCode::MISSING_SOURCE,
            IntegrityIssueCode::ORPHANED_CONNECTED_TRANSLATION => IntegrityRepairAction::fromIssue(
                $issue,
                IntegrityRepairAction::TYPE_DELETE,
            ),
            // Impossible relations are quarantined rather than deleted: the data
            // is kept for diagnosis and an editor decides.
            IntegrityIssueCode::CROSS_SITE_RELATION,
            IntegrityIssueCode::CROSS_LANGUAGE_RELATION,
            IntegrityIssueCode::SELF_REFERENTIAL_SOURCE,
            IntegrityIssueCode::TRANSLATION_SOURCE_RELATION,
            IntegrityIssueCode::INVALID_FREE_PARENT,
            IntegrityIssueCode::ORPHANED_FREE_CONTENT,
            IntegrityIssueCode::INVALID_ALIAS => IntegrityRepairAction::fromIssue(
                $issue,
                IntegrityRepairAction::TYPE_QUARANTINE,
            ),
            // Only a provably redundant duplicate may be removed automatically.
            IntegrityIssueCode::DUPLICATE_TRANSLATION => true === ($issue->context['redundant'] ?? false)
                ? IntegrityRepairAction::fromIssue($issue, IntegrityRepairAction::TYPE_DELETE)
                : null,
            default => null,
        };
    }
}
