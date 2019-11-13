<?php declare(strict_types=1);

namespace Symplify\MonorepoBuilder\Package;

use Symplify\MonorepoBuilder\ArraySorter;
use Symplify\MonorepoBuilder\Composer\Section;
use Symplify\MonorepoBuilder\Configuration\MergedPackagesCollector;
use Symplify\MonorepoBuilder\FileSystem\JsonFileManager;
use Symplify\PackageBuilder\Yaml\ParametersMerger;
use Symplify\SmartFileSystem\SmartFileInfo;

final class PackageComposerJsonMerger
{
    /**
     * @var string[]
     */
    private $mergeSections = [];

    /**
     * @var string[]
     */
    private $sectionsWithPath = ['classmap', 'files', 'exclude-from-classmap', 'psr-4', 'psr-0'];

    /**
     * @var ParametersMerger
     */
    private $parametersMerger;

    /**
     * @var MergedPackagesCollector
     */
    private $mergedPackagesCollector;

    /**
     * @var JsonFileManager
     */
    private $jsonFileManager;

    /**
     * @var ArraySorter
     */
    private $arraySorter;

    /**
     * @param string[] $mergeSections
     */
    public function __construct(
        ParametersMerger $parametersMerger,
        MergedPackagesCollector $mergedPackagesCollector,
        JsonFileManager $jsonFileManager,
        ArraySorter $arraySorter,
        array $mergeSections
    ) {
        $this->parametersMerger = $parametersMerger;
        $this->mergedPackagesCollector = $mergedPackagesCollector;
        $this->jsonFileManager = $jsonFileManager;
        $this->mergeSections = $mergeSections;
        $this->arraySorter = $arraySorter;
    }

    /**
     * @param SmartFileInfo[] $composerPackageFileInfos
     * @return string[]
     */
    public function mergeFileInfos(array $composerPackageFileInfos): array
    {
        $merged = [];

        foreach ($composerPackageFileInfos as $packageFile) {
            $packageComposerJson = $this->jsonFileManager->loadFromFileInfo($packageFile);

            if (isset($packageComposerJson['name'])) {
                $this->mergedPackagesCollector->addPackage($packageComposerJson['name']);
            }

            foreach ($this->mergeSections as $mergeSection) {
                if (! isset($packageComposerJson[$mergeSection])) {
                    continue;
                }

                $packageComposerJson = $this->prepareAutoloadPaths(
                    $mergeSection,
                    $packageComposerJson,
                    $packageFile
                );

                $merged = $this->mergeSection($packageComposerJson, $mergeSection, $merged);
            }
        }

        return $this->filterOutDuplicatesRequireAndRequireDev($merged);
    }

    /**
     * Class map path needs to be prefixed before merge, otherwise will override one another
     * @see https://github.com/Symplify/Symplify/issues/1333
     * @param mixed[] $packageComposerJson
     * @return mixed[]
     */
    private function prepareAutoloadPaths(
        string $mergeSection,
        array $packageComposerJson,
        SmartFileInfo $packageFile
    ): array {
        if (! in_array($mergeSection, ['autoload', 'autoload-dev'], true)) {
            return $packageComposerJson;
        }

        foreach ($this->sectionsWithPath as $sectionWithPath) {
            if (! isset($packageComposerJson[$mergeSection][$sectionWithPath])) {
                continue;
            }

            $packageComposerJson[$mergeSection][$sectionWithPath] = $this->relativizePath(
                $packageComposerJson[$mergeSection][$sectionWithPath],
                $packageFile
            );
        }

        return $packageComposerJson;
    }

    /**
     * @param mixed[] $packageComposerJson
     * @param mixed[] $merged
     * @return mixed[]
     */
    private function mergeSection(array $packageComposerJson, string $section, array $merged): array
    {
        // array sections
        if (is_array($packageComposerJson[$section])) {
            $merged[$section] = $this->parametersMerger->mergeWithCombine(
                $merged[$section] ?? [],
                $packageComposerJson[$section]
            );

            $merged[$section] = $this->arraySorter->recursiveSort($merged[$section]);

            // uniquate special cases, ref https://github.com/Symplify/Symplify/issues/1197
            if ($section === 'repositories') {
                $merged[$section] = array_unique($merged[$section], SORT_REGULAR);
                // remove keys
                $merged[$section] = array_values($merged[$section]);
            }

            return $merged;
        }

        // key: value sections, like "minimum-stability: dev"
        $merged[$section] = $packageComposerJson[$section];

        return $merged;
    }

    /**
     * @param mixed[] $composerJson
     * @return mixed[]
     */
    private function filterOutDuplicatesRequireAndRequireDev(array $composerJson): array
    {
        if (! isset($composerJson[Section::REQUIRE]) || ! isset($composerJson[Section::REQUIRE_DEV])) {
            return $composerJson;
        }

        $duplicatedPackages = array_intersect(
            array_keys($composerJson[Section::REQUIRE]),
            array_keys($composerJson[Section::REQUIRE_DEV])
        );

        foreach (array_keys($composerJson[Section::REQUIRE_DEV]) as $package) {
            if (in_array($package, $duplicatedPackages, true)) {
                unset($composerJson[Section::REQUIRE_DEV][$package]);
            }
        }

        // remove empty "require-dev"
        if (count($composerJson[Section::REQUIRE_DEV]) === 0) {
            unset($composerJson[Section::REQUIRE_DEV]);
        }

        return $composerJson;
    }

    /**
     * @param mixed[] $classmap
     * @return mixed[]
     */
    private function relativizePath(array $classmap, SmartFileInfo $packageFileInfo): array
    {
        $packageRelativeDirectory = dirname($packageFileInfo->getRelativeFilePathFromDirectory(getcwd()));
        foreach ($classmap as $key => $value) {
            if (is_array($value)) {
                $classmap[$key] = array_map(function ($path) use ($packageRelativeDirectory): string {
                    return $packageRelativeDirectory . '/' . ltrim($path, '/');
                }, $value);
            } else {
                $classmap[$key] = $packageRelativeDirectory . '/' . ltrim($value, '/');
            }
        }

        return $classmap;
    }
}
