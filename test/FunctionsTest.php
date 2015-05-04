<?php

namespace Amp\Test;

use Amp\NativeReactor;
use Amp\Success;
use Amp\Failure;

class FunctionsTest extends \PHPUnit_Framework_TestCase {

    public function testAllResolutionWhenNoPromiseInstancesCombined() {
        $promises = [null, 1, 2, true];
        \Amp\all($promises)->when(function($e, $r) {
            list($a, $b, $c, $d) = $r;
            $this->assertNull($a);
            $this->assertSame(1, $b);
            $this->assertSame(2, $c);
            $this->assertSame(true, $d);
        });
    }

    public function testSomeResolutionWhenNoPromiseInstancesCombined() {
        $promises = [null, 1, 2, true];
        \Amp\some($promises)->when(function($e, $r) {
            list($errors, $results) = $r;
            list($a, $b, $c, $d) = $results;
            $this->assertNull($a);
            $this->assertSame(1, $b);
            $this->assertSame(2, $c);
            $this->assertSame(true, $d);
        });
    }

    public function testAnyResolutionWhenNoPromiseInstancesCombined() {
        $promises = [null, 1, 2, true];
        \Amp\any($promises)->when(function($e, $r) {
            list($errors, $results) = $r;
            list($a, $b, $c, $d) = $results;
            $this->assertNull($a);
            $this->assertSame(1, $b);
            $this->assertSame(2, $c);
            $this->assertSame(true, $d);
        });
    }

    public function testAllResolvesWithArrayOfResults() {
        \Amp\all(['r1' => 42, 'r2' => new Success(41)])->when(function($error, $result) {
            $expected = ['r1' => 42, 'r2' => 41];
            $this->assertSame($expected, $result);
        });
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage zanzibar
     */
    public function testAllThrowsIfAnyIndividualPromiseFails() {
        $exception = new \RuntimeException('zanzibar');
        \Amp\all([
            'r1' => new Success(42),
            'r2' => new Failure($exception),
            'r3' => new Success(40),
        ])->when(function($error) {
            throw $error;
        });
    }

    public function testSomeReturnsArrayOfErrorsAndResults() {
        $exception = new \RuntimeException('zanzibar');
        \Amp\some([
            'r1' => new Success(42),
            'r2' => new Failure($exception),
            'r3' => new Success(40),
        ])->when(function($error, $result) use ($exception) {
            list($errors, $results) = (yield \Amp\some($promises));
            $this->assertSame(['r2' => $exception], $errors);
            $this->assertSame(['r1' => 42, 'r3' => 40], $results);
        });
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage All promises failed
     */
    public function testSomeThrowsIfNoPromisesResolveSuccessfully() {
        \Amp\some([
            'r1' => new Failure(new \RuntimeException),
            'r2' => new Failure(new \RuntimeException),
        ])->when(function($error) {
            throw $error;
        });
    }

    public function testResolutionFailuresAreThrownIntoGenerator() {
        $foo = function() {
            $a = (yield new Success(21));
            $b = 1;
            try {
                yield new Failure(new \Exception('test'));
                $this->fail('Code path should not be reached');
            } catch (\Exception $e) {
                $this->assertSame('test', $e->getMessage());
                $b = 2;
            }
        };

        (new NativeReactor)->run(function($reactor) use ($foo) {
            $result = (yield \Amp\resolve($foo(), $reactor));
        });
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage When in the chronicle of wasted time
     */
    public function testUncaughtGeneratorExceptionFailsResolverPromise() {
        (new NativeReactor)->run(function($reactor) {
            $gen = function() {
                yield;
                throw new \Exception('When in the chronicle of wasted time');
                yield;
            };

            yield \Amp\resolve($gen(), $reactor);
        });
    }

    public function testAllCombinatorResolution() {
        (new NativeReactor)->run(function($reactor) {
            list($a, $b) = (yield \Amp\all([
                    new Success(21),
                    new Success(2),
            ]));

            $result = ($a * $b);
            $this->assertSame(42, $result);
        });
    }

    public function testAllCombinatorResolutionWithNonPromises() {
        (new NativeReactor)->run(function($reactor) {
            list($a, $b, $c) = (yield \Amp\all([new Success(21), new Success(2), 10]));
            $result = ($a * $b * $c);
            $this->assertSame(420, $result);
        });
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage When in the chronicle of wasted time
     */
    public function testAllCombinatorResolutionThrowsIfAnyOnePromiseFails() {
        (new NativeReactor)->run(function($reactor) {
            list($a, $b) = (yield \Amp\all([
                new Success(21),
                new Failure(new \Exception('When in the chronicle of wasted time')),
            ]));
        });
    }

    public function testExplicitAllCombinatorResolution() {
        (new NativeReactor)->run(function($reactor) {
            list($a, $b, $c) = (yield \Amp\all([
                new Success(21),
                new Success(2),
                10
            ]));

            $this->assertSame(420, ($a * $b * $c));
        });
    }

    public function testExplicitAnyCombinatorResolution() {
        (new NativeReactor)->run(function($reactor) {
            list($errors, $results) = (yield \Amp\any([
                'a' => new Success(21),
                'b' => new Failure(new \Exception('test')),
            ]));
            $this->assertSame('test', $errors['b']->getMessage());
            $this->assertSame(21, $results['a']);
        });
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage All promises failed
     */
    public function testExplicitSomeCombinatorResolutionFailsOnError() {
        (new NativeReactor)->run(function($reactor) {
            yield \Amp\some([
                'r1' => new Failure(new \RuntimeException),
                'r2' => new Failure(new \RuntimeException),
            ]);
        });
    }

    public function testCoroutineReturnValue() {
        $co = function() {
            yield;
            yield "return" => 42;
            yield;
        };
        (new NativeReactor)->run(function($reactor) use ($co) {
            $result = (yield \Amp\resolve($co()));
            $this->assertSame(42, $result);
        });
    }
}