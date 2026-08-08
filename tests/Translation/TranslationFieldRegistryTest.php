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

namespace Vtinnovations\ContaoMultilingualPagetree\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistration;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistry;

final class TranslationFieldRegistryTest extends TestCase
{
    public function testEverySupportedEntityHasAnExplicitDefaultDenyPolicy(): void
    {
        $registry = new TranslationFieldRegistry();
        foreach (['page', 'article', 'content', 'news', 'event', 'faq'] as $entity) {
            $this->assertNotSame('', $registry->getPolicy($entity)->sourceTable);
        }
        $this->assertSame([], $registry->getPolicy('unknown')->translatableFields);
    }

    public function testCategoriesKeepStructurePublicationAndContentSeparate(): void
    {
        $page = (new TranslationFieldRegistry())->getPolicy('page');

        $this->assertArrayHasKey('title', $page->translatableFields);
        $this->assertArrayHasKey('alias', $page->translatableFields);
        $this->assertContains('type', $page->structuralFields);
        $this->assertContains('layout', $page->structuralFields);
        $this->assertContains('groups', $page->structuralFields);
        $this->assertContains('published', $page->independentFields);
        $this->assertNotContains('published', array_keys($page->translatableFields));
        $this->assertNotContains('pid', array_keys($page->translatableFields));
    }

    public function testEntityPoliciesProtectRelationsSchedulesAndAliases(): void
    {
        $registry = new TranslationFieldRegistry();
        $this->assertSame(['title'], $registry->fieldNames('tl_article_translation'));
        $this->assertContains('alias', $registry->fieldNames('tl_news_translation'));
        $this->assertContains('alias', $registry->fieldNames('tl_calendar_events_translation'));
        $this->assertContains('alias', $registry->fieldNames('tl_faq_translation'));
        foreach (['pid', 'author', 'startDate', 'repeatEach', 'sorting', 'customTpl'] as $denied) {
            $this->assertFalse($registry->isTranslatable('tl_news_translation', $denied));
            $this->assertFalse($registry->isTranslatable('tl_calendar_events_translation', $denied));
            $this->assertFalse($registry->isTranslatable('tl_faq_translation', $denied));
        }
    }

    public function testContentFieldsAreTypeSpecificAndStructuralFieldsStayDenied(): void
    {
        $registry = new TranslationFieldRegistry();

        $this->assertTrue($registry->isTranslatable('tl_content_translation', 'text', 'text'));
        $this->assertTrue($registry->isTranslatable('tl_content_translation', 'alt', 'text'));
        $this->assertFalse($registry->isTranslatable('tl_content_translation', 'text', 'image'));
        $this->assertTrue($registry->isTranslatable('tl_content_translation', 'alt', 'image'));
        $this->assertFalse($registry->isTranslatable('tl_content_translation', 'alt', 'unknown'));
        foreach (['type', 'CType', 'colPos', 'pid', 'singleSRC', 'customTpl'] as $field) {
            $this->assertFalse($registry->isTranslatable('tl_content_translation', $field, 'text'));
        }
    }

    public function testContributorsAreDeterministicAndCannotOverrideProtectedFields(): void
    {
        $late = new class implements TranslationFieldPolicyContributorInterface {
            public function registrations(): iterable
            {
                yield new TranslationFieldRegistration('tl_content', 'note', 'serialized_array', 'product_note');
                yield new TranslationFieldRegistration('tl_content', 'pid', 'string', 'product_note');
            }
        };
        $early = new class implements TranslationFieldPolicyContributorInterface {
            public function registrations(): iterable
            {
                yield new TranslationFieldRegistration('tl_content', 'note', 'string', 'product_note');
                yield new TranslationFieldRegistration('tl_content', 'bad field', 'string', 'product_note');
            }
        };

        $registry = new TranslationFieldRegistry([$late, $early]);
        $reversed = new TranslationFieldRegistry([$early, $late]);
        $this->assertContains($registry->type('tl_content_translation', 'note', 'product_note'), ['string', 'serialized_array']);
        $this->assertSame(
            $registry->type('tl_content_translation', 'note', 'product_note'),
            $reversed->type('tl_content_translation', 'note', 'product_note'),
        );
        $this->assertFalse($registry->isTranslatable('tl_content_translation', 'pid', 'product_note'));
        $this->assertFalse($registry->isTranslatable('tl_content_translation', 'bad field', 'product_note'));
    }
}
