<?php

namespace Nuwave\Lighthouse\Schema\Directives;

use Closure;
use Exception;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DirectiveNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\ObjectFieldNode;
use GraphQL\Language\AST\ObjectValueNode;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\TypeSystemDefinitionNode;
use Nuwave\Lighthouse\Support\Contracts\Directive;
use Nuwave\Lighthouse\Support\Exceptions\DirectiveException;
use Nuwave\Lighthouse\Schema\Directives\Fields\NamespaceDirective;

abstract class BaseDirective implements Directive
{
    /**
     * The node the directive is defined on.
     *
     * @var TypeSystemDefinitionNode
     */
    protected $definitionNode;

    /**
     * The hydrate function is called when retrieving a directive from the directive registry.
     *
     * @todo Make this type annotation a hard requirement as soon as the underlying implementation is fixed
     *
     * @param TypeSystemDefinitionNode $definitionNode
     *
     * @return BaseDirective
     */
    public function hydrate($definitionNode): BaseDirective
    {
        $this->definitionNode = $definitionNode;

        return $this;
    }

    /**
     * This can be at most one directive, since directives can only be used once per location.
     *
     * @param string|null                   $name
     * @param TypeSystemDefinitionNode|null $definitionNode
     *
     * @return DirectiveNode|null
     */
    protected function directiveDefinition($name = null, $definitionNode = null)
    {
        $name = $name ?? static::name();
        $definitionNode = $definitionNode ?? $this->definitionNode;

        return collect($definitionNode->directives)->first(function (DirectiveNode $directiveDefinitionNode) use ($name) {
            return $directiveDefinitionNode->name->value === $name;
        });
    }

    /**
     * Get directive argument value.
     *
     * @param string             $name
     * @param mixed|null         $default
     * @param DirectiveNode|null $directive
     *
     * @return mixed
     */
    protected function directiveArgValue(string $name, $default = null, $directive = null)
    {
        // Get the definition associated with the class of the directive, unless explicitly given
        $directive = $directive ?? $this->directiveDefinition();

        if (! $directive) {
            return $default;
        }

        $arg = collect($directive->arguments)->first(function (ArgumentNode $argumentNode) use ($name) {
            return $argumentNode->name->value === $name;
        });

        return $arg
            ? $this->argValue($arg, $default)
            : $default;
    }
    
    /**
     * Get the resolver that is specified in the current directive.
     *
     * @param Closure $defaultResolver Add in a default resolver to return if no resolver class is given.
     * @param string $argumentName If the name of the directive argument is not "resolver" you may overwrite it.
     *
     * @throws DirectiveException
     *
     * @return Closure
     */
    protected function getResolver(Closure $defaultResolver = null, string $argumentName = 'resolver'): Closure
    {
        $resolverFragments = explode('@', $this->directiveArgValue($argumentName) ?? '');

        $baseClassName =
            $this->directiveArgValue('class')
            ?? $resolverFragments[0];

        if (empty($baseClassName)) {
            // If a default is given, simply return it
            if ($defaultResolver) {
                return $defaultResolver;
            }

            $directiveName = $this->name();
            throw new DirectiveException("Directive '{$directiveName}' must have a resolver class specified.");
        }

        $resolverClass = $this->namespaceClassName($baseClassName);
        $resolverMethod =
            $this->directiveArgValue('method')
            ?? $resolverFragments[1]
            ?? 'resolve';

        if ( ! method_exists($resolverClass, $resolverMethod)) {
            throw new DirectiveException("Method '{$resolverMethod}' does not exist on class '{$resolverClass}'");
        }

        return Closure::fromCallable([app($resolverClass), $resolverMethod]);
    }

    /**
     * Get the model class from `model` argument of field.
     * If `model` argument not provided, fallback to:
     * 1. `field return type` + namespace
     * 2. `field name(singular)` + namespace
     *
     * @throws DirectiveException
     *
     * @return string
     */
    protected function getModelClass(): string
    {
        $modelClassFromArg = $this->directiveArgValue('model');

        if ($modelClassFromArg && \class_exists($modelClassFromArg)) {
            return $modelClassFromArg;
        }

        $classFromArg = function () use ($modelClassFromArg) {
            return $modelClassFromArg;
        };

        $classFromFieldReturnType = function () {
            return $this->definitionNode instanceof FieldDefinitionNode ?
                ASTHelper::getFieldTypeName($this->definitionNode) :
                null;
        };

        $classFromfieldName = function () {
            return $this->definitionNode instanceof FieldDefinitionNode ?
                studly_case(str_singular($this->definitionNode->name->value)) :
                null;
        };

        $defaultNamespace = config('lighthouse.namespaces.models');
        $namespace = $this->associatedNamespace() ?: $defaultNamespace;
        $fallbackClass = null;

        collect([
            $classFromArg,
            $classFromFieldReturnType,
            $classFromfieldName,
        ])->first(function (Closure $getBaseClassName) use ($namespace, &$fallbackClass) {
            // Try to locate a valid class by using the following pattern.
            // Lower number has higher priority.
            // 1. 'model' argument + namespace
            // 2. return type + namespace
            // 3. field name + namespace

            try {
                $baseClassName = $getBaseClassName();

                if(!$baseClassName){
                    return false;
                }

                $className = "$namespace\\$baseClassName";
                $classExists = \class_exists($className);

                if ($classExists) {
                    $fallbackClass = $className;

                    return true;
                }
            } catch (Exception $e) {}

            return false;
        });

        if ( ! $fallbackClass) {
            $nodeName = $this->definitionNode->name->value;
            $directiveName = $this->name();
            throw new DirectiveException(
                "A valid model class for `$directiveName` directive on `$nodeName` not found."
            );
        }

        return $fallbackClass;
    }

    /**
     * Add the namespace to a class name and check if it exists.
     *
     * @param string $baseClassName
     *
     * @throws DirectiveException
     *
     * @return string
     */
    protected function namespaceClassName(string $baseClassName): string
    {
        $className = $this->associatedNamespace().'\\'.$baseClassName;

        if (! class_exists($className)) {
            $directiveName = static::name();
            throw new DirectiveException("No class '$className' was found for directive '$directiveName'");
        }

        return $className;
    }

    /**
     * Get the namespace for the current directive, returns an empty string if its not set.
     *
     * @return string
     */
    protected function associatedNamespace(): string
    {
        $namespaceDirective = $this->directiveDefinition(
            (new NamespaceDirective())->name()
        );

        return $namespaceDirective
            // The namespace directive can contain an argument with the name of the
            // current directive, in which case it applies here
            ? $this->directiveArgValue(static::name(), '', $namespaceDirective)
            // Default to an empty namespace if the namespace directive does not exist
            : '';
    }

    /**
     * Get argument's value.
     *
     * @param Node  $arg
     * @param mixed $default
     *
     * @return mixed
     */
    protected function argValue(Node $arg, $default = null)
    {
        $valueNode = $arg->value;

        if (! $valueNode) {
            return $default;
        }

        if ($valueNode instanceof ListValueNode) {
            return collect($valueNode->values)->map(function (ValueNode $valueNode) {
                return $valueNode->value;
            })->toArray();
        }

        if ($valueNode instanceof ObjectValueNode) {
            return collect($valueNode->fields)->mapWithKeys(function (ObjectFieldNode $field) {
                return [$field->name->value => $this->argValue($field)];
            })->toArray();
        }

        return $valueNode->value;
    }
}
