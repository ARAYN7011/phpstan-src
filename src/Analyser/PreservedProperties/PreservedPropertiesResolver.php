<?php declare(strict_types = 1);

namespace PHPStan\Analyser\PreservedProperties;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\Analyser\CalledMethodProcessor;
use PHPStan\Analyser\MutatingScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\PreservedPropertiesVisitor;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeUtils;
use function array_keys;
use function count;
use function explode;
use function implode;
use function str_contains;

/**
 * Which properties of $this keep their remembered types across an impure call
 * on $this - what PreservedPropertiesVisitor found, minus the properties passed
 * to a by-reference parameter of a function.
 */
#[AutowiredService]
final class PreservedPropertiesResolver
{

	/** @var array<string, bool> */
	private array $byValueCache = [];

	public function __construct(
		private ReflectionProvider $reflectionProvider,
		private CalledMethodProcessor $calledMethodProcessor,
	)
	{
	}

	/**
	 * After a call of a method of the class on $this that runs exactly that method - a private
	 * or final one, or one called through self:: - the properties the walk of the method ended
	 * with initialized are initialized, and the properties of $this the class's own code the call
	 * runs writes get the types the walk ended with. ClassLikeHandler walks the methods before
	 * their callers.
	 */
	public function applyCalledMethodExitScope(MutatingScope $scope, Expr $call, MethodReflection $methodReflection): MutatingScope
	{
		if ($methodReflection->isStatic() || !$scope->isInClass()) {
			return $scope;
		}
		$classReflection = $scope->getClassReflection();
		if ($methodReflection->getDeclaringClass()->getName() !== $classReflection->getName()) {
			return $scope;
		}

		if ($call instanceof MethodCall) {
			if (!$call->var instanceof Variable || $call->var->name !== 'this') {
				return $scope;
			}
			$runsExactly = false;
		} elseif ($call instanceof StaticCall) {
			if (!$call->class instanceof Name) {
				return $scope;
			}
			$className = $call->class->toLowerString();
			if ($className === 'parent') {
				return $scope;
			}
			$function = $scope->getFunction();
			if (!$function instanceof MethodReflection || $function->isStatic()) {
				return $scope;
			}
			$runsExactly = $className !== 'static';
		} else {
			return $scope;
		}
		if (!$runsExactly && !$methodReflection->isPrivate() && !$methodReflection->isFinal()->yes() && !$classReflection->isFinal()) {
			return $scope;
		}

		$exitScope = $this->calledMethodProcessor->getWalkedMethodExitScope($classReflection, $methodReflection->getName());
		if ($exitScope === null) {
			return $scope;
		}
		$scope = $scope->mergeInitializedProperties($exitScope);

		$preservedProperties = $call->getAttribute(PreservedPropertiesVisitor::ATTRIBUTE_NAME);
		if (!$preservedProperties instanceof PreservedProperties) {
			return $scope;
		}
		$writtenPropertyNames = $preservedProperties->getWrittenPropertyNames();

		foreach (array_keys($writtenPropertyNames) as $propertyName) {
			$propertyFetch = new PropertyFetch(new Variable('this'), $propertyName);
			if ($scope->hasExpressionType($propertyFetch)->yes() || !$exitScope->hasExpressionType($propertyFetch)->yes()) {
				continue;
			}
			$type = $exitScope->getType($propertyFetch);
			if (TypeUtils::containsTemplateType($type)) {
				// the method's own template types mean nothing after the call
				continue;
			}
			$scope = $scope->assignExpression($propertyFetch, $type, $exitScope->getNativeType($propertyFetch));
		}

		return $scope;
	}

	/**
	 * @return array<string, true>|null null when every property is forgotten
	 */
	public function resolve(Expr $call): ?array
	{
		$preservedProperties = $call->getAttribute(PreservedPropertiesVisitor::ATTRIBUTE_NAME);
		if (!$preservedProperties instanceof PreservedProperties) {
			return null;
		}

		$propertyNames = $preservedProperties->getPropertyNames();
		foreach ($preservedProperties->getFunctionArguments() as [$functionNames, $position, $propertyName]) {
			if (!isset($propertyNames[$propertyName]) || $this->isFunctionArgumentByValue($functionNames, $position)) {
				continue;
			}
			unset($propertyNames[$propertyName]);
		}

		return count($propertyNames) > 0 ? $propertyNames : null;
	}

	/**
	 * A call not known to have side effects keeps everything remembered, except the properties
	 * of $this that PreservedPropertiesVisitor saw the class's own code run by the call write.
	 */
	public function forgetWrittenProperties(MutatingScope $scope, Expr $call): MutatingScope
	{
		$preservedProperties = $call->getAttribute(PreservedPropertiesVisitor::ATTRIBUTE_NAME);
		if (!$preservedProperties instanceof PreservedProperties) {
			return $scope;
		}

		$propertyNames = $preservedProperties->getWrittenPropertyNames();
		foreach ($preservedProperties->getWrittenFunctionArguments() as [$functionNames, $position, $propertyName]) {
			if ($this->isFunctionArgumentByValue($functionNames, $position)) {
				continue;
			}
			$propertyNames[$propertyName] = true;
		}

		foreach (array_keys($propertyNames) as $propertyName) {
			$propertyFetch = new PropertyFetch(new Variable('this'), $propertyName);
			if ($scope->hasExpressionType($propertyFetch)->yes() && $scope->isReadonlyPropertyFetch($propertyFetch, true)) {
				// remembered, so initialized, and a readonly property cannot change after that
				continue;
			}
			$scope = $scope->invalidateExpression($propertyFetch);
		}

		return $scope;
	}

	/**
	 * @param list<non-empty-string> $functionNames
	 */
	private function isFunctionArgumentByValue(array $functionNames, int $position): bool
	{
		return $this->byValueCache[implode('|', $functionNames) . '#' . $position] ??= $this->findIsFunctionArgumentByValue($functionNames, $position);
	}

	/**
	 * @param list<non-empty-string> $functionNames candidate function names, or 'Class::method'
	 */
	private function findIsFunctionArgumentByValue(array $functionNames, int $position): bool
	{
		foreach ($functionNames as $functionName) {
			$variants = $this->findVariants($functionName);
			if ($variants === null) {
				continue;
			}

			foreach ($variants as $variant) {
				$parameters = $variant->getParameters();
				$parameter = $parameters[$position] ?? null;
				if ($parameter === null) {
					if (!$variant->isVariadic() || count($parameters) === 0) {
						continue;
					}
					$parameter = $parameters[count($parameters) - 1];
				}
				if (!$parameter->passedByReference()->no()) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @param non-empty-string $calleeName
	 * @return list<ParametersAcceptor>|null null when there is no such function or method
	 */
	private function findVariants(string $calleeName): ?array
	{
		if (!str_contains($calleeName, '::')) {
			$name = new Name($calleeName);
			if (!$this->reflectionProvider->hasFunction($name, null)) {
				return null;
			}

			return $this->reflectionProvider->getFunction($name, null)->getVariants();
		}

		[$className, $methodName] = explode('::', $calleeName, 2);
		if (!$this->reflectionProvider->hasClass($className)) {
			return null;
		}
		$classReflection = $this->reflectionProvider->getClass($className);
		if (!$classReflection->hasNativeMethod($methodName)) {
			// a class without a constructor takes no arguments by reference
			return $methodName === '__construct' ? [] : null;
		}

		return $classReflection->getNativeMethod($methodName)->getVariants();
	}

}
