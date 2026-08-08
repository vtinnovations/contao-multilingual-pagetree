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

namespace Vtinnovations\ContaoMultilingualPagetree\Review;

use Contao\StringUtil;

/**
 * Renders review status for the backend.
 *
 * Status is never communicated by colour alone: every badge carries a symbol
 * and a translated text label, and the panel repeats the state in words.
 * All dynamic values are escaped; source previews are plain text produced by
 * {@see SourceValuePreview} and are escaped again here.
 */
final class ReviewBadgeRenderer
{
    private const SYMBOLS = [
        'up_to_date' => '✓',
        'needs_review' => '!',
        'unreviewed' => '•',
        'source_missing' => '⚠',
    ];

    private const COLOURS = [
        'up_to_date' => ['#dcfce7', '#15803d'],
        'needs_review' => ['#fef3c7', '#92400e'],
        'unreviewed' => ['#e5e7eb', '#374151'],
        'source_missing' => ['#fef2f2', '#991b1b'],
    ];

    /**
     * @param array<string, string> $labels
     */
    public function badge(ReviewStatus $status, array $labels): string
    {
        $label = $this->label($status, $labels);
        [$background, $colour] = self::COLOURS[$status->value] ?? self::COLOURS[ReviewStatus::Unreviewed->value];
        $symbol = self::SYMBOLS[$status->value] ?? '•';

        return sprintf(
            '<span class="contao-multilingual-pagetree-review contao-multilingual-pagetree-review--%s" style="background:%s;color:%s;'
            .'font-size:10px;padding:2px 6px;border-radius:3px;margin-left:6px;font-weight:600;vertical-align:middle;" title="%s">'
            .'<span aria-hidden="true">%s</span> %s</span>',
            StringUtil::specialchars($status->value),
            $background,
            $colour,
            StringUtil::specialchars($label),
            StringUtil::specialchars($symbol),
            StringUtil::specialchars($label),
        );
    }

    /**
     * The information panel shown inside the translation form.
     *
     * @param array<string, string> $labels
     */
    public function panel(ReviewState $state, array $labels, ?string $reviewUrl = null, string $reviewerName = '', string $requestToken = ''): string
    {
        $html = '<div class="widget contao-multilingual-pagetree-review-panel" style="margin-bottom:12px;">';
        $html .= '<div>'.$this->badge($state->status, $labels).'</div>';

        if ($state->reviewedAt > 0) {
            $html .= '<p style="margin:6px 0 0;">'
                .StringUtil::specialchars($labels['reviewedAt'] ?? '').': '
                .StringUtil::specialchars(date('Y-m-d H:i', $state->reviewedAt));

            if ('' !== $reviewerName) {
                $html .= ' – '.StringUtil::specialchars($labels['reviewedBy'] ?? '').': '
                    .StringUtil::specialchars($reviewerName);
            }

            $html .= '</p>';
        }

        if (ReviewStatus::SourceMissing === $state->status) {
            $html .= '<p style="margin:6px 0 0;"><strong>'
                .StringUtil::specialchars($labels['sourceMissing'] ?? '').'</strong></p>';
        }

        if ([] !== $state->changedFields) {
            $html .= '<p style="margin:8px 0 4px;"><strong>'
                .StringUtil::specialchars($labels['changedFields'] ?? '').'</strong></p><ul style="margin:0 0 0 18px;">';

            foreach ($state->changedFields as $field) {
                $html .= '<li><strong>'.StringUtil::specialchars($this->fieldLabel($field->field, $labels)).'</strong>';

                if ('' !== $field->reviewedPreview || '' !== $field->currentPreview) {
                    $html .= '<br><span style="color:#666;">'
                        .StringUtil::specialchars($labels['reviewedValue'] ?? '').': '
                        .StringUtil::specialchars($field->reviewedPreview).'</span>';
                    $html .= '<br><span>'
                        .StringUtil::specialchars($labels['currentValue'] ?? '').': '
                        .StringUtil::specialchars($field->currentPreview).'</span>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        if (null !== $reviewUrl && $state->isReviewable()) {
            // The panel already lives inside Contao's native edit form. A
            // nested form breaks ownership of the native save buttons, so the
            // review action submits that existing form to its own endpoint.
            $html .= $this->actionButton($reviewUrl, (string) ($labels['markReviewed'] ?? ''));
        }

        return $html.'</div>';
    }

    /**
     * Renders the review action as a POST form.
     *
     * The action changes stored data, so it must never be reachable through a
     * link, a prefetch or an image request. The Contao request token is
     * submitted with the form and validated server side.
     */
    public function actionForm(string $actionUrl, string $requestToken, string $label): string
    {
        return sprintf(
            '<form method="post" action="%s" class="contao-multilingual-pagetree-review-action" style="margin:10px 0 0;">'
            .'<input type="hidden" name="REQUEST_TOKEN" value="%s">'
            .'<button type="submit" class="tl_submit">%s</button></form>',
            StringUtil::specialchars($actionUrl),
            StringUtil::specialchars($requestToken),
            StringUtil::specialchars($label),
        );
    }

    /** A review action rendered inside Contao's existing record-edit form. */
    public function actionButton(string $actionUrl, string $label): string
    {
        return sprintf(
            '<button type="submit" formaction="%s" formmethod="post" formnovalidate class="tl_submit contao-multilingual-pagetree-review-action" style="margin:10px 0 0;">%s</button>',
            StringUtil::specialchars($actionUrl),
            StringUtil::specialchars($label),
        );
    }

    /**
     * @param array<string, string> $labels
     */
    private function label(ReviewStatus $status, array $labels): string
    {
        $label = $labels[$status->value] ?? null;

        return is_string($label) && '' !== $label ? $label : $status->value;
    }

    /**
     * @param array<string, string> $labels
     */
    private function fieldLabel(string $field, array $labels): string
    {
        $label = $labels['field_'.$field] ?? null;

        return is_string($label) && '' !== $label ? $label : $field;
    }
}
