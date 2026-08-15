<?php

declare(strict_types=1);

namespace Unknowncoder\Teleport\Scanner;

use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Function_;

class FunctionParser
{
    protected Parser $parser;

    public function __construct()
    {
        // Use the factory to create a parser instance suitable for the host PHP version
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse a PHP file and return an array of fully qualified names for all functions defined in it.
     *
     * @param string $filePath Absolute path to the PHP file.
     * @return array List of fully qualified function names (e.g. ['App\Helpers\slugify']).
     */
    public function parseFunctions(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            return [];
        }

        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return [];
            }
            return $this->findFunctions($ast);
        } catch (\Throwable $e) {
            // Silently ignore syntactically invalid files or parsing exceptions
            return [];
        }
    }

    /**
     * Recursively traverse AST nodes to discover defined functions and namespaces.
     *
     * @param array $nodes AST nodes.
     * @param string $currentNamespace The active namespace context.
     * @return array Discoveries.
     */
    protected function findFunctions(array $nodes, string $currentNamespace = ''): array
    {
        $functions = [];

        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $nsName = $node->name !== null ? $node->name->toString() : '';
                $functions = array_merge($functions, $this->findFunctions($node->stmts, $nsName));
            } elseif ($node instanceof Function_) {
                $funcName = $node->name->toString();
                $fqn = $currentNamespace !== '' ? $currentNamespace . '\\' . $funcName : $funcName;
                $functions[] = $fqn;
            } elseif (property_exists($node, 'stmts') && is_array($node->stmts)) {
                $functions = array_merge($functions, $this->findFunctions($node->stmts, $currentNamespace));
            }
        }

        return $functions;
    }
}
