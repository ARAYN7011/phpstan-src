<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\NeverType;
use function array_key_exists;
use function strtolower;

/**
 * The walks of the methods of the classes being walked: the scope a method's walk ends
 * with is what calling the method does to $this. ClassLikeHandler walks the methods
 * called on $this before their callers.
 */
#[AutowiredService]
final class CalledMethodProcessor
{

	/** @var array<string, array<string, MethodReturnStatementsNode>> by class name and lowercase method name */
	private array $walkedMethods = [];

	/** @var array<string, array<string, MutatingScope|null>> by class name and lowercase method name */
	private array $exitScopes = [];

	public function rememberWalkedMethod(MethodReturnStatementsNode $node): void
	{
		$className = $node->getClassReflection()->getName();
		$methodName = strtolower($node->getMethodName());
		$this->walkedMethods[$className][$methodName] = $node;
		unset($this->exitScopes[$className][$methodName]);
	}

	/**
	 * The merged scope at the ends of the method's walk, null when it was not walked
	 * yet or never returns.
	 */
	public function getWalkedMethodExitScope(ClassReflection $classReflection, string $methodName): ?MutatingScope
	{
		$className = $classReflection->getName();
		$methodName = strtolower($methodName);
		if (!isset($this->walkedMethods[$className][$methodName])) {
			return null;
		}
		if (!array_key_exists($methodName, $this->exitScopes[$className] ?? [])) {
			$this->exitScopes[$className][$methodName] = $this->getExitScope($this->walkedMethods[$className][$methodName]);
		}

		return $this->exitScopes[$className][$methodName];
	}

	public function clearCalledMethodResults(ClassReflection $classReflection): void
	{
		unset($this->walkedMethods[$classReflection->getName()], $this->exitScopes[$classReflection->getName()]);
	}

	private function getExitScope(MethodReturnStatementsNode $returnStatement): ?MutatingScope
	{
		$exitScope = null;
		foreach ($returnStatement->getExecutionEnds() as $executionEnd) {
			$statementResult = $executionEnd->getStatementResult();
			$endExprResult = $executionEnd->getExprResult();
			if ($endExprResult !== null) {
				$walkScope = $statementResult->getScope()->toWalkScope();
				$exprType = $endExprResult->getTypeOnScope($walkScope, $walkScope->nativeTypesPromoted);
				if ($exprType instanceof NeverType && $exprType->isExplicit()) {
					continue;
				}
			}
			if ($exitScope === null) {
				$exitScope = $statementResult->getScope()->toWalkScope();
				continue;
			}

			$exitScope = $exitScope->mergeWith($statementResult->getScope()->toWalkScope());
		}
		foreach ($returnStatement->getReturnStatements() as $statement) {
			if ($exitScope === null) {
				$exitScope = $statement->getScope()->toWalkScope();
				continue;
			}

			$exitScope = $exitScope->mergeWith($statement->getScope()->toWalkScope());
		}

		return $exitScope;
	}

}
