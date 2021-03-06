<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\PhpDocNodeFactory\Doctrine\Class_;

use Nette\Utils\Strings;
use Rector\BetterPhpDocParser\PhpDocNode\Doctrine\Class_\IndexTagValueNode;

final class IndexPhpDocNodeFactory
{
    /**
     * @var string
     */
    private const INDEX_PATTERN = '#(?<tag>@(ORM\\\\)?Index)\((?<content>.*?)\),?#si';

    /**
     * @param mixed[]|null $indexes
     * @return IndexTagValueNode[]
     */
    public function createIndexTagValueNodes(?array $indexes, string $annotationContent): array
    {
        if ($indexes === null) {
            return [];
        }

        $indexContents = Strings::matchAll($annotationContent, self::INDEX_PATTERN);

        $indexTagValueNodes = [];
        foreach ($indexes as $key => $index) {
            $currentContent = $indexContents[$key];

            $indexTagValueNodes[] = new IndexTagValueNode($index, $currentContent['content'], $currentContent['tag']);
        }

        return $indexTagValueNodes;
    }
}
