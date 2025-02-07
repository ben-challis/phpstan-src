<?php declare(strict_types = 1);

namespace PHPStan\Rules\Functions;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\ArgumentsNormalizer;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Rules\RuleLevelHelper;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use function array_key_exists;
use function count;
use function in_array;
use function sprintf;

/**
 * @implements Rule<Node\Expr\FuncCall>
 */
class ParameterCastableToStringFunctionRule implements Rule
{

	public function __construct(
		private ReflectionProvider $reflectionProvider,
		private RuleLevelHelper $ruleLevelHelper,
	)
	{
	}

	public function getNodeType(): string
	{
		return FuncCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!($node->name instanceof Node\Name)) {
			return [];
		}

		if (!$this->reflectionProvider->hasFunction($node->name, $scope)) {
			return [];
		}

		$functionReflection = $this->reflectionProvider->getFunction($node->name, $scope);
		$functionName = $functionReflection->getName();
		$implodeFunctions = ['implode', 'join'];
		$checkAllArgsFunctions = ['array_intersect', 'array_intersect_assoc', 'array_diff', 'array_diff_assoc'];
		$checkFirstArgFunctions = [
			'array_unique',
			'array_combine',
			'sort',
			'rsort',
			'asort',
			'arsort',
			'natcasesort',
			'natsort',
			'array_count_values',
			'array_fill_keys',
		];

		if (
			!in_array($functionName, $checkAllArgsFunctions, true)
			&& !in_array($functionName, $checkFirstArgFunctions, true)
			&& !in_array($functionName, $implodeFunctions, true)
		) {
			return [];
		}

		$origArgs = $node->getArgs();
		$parametersAcceptor = ParametersAcceptorSelector::selectFromArgs(
			$scope,
			$origArgs,
			$functionReflection->getVariants(),
			$functionReflection->getNamedArgumentsVariants(),
		);

		$errorMessage = 'Parameter %s of function %s expects an array of values castable to string, %s given.';
		$getNormalizedArgs = static function () use ($parametersAcceptor, $node): array {
			$normalizedFuncCall = ArgumentsNormalizer::reorderFuncArguments($parametersAcceptor, $node);

			if ($normalizedFuncCall === null) {
				return [];
			}

			return $normalizedFuncCall->getArgs();
		};
		if (in_array($functionName, $implodeFunctions, true)) {
			$normalizedArgs = $getNormalizedArgs();
			$errorMessage = 'Parameter %s of function %s expects array<string>, %s given.';
			if (count($normalizedArgs) === 1) {
				$argsToCheck = [0 => $normalizedArgs[0]];
			} elseif (count($normalizedArgs) === 2) {
				$argsToCheck = [1 => $normalizedArgs[1]];
			} else {
				return [];
			}
		} elseif (in_array($functionName, $checkAllArgsFunctions, true)) {
			$argsToCheck = $origArgs;
		} elseif (in_array($functionName, $checkFirstArgFunctions, true)) {
			$normalizedArgs = $getNormalizedArgs();
			if ($normalizedArgs === []) {
				return [];
			}
			$argsToCheck = [0 => $normalizedArgs[0]];
		} else {
			return [];
		}

		$origNamedArgs = [];
		foreach ($origArgs as $arg) {
			if ($arg->unpack || $arg->name === null) {
				continue;
			}

			$origNamedArgs[$arg->name->toString()] = $arg;
		}

		$errors = [];
		$functionParameters = $parametersAcceptor->getParameters();

		foreach ($argsToCheck as $argIdx => $arg) {
			if ($arg->unpack) {
				continue;
			}

			$typeResult = $this->ruleLevelHelper->findTypeToCheck(
				$scope,
				$arg->value,
				'',
				static fn (Type $type): bool => !$type->getIterableValueType()->toString() instanceof ErrorType,
			);

			if ($typeResult->getType() instanceof ErrorType
				|| !$typeResult->getType()->getIterableValueType()->toString() instanceof ErrorType) {
				continue;
			}

			if (in_array($functionName, $implodeFunctions, true)) {
				// implode has weird variants, so $array has to be fixed. It's especially weird with named arguments.
				if (array_key_exists('array', $origNamedArgs)) {
					$argName = '$array';
				} elseif (array_key_exists('separator', $origNamedArgs) && count($origArgs) === 1) {
					$argName = '$separator';
				} else {
					$argName = sprintf('#%d $array', $argIdx + 1);
				}
			} elseif (array_key_exists($argIdx, $functionParameters)) {
				$paramName = $functionParameters[$argIdx]->getName();
				$argName = array_key_exists($paramName, $origNamedArgs)
					? sprintf('$%s', $paramName)
					: sprintf('#%d $%s', $argIdx + 1, $paramName);
			} else {
				$argName = sprintf('#%d', $argIdx + 1);
			}

			$errors[] = RuleErrorBuilder::message(
				sprintf($errorMessage, $argName, $functionName, $typeResult->getType()->describe(VerbosityLevel::typeOnly())),
			)->identifier('argument.type')->build();
		}

		return $errors;
	}

}
