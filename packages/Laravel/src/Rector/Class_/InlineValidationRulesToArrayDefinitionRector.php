<?php declare(strict_types=1);

namespace Rector\Laravel\Rector\Class_;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\Laravel\Tests\Rector\Class_\InlineValidationRulesToArrayDefinitionRector\InlineValidationRulesToArrayDefinitionRectorTest
 */
final class InlineValidationRulesToArrayDefinitionRector extends AbstractRector
{
    /**
     * @var string
     */
    private const FORM_REQUEST_CLASS = 'Illuminate\Foundation\Http\FormRequest';

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Transforms inline validation rules to array definition', [
            new CodeSample(
                <<<'CODE_SAMPLE'
use Illuminate\Foundation\Http\FormRequest;

class SomeClass extends FormRequest
{
    public function rules(): array
    {
        return [
            'someAttribute' => 'required|string|exists:' . SomeModel::class . 'id',
        ];
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use Illuminate\Foundation\Http\FormRequest;

class SomeClass extends FormRequest
{
    public function rules(): array
    {
        return [
            'someAttribute' => ['required', 'string', \Illuminate\Validation\Rule::exists(SomeModel::class, 'id')],
        ];
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [ArrayItem::class];
    }

    /**
     * @param ArrayItem $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->shouldSkipArrayItem($node)) {
            return null;
        }

        $newRules = $this->createNewRules($node);
        $node->value = $this->createArray($newRules);

        return $node;
    }

    /**
     * @return Expr[]
     */
    private function transformRulesSetToExpressionsArray(Expr $expr): array
    {
        if ($expr instanceof String_) {
            return array_map(static function (string $value): String_ {
                return new String_($value);
            }, explode('|', $expr->value));
        }

        if ($expr instanceof Concat) {
            $left = $this->transformRulesSetToExpressionsArray($expr->left);
            $expr->left = $left[count($left) - 1];

            $right = $this->transformRulesSetToExpressionsArray($expr->right);
            $expr->right = $right[0];

            return array_merge(array_slice($left, 0, -1), [$expr], array_slice($right, 1));
        }

        if ($expr instanceof ClassConstFetch || $expr instanceof MethodCall || $expr instanceof FuncCall) {
            return [$expr];
        }

        throw new ShouldNotHappenException('Unexpected call ' . get_class($expr));
    }

    private function transformConcatExpressionToSingleString(Concat $concat): ?string
    {
        $output = '';

        foreach ([$concat->left, $concat->right] as $expressionPart) {
            if ($expressionPart instanceof String_) {
                $output .= $expressionPart->value;
            } elseif ($expressionPart instanceof Concat) {
                $output .= $this->transformConcatExpressionToSingleString($expressionPart);
            } elseif ($expressionPart instanceof ClassConstFetch) {
                /** @var Node\Name $name */
                $name = $expressionPart->class->getAttribute('originalName');

                $output .= implode('\\', $name->parts);
            } else {
                // unable to process
                return null;
            }
        }

        return $output;
    }

    /**
     * @return Expr[]
     */
    private function createNewRules(ArrayItem $arrayItem): array
    {
        $newRules = $this->transformRulesSetToExpressionsArray($arrayItem->value);

        foreach ($newRules as $key => $newRule) {
            if (! $newRule instanceof Concat) {
                continue;
            }

            $fullString = $this->transformConcatExpressionToSingleString($newRule);
            if ($fullString === null) {
                return [];
            }

            $matchesExist = preg_match('#^exists:(\w+),(\w+)$#', $fullString, $matches);
            if ($matchesExist === false || $matchesExist === 0) {
                continue;
            }

            $ruleClass = $matches[1];
            $ruleAttribute = $matches[2];

            $newRules[$key] = new StaticCall(
                new FullyQualified('Illuminate\Validation\Rule'),
                'exists',
                [new Arg(new ClassConstFetch(new Name($ruleClass), 'class')), new Arg(new String_($ruleAttribute))]
            );
        }

        return $newRules;
    }

    private function shouldSkipArrayItem(ArrayItem $arrayItem): bool
    {
        $classNode = $arrayItem->getAttribute(AttributeKey::CLASS_NODE);
        if ($classNode === null) {
            return true;
        }

        if (! $this->isObjectType($classNode, self::FORM_REQUEST_CLASS)) {
            return true;
        }

        $methodNode = $arrayItem->getAttribute(AttributeKey::METHOD_NODE);
        if ($methodNode === null) {
            return true;
        }

        if (! $this->isName($methodNode, 'rules')) {
            return true;
        }

        if (! $arrayItem->value instanceof String_ && ! $arrayItem->value instanceof Concat) {
            return true;
        }

        return false;
    }
}
