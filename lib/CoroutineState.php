<?php

namespace Amp;

/**
 * @TODO This class is only necessary for PHP5; remove in favor of an anon class once PHP7 is required
 */
class CoroutineState {
    use Struct;
    public $reactor;
    public $promisor;
    public $generator;
    public $returnValue;
    public $currentPromise;
    public $nestingLevel;
}
