<?php

/**
 * Test: Kdyby\Aop\Extension.
 *
 * @testCase KdybyTests\Aop\ExtensionTest
 * @author Filip Procházka <filip@prochazka.su>
 * @package Kdyby\Aop
 */

namespace KdybyTests\Aop;

use Doctrine\Common\Annotations\AnnotationException;
use Kdyby;
use Kdyby\Aop\JoinPoint\AfterMethod;
use Kdyby\Aop\JoinPoint\AfterReturning;
use Kdyby\Aop\JoinPoint\AfterThrowing;
use Kdyby\Aop\JoinPoint\BeforeMethod;
use Kdyby\Aop\JoinPoint\MethodInvocation;
use Nette;
use Tester;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/files/aspect-examples.php';



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class ExtensionTest extends Tester\TestCase
{

	/**
	 * @param string $configFile
	 * @return \SystemContainer|Nette\DI\Container
	 */
	public function createContainer($configFile)
	{
		$config = new Nette\Configurator();
		$config->setTempDirectory(TEMP_DIR);
		$config->addParameters(array('container' => array('class' => 'SystemContainer_' . md5($configFile))));
		$config->addConfig(__DIR__ . '/../nette-reset.neon');
		$config->addConfig(__DIR__ . '/config/' . $configFile . '.neon');

		Kdyby\Annotations\DI\AnnotationsExtension::register($config);
		Kdyby\Aop\DI\AopExtension::register($config);

		return $config->createContainer();
	}



	public function testFunctionalBefore()
	{
		$dic = $this->createContainer('before');
		$service = $dic->getByType('KdybyTests\Aop\CommonService');
		/** @var CommonService $service */

		Assert::same(4, $service->magic(2));
		Assert::same(array(2), $service->calls[0]);
		$advice = self::assertAspectInvocation($service, 'KdybyTests\Aop\BeforeAspect', 0, new BeforeMethod($service, 'magic', array(2)));
		/** @var BeforeAspect $advice */

		$service->return = 3;
		Assert::same(6, $service->magic(2));
		Assert::same(array(2), $service->calls[1]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\BeforeAspect', 1, new BeforeMethod($service, 'magic', array(2)));

		$advice->modifyArgs = array(3);
		Assert::same(9, $service->magic(2));
		Assert::same(array(3), $service->calls[2]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\BeforeAspect', 2, new BeforeMethod($service, 'magic', array(3)));
	}



	public function testFunctionalAfterReturning()
	{
		$dic = $this->createContainer('afterReturning');
		$service = $dic->getByType('KdybyTests\Aop\CommonService');
		/** @var CommonService $service */

		Assert::same(4, $service->magic(2));
		Assert::same(array(2), $service->calls[0]);
		$advice = self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterReturningAspect', 0, new AfterReturning($service, 'magic', array(2), 4));
		/** @var AfterReturningAspect $advice */

		$service->return = 3;
		Assert::same(6, $service->magic(2));
		Assert::same(array(2), $service->calls[1]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterReturningAspect', 1, new AfterReturning($service, 'magic', array(2), 6));

		$advice->modifyReturn = 9;
		Assert::same(9, $service->magic(2));
		Assert::same(array(2), $service->calls[2]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterReturningAspect', 2, new AfterReturning($service, 'magic', array(2), 9));
	}



	public function testFunctionalAfterThrowing()
	{
		$dic = $this->createContainer('afterThrowing');
		$service = $dic->getByType('KdybyTests\Aop\CommonService');
		/** @var CommonService $service */

		$service->throw = TRUE;
		Assert::throws(function () use ($service) {
			$service->magic(2);
		}, 'RuntimeException', "Something's fucky");

		Assert::same(array(2), $service->calls[0]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterThrowingAspect', 0, new AfterThrowing($service, 'magic', array(2), new \RuntimeException("Something's fucky")));
	}



	public function testFunctionalAfter()
	{
		$dic = $this->createContainer('after');
		$service = $dic->getByType('KdybyTests\Aop\CommonService');
		/** @var CommonService $service */

		Assert::same(4, $service->magic(2));
		Assert::same(array(2), $service->calls[0]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterAspect', 0, new AfterMethod($service, 'magic', array(2), 4));

		$service->throw = TRUE;
		Assert::throws(function () use ($service) {
			$service->magic(2);
		}, 'RuntimeException', "Something's fucky");

		Assert::same(array(2), $service->calls[1]);
		self::assertAspectInvocation($service, 'KdybyTests\Aop\AfterAspect', 1, new AfterMethod($service, 'magic', array(2), NULL, new \RuntimeException("Something's fucky")));
	}



	/**
	 * @param object $service
	 * @param string $adviceClass
	 * @param int $adviceCallIndex
	 * @param MethodInvocation $joinPoint
	 * @return object
	 */
	private static function assertAspectInvocation($service, $adviceClass, $adviceCallIndex, MethodInvocation $joinPoint)
	{
		$advices = array_filter(self::getAspects($service), function ($advice) use ($adviceClass) {
			return $advice instanceof $adviceClass;
		});
		Assert::true(!empty($advices));
		$advice = reset($advices);
		Assert::true($advice instanceof $adviceClass);

		Assert::true(!empty($advice->calls[$adviceCallIndex]));
		$call = $advice->calls[$adviceCallIndex];
		/** @var MethodInvocation $call */

		$joinPointClass = get_class($joinPoint);
		Assert::true($call instanceof $joinPointClass);
		Assert::equal($joinPoint->getArguments(), $call->getArguments());
		Assert::same($joinPoint->getTargetObject(), $call->getTargetObject());
		Assert::same($joinPoint->getTargetReflection()->getName(), $call->getTargetReflection()->getName());

		if ($joinPoint instanceof Kdyby\Aop\JoinPoint\ResultAware) {
			/** @var AfterReturning $call */
			Assert::same($joinPoint->getResult(), $call->getResult());
		}

		if ($joinPoint instanceof Kdyby\Aop\JoinPoint\ExceptionAware) {
			/** @var AfterThrowing $call */
			Assert::equal($joinPoint->getException() ? get_class($joinPoint->getException()) : NULL, $call->getException() ? get_class($call->getException()) : NULL);
			Assert::equal($joinPoint->getException() ? $joinPoint->getException()->getMessage() : '', $call->getException() ? $call->getException()->getMessage() : '');
		}

		return $advice;
	}



	/**
	 * @param string $service
	 * @return array
	 */
	private static function getAspects($service)
	{
		try {
			$propRefl = Nette\Reflection\ClassType::from($service)
				->getProperty('_kdyby_aopAdvices'); // internal property

			$propRefl->setAccessible(TRUE);
			return $propRefl->getValue($service);

		} catch (\ReflectionException $e) {
			return array();
		}
	}

}

\run(new ExtensionTest());
