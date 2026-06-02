<?php

declare(strict_types=1);

namespace Opus125\GdprBundle\Subject;

use Opus125\GdprBundle\Registry\PersonalDataRegistry;

/**
 * Computes the inverse of {@see SubjectResolver}: the association routes from an
 * entity class *down to* the data-subject classes it can belong to.
 *
 * Where the resolver walks live objects forward to find a subject, this walks the
 * declared `#[SubjectLink]` graph at the class level to find every path, so the
 * access/erasure services can turn "subject S" into a query over each class that
 * references S — multi-level joins included. Cycles in the declaration graph are
 * broken with a visited-class set, and results are cached per class.
 */
final class SubjectPathResolver
{
    /**
     * @var array<class-string, list<SubjectPath>>
     */
    private array $cache = [];

    public function __construct(
        private readonly PersonalDataRegistry $registry,
    ) {
    }

    /**
     * Every route from $class to a data subject. A class that is itself a subject
     * yields one zero-length path to itself.
     *
     * @param class-string $class
     *
     * @return list<SubjectPath>
     */
    public function pathsFrom(string $class): array
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        if ($this->registry->isDataSubject($class)) {
            return $this->cache[$class] = [new SubjectPath($class, [])];
        }

        $paths = [];
        $this->walk($class, [], [$class => true], $paths);

        return $this->cache[$class] = $paths;
    }

    /**
     * Routes from $class that reach the specific subject class $subjectClass.
     *
     * @param class-string $class
     * @param class-string $subjectClass
     *
     * @return list<SubjectPath>
     */
    public function pathsTo(string $class, string $subjectClass): array
    {
        return array_values(array_filter(
            $this->pathsFrom($class),
            static fn (SubjectPath $p): bool => $p->subjectClass === $subjectClass,
        ));
    }

    /**
     * @param class-string             $class
     * @param list<string>             $prefix  properties accumulated so far
     * @param array<class-string,true> $visited classes on the current branch
     * @param list<SubjectPath>        $paths   accumulator
     */
    private function walk(string $class, array $prefix, array $visited, array &$paths): void
    {
        foreach ($this->registry->links($class) as $link) {
            /** @var class-string $target */
            $target = $link->target;

            if (isset($visited[$target])) {
                continue; // cycle in the declaration graph.
            }

            $path = [...$prefix, $link->property];

            if ($this->registry->isDataSubject($target)) {
                $paths[] = new SubjectPath($target, $path);

                continue;
            }

            $this->walk($target, $path, [...$visited, $target => true], $paths);
        }
    }
}
