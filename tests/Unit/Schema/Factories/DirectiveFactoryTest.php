<?php

namespace Tests\Unit\Schema;

use Tests\TestCase;
use Nuwave\Lighthouse\Schema\AST\PartialParser;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Exceptions\DirectiveException;
use Nuwave\Lighthouse\Schema\Factories\DirectiveFactory;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Schema\Directives\Fields\FieldDirective;

class DirectiveFactoryTest extends TestCase
{
    /**
     * @var DirectiveFactory
     */
    protected $directiveFactory;

    public function getEnvironmentSetUp($app)
    {
        $this->directiveFactory = $app[DirectiveFactory::class];

        parent::getEnvironmentSetUp($app);
    }

    /**
     * @test
     */
    public function itRegistersLighthouseDirectives()
    {
        $this->assertInstanceOf(
            FieldDirective::class,
            $this->directiveFactory->create((new FieldDirective())->name())
        );
    }

    /**
     * @test
     */
    public function itHydratesBaseDirectives()
    {
        $fieldDefinition = PartialParser::fieldDefinition('
            foo: String
        ');

        $fieldDirective = $this->directiveFactory->create('field', $fieldDefinition);
        $this->assertAttributeEquals($fieldDefinition, 'definitionNode', $fieldDirective);
    }

    /**
     * @deprecated this test is for compatibility reasons and can likely be removed in v3
     * @test
     */
    public function itSkipsHydrationForNonBaseDirectives()
    {
        $fieldDefinition = PartialParser::fieldDefinition('
            foo: String
        ');

        $directive = new class() implements FieldMiddleware {
            public function name(): string
            {
                return 'foo';
            }

            public function handleField(FieldValue $value, \Closure $next)
            {
            }
        };

        $this->directiveFactory->setResolved('foo', \get_class($directive));
        $directive = $this->directiveFactory->create('foo', $fieldDefinition);

        $this->assertObjectNotHasAttribute('definitionNode', $directive);
    }

    /**
     * @test
     */
    public function itThrowsIfDirectiveNameCanNotBeResolved()
    {
        $this->expectException(DirectiveException::class);
        $this->directiveFactory->create('bar');
    }
}