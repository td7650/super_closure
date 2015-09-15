<?php namespace SuperClosure;

use SuperClosure\Analyzer\AstAnalyzer as DefaultAnalyzer;
use SuperClosure\Analyzer\ClosureAnalyzer;
use SuperClosure\Exception\ClosureUnserializationException;

/**
 * This class acts as a wrapper for a closure, and allows it to be serialized.
 *
 * With the combined power of the Reflection API, code parsing, and the infamous
 * `eval()` function, you can serialize a closure, unserialize it somewhere
 * else (even a different PHP process), and execute it.
 */
class SerializableClosure implements \Serializable
{
    /**
     * The special value marking a recursive reference to a closure.
     *
     * @var string
     */
    const RECURSION = "{{RECURSION}}";

    const EXCLUDE = "{{EXCLUDE}}";

    /**
     * The keys of closure data required for serialization.
     *
     * @var array
     */
    private static $dataToKeep = array(
        'code'     => true,
        'context'  => true,
        'binding'  => true,
        'scope'    => true,
        'isStatic' => true,
        'analyzer' => true,
    );

    /**
     * The closure analyzer instance.
     *
     * @var ClosureAnalyzer
     */
    private $analyzer;

    /**
     * The list of objects from context to exclude from serialization
     * set by key and value from these static list
     *
     * @var array
     */
    protected static $excludeFromContext = array(
        //
    );

    /**
     * The closure being wrapped for serialization.
     *
     * @var \Closure
     */
    private $closure;

    /**
     * The data from unserialization.
     *
     * @var array
     */
    private $data;

    /**
     * Create a new serializable closure instance.
     *
     * @param \Closure                 $closure
     * @param ClosureAnalyzer $analyzer
     */
    public function __construct(\Closure $closure, ClosureAnalyzer $analyzer = null)
    {
        $this->closure = $closure;
        $this->analyzer = $analyzer ?: new DefaultAnalyzer;
    }


    public static function setExcludeFromContext($key, &$value)
    {
        self::$excludeFromContext[$key] = $value;
    }

    public static function &getExcludeFromContext($key)
    {
        return self::$excludeFromContext[$key];
    }

    /**
     * @inheritDoc
     */
    public function getData(\Closure $closure, $forSerialization = false)
    {
        // Use the closure analyzer to get data about the closure.
        $data = $this->analyzer->analyze($closure);

        // If the closure data is getting retrieved solely for the purpose of
        // serializing the closure, then make some modifications to the data.
        if ($forSerialization) {
            // If there is no reference to the binding, don't serialize it.
            if (!$data['hasThis']) {
                $data['binding'] = null;
            }

            // Remove data about the closure that does not get serialized.
            $data = array_intersect_key($data, self::$dataToKeep);

            // Wrap any other closures within the context.
            foreach ($data['context'] as $key => &$value) {
                if ($value instanceof \Closure) {
                    $value = ($value === $closure)
                        ? self::RECURSION
                        : new self($value, $this->analyzer);
                }
                if (isset(self::$excludeFromContext[$key])) {
                    $value = self::EXCLUDE;
                }
            }
        }

        return $data;
    }

    /**
     * Return the original closure object.
     *
     * @return \Closure
     */
    public function getClosure()
    {
        return $this->closure;
    }

    /**
     * Delegates the closure invocation to the actual closure object.
     *
     * Important Notes:
     *
     * - `ReflectionFunction::invokeArgs()` should not be used here, because it
     *   does not work with closure bindings.
     * - Args passed-by-reference lose their references when proxied through
     *   `__invoke()`. This is an unfortunate, but understandable, limitation
     *   of PHP that will probably never change.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->closure, func_get_args());
    }

    /**
     * Clones the SerializableClosure with a new bound object and class scope.
     *
     * The method is essentially a wrapped proxy to the \Closure::bindTo method.
     *
     * @param mixed $newthis  The object to which the closure should be bound,
     *                        or NULL for the closure to be unbound.
     * @param mixed $newscope The class scope to which the closure is to be
     *                        associated, or 'static' to keep the current one.
     *                        If an object is given, the type of the object will
     *                        be used instead. This determines the visibility of
     *                        protected and private methods of the bound object.
     *
     * @return SerializableClosure
     * @link http://www.php.net/manual/en/closure.bindto.php
     */
    public function bindTo($newthis, $newscope = 'static')
    {
        return new self(
            $this->closure->bindTo($newthis, $newscope),
            $this->analyzer
        );
    }

    /**
     * Serializes the code, context, and binding of the closure.
     *
     * @return string|null
     * @link http://php.net/manual/en/serializable.serialize.php
     */
    public function serialize()
    {
        try {
            $this->data = $this->data ?: $this->getData($this->closure, true);
            return serialize($this->data);
        } catch (\Exception $e) {
            trigger_error(
                'Serialization of closure failed: ' . $e->getMessage(),
                E_USER_NOTICE
            );
            // Note: The serialize() method of Serializable must return a string
            // or null and cannot throw exceptions.
            return null;
        }
    }

    /**
     * Unserializes the closure.
     *
     * Unserializes the closure's data and recreates the closure using a
     * simulation of its original context. The used variables (context) are
     * extracted into a fresh scope prior to redefining the closure. The
     * closure is also rebound to its former object and scope.
     *
     * @param string $serialized
     *
     * @throws ClosureUnserializationException
     * @link http://php.net/manual/en/serializable.unserialize.php
     */
    public function unserialize($serialized)
    {
        // Unserialize the data and reconstruct the SuperClosure.
        $this->data = unserialize($serialized);
        try {
            $this->reconstructClosure();
        } catch (\ParseException $e) {
            // Discard the parse exception, we'll throw a custom one
            // a few lines down.
        }
        if (!$this->closure instanceof \Closure) {
            throw new ClosureUnserializationException(
                'The closure is corrupted and cannot be unserialized.'
            );
        }

        // Rebind the closure to its former binding, if it's not static.
        if (!$this->data['isStatic']) {
            $this->closure = $this->closure->bindTo(
                $this->data['binding'],
                $this->data['scope']
            );
        }
    }

    /**
     * Reconstruct the closure.
     *
     * HERE BE DRAGONS!
     *
     * The infamous `eval()` is used in this method, along with `extract()`,
     * the error suppression operator, and variable variables (i.e., double
     * dollar signs) to perform the unserialization work. I'm sorry, world!
     */
    private function reconstructClosure()
    {
        foreach($this->data['context'] as $key => &$value) {
            if ($value == self::EXCLUDE) {
                $value = self::getExcludeFromContext($key);
            }
        }
        // Simulate the original context the closure was created in.
        extract($this->data['context'], EXTR_OVERWRITE);

        // Evaluate the code to recreate the closure.
        if ($_fn = array_search(self::RECURSION, $this->data['context'], true)) {
            @eval("\${$_fn} = {$this->data['code']};");
            $this->closure = $$_fn;
        } else {
            @eval("\$this->closure = {$this->data['code']};");
        }
    }

    /**
     * Returns closure data for `var_dump()`.
     *
     * @return array
     */
    public function __debugInfo()
    {
        return $this->data ?: $this->getData($this->closure, true);
    }
}
