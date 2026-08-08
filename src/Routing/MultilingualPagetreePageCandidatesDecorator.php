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

namespace Vtinnovations\ContaoMultilingualPagetree\Routing;

use Doctrine\DBAL\Connection;
use Symfony\Cmf\Component\Routing\Candidates\CandidatesInterface;
use Symfony\Component\HttpFoundation\Request;

class MultilingualPagetreePageCandidatesDecorator implements CandidatesInterface
{
    private CandidatesInterface $inner;
    private Connection $connection;

    public function __construct(CandidatesInterface $inner, Connection $connection)
    {
        $this->inner = $inner;
        $this->connection = $connection;
    }

    public function getCandidates(Request $request): array
    {
        $candidates = $this->inner->getCandidates($request);
        $additional = [];

        foreach ($candidates as $candidate) {
            $candidateStr = (string) $candidate;
            if ($candidateStr === '' || $candidateStr === '/' || preg_match('/^[1-9]\d*$/', $candidateStr)) {
                continue;
            }

            try {
                $qb = $this->connection->createQueryBuilder();
                $result = $qb->select('t.pid', 'p.alias')
                    ->from('tl_page_translation', 't')
                    ->innerJoin('t', 'tl_page', 'p', 't.pid = p.id')
                    ->where('t.alias = :alias OR t.title = :alias')
                    ->setParameter('alias', $candidateStr)
                    ->executeQuery()
                    ->fetchAllAssociative();

                foreach ($result as $row) {
                    if (!empty($row['pid'])) {
                        $additional[] = (string) $row['pid'];
                    }
                    if (!empty($row['alias'])) {
                        $additional[] = (string) $row['alias'];
                    }
                }
            } catch (\Throwable $e) {
                // Table might not exist yet during initial installation/migration
            }
        }

        if (!empty($additional)) {
            return array_values(array_unique([...$candidates, ...$additional]));
        }

        return $candidates;
    }

    public function isCandidate($name): bool
    {
        $name = (string) $name;

        return $this->inner->isCandidate($name) || str_starts_with($name, 'tl_page.');
    }

    public function restrictQuery($queryBuilder): void
    {
        $this->inner->restrictQuery($queryBuilder);
    }
}
