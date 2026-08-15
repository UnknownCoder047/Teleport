<?php

declare(strict_types=1);

namespace Unknowncoder\Teleport\Dispatcher;

/**
 * Class Warp
 *
 * Intercepts static function calls routed through Warp::<Alias>() or Warp::call()
 * and lazily loads the corresponding PHP file from the stealth function map.
 *
 * @package Unknowncoder\Teleport\Dispatcher
 */
class Warp
{
    /**
     * Stealth function lookup map: Alias => FilePath or Array definition.
     *
     * @var array<string, string|array{file: string, fqn?: string}>
     */
    protected static array $map = [];

    /**
     * Index mapping Alias => Shard Relative File Path.
     *
     * @var array<string, string>
     */
    protected static array $shardsIndex = [];

    /**
     * Base directory where index.php and shards/ are stored.
     *
     * @var string|null
     */
    protected static ?string $baseDir = null;

    /**
     * Loaded shard file paths to prevent redundant requires.
     *
     * @var array<string, bool>
     */
    protected static array $loadedShards = [];

    /**
     * Internal cache for resolved function FQNs.
     *
     * @var array<string, string>
     */
    protected static array $resolvedFqns = [];

    /**
     * Internal cache for user defined functions to avoid repeated calls.
     *
     * @var array<string>|null
     */
    protected static ?array $userFunctionsCache = null;

    /**
     * Set the stealth function map or index.
     *
     * @param array $map Map or Index array (may contain 'shards' array).
     * @param string|null $baseDir Directory path containing index.php and shards/.
     * @return void
     */
    public static function setMap(array $map, ?string $baseDir = null): void
    {
        self::$baseDir = $baseDir;
        self::$loadedShards = [];
        self::$resolvedFqns = [];

        if (isset($map['shards']) && is_array($map['shards'])) {
            self::$shardsIndex = $map['shards'];
            self::$map = [];
        } else {
            self::$shardsIndex = [];
            self::$map = $map;
        }
    }

    /**
     * Get the active stealth function map.
     *
     * @return array
     */
    public static function getMap(): array
    {
        return self::$map;
    }

    /**
     * Get the active shards index map.
     *
     * @return array<string, string>
     */
    public static function getShardsIndex(): array
    {
        return self::$shardsIndex;
    }

    /**
     * Call a namespaced function directly by its FQN (e.g., Warp::call('App\Helpers\slugify', $text)).
     *
     * @param string $fqn Fully qualified function name.
     * @param mixed ...$arguments Arguments passed to the target function.
     * @return mixed Result of function execution.
     * @throws \BadFunctionCallException If function cannot be found.
     */
    public static function call(string $fqn, mixed ...$arguments): mixed
    {
        $normalizedFqn = ltrim($fqn, '\\');
        $alias = str_replace('\\', '_', $normalizedFqn);

        return self::__callStatic($alias, $arguments);
    }

    /**
     * Intercept and delegate static calls to stealth functions.
     *
     * @param string $name Method alias called on Warp.
     * @param array $arguments Arguments passed to the target function.
     * @return mixed Result of function execution.
     * @throws \BadFunctionCallException
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        // 1. If alias is in sharded index, load the specific shard file lazily
        if (isset(self::$shardsIndex[$name]) && !isset(self::$map[$name])) {
            $shardFile = self::$shardsIndex[$name];
            $fullShardPath = self::$baseDir ? rtrim(self::$baseDir, '/\\') . '/' . $shardFile : $shardFile;

            if (!isset(self::$loadedShards[$fullShardPath])) {
                self::$loadedShards[$fullShardPath] = true;
                if (file_exists($fullShardPath)) {
                    $shardData = require $fullShardPath;
                    if (is_array($shardData)) {
                        foreach ($shardData as $alias => $definition) {
                            self::$map[$alias] = $definition;
                        }
                    }
                }
            }
        }

        // 2. Require target file if present in stealth map
        if (isset(self::$map[$name])) {
            $mapTarget = self::$map[$name];
            $filePath = is_array($mapTarget) ? ($mapTarget['file'] ?? '') : $mapTarget;
            $mappedFqn = is_array($mapTarget) ? ($mapTarget['fqn'] ?? null) : null;

            if ($filePath !== '' && file_exists($filePath)) {
                require_once $filePath;
            }

            if ($mappedFqn !== null) {
                self::$resolvedFqns[$name] = $mappedFqn;
            }
        }

        // 3. Return fast if FQN was resolved and function exists
        if (isset(self::$resolvedFqns[$name])) {
            $resolved = self::$resolvedFqns[$name];
            if (function_exists($resolved)) {
                return $resolved(...$arguments);
            }
        }

        // 4. Try standard translation: App_Helpers_slugify -> App\Helpers\slugify
        $translatedName = str_replace('_', '\\', $name);
        if (function_exists($translatedName)) {
            self::$resolvedFqns[$name] = $translatedName;
            return $translatedName(...$arguments);
        }

        // 5. Fallback resolution for functions containing underscores
        if (self::$userFunctionsCache === null) {
            self::$userFunctionsCache = get_defined_functions()['user'] ?? [];
        }
        $userFunctions = self::$userFunctionsCache;
        foreach ($userFunctions as $func) {
            if (str_replace('\\', '_', $func) === $name || strcasecmp(str_replace('\\', '_', $func), $name) === 0) {
                self::$resolvedFqns[$name] = $func;
                return $func(...$arguments);
            }
        }

        // 6. Function not found: Calculate smart typo suggestions
        $suggestion = self::findClosestAliasSuggestion($name);
        $message = "Teleport Warp: Function '{$translatedName}' not found.";
        if ($suggestion !== null) {
            $message .= " Did you mean 'Warp::{$suggestion}()'?";
        }

        throw new \BadFunctionCallException($message);
    }

    /**
     * Find the closest matching alias using Levenshtein distance for typo suggestions.
     *
     * @param string $searchAlias
     * @return string|null
     */
    protected static function findClosestAliasSuggestion(string $searchAlias): ?string
    {
        $allAliases = array_unique(array_merge(
            array_keys(self::$shardsIndex),
            array_keys(self::$map),
            array_keys(self::$resolvedFqns)
        ));

        if (empty($allAliases)) {
            return null;
        }

        $bestMatch = null;
        $shortestDistance = -1;

        foreach ($allAliases as $alias) {
            $lev = levenshtein($searchAlias, $alias);
            if ($lev <= 4 && ($shortestDistance === -1 || $lev < $shortestDistance)) {
                $shortestDistance = $lev;
                $bestMatch = $alias;
            }
        }

        return $bestMatch;
    }

    /**
     * Reset internal cache state.
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$resolvedFqns = [];
        self::$loadedShards = [];
        self::$shardsIndex = [];
        self::$map = [];
        self::$baseDir = null;
        self::$userFunctionsCache = null;
    }
}
