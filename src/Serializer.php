<?php namespace SuperClosure;

use SuperClosure\Analyzer\AstAnalyzer as DefaultAnalyzer;
use SuperClosure\Analyzer\ClosureAnalyzer;
use SuperClosure\Exception\ClosureUnserializationException;

/**
 * This is the serializer class used for serializing Closure objects.
 *
 * We're abstracting away all the details, impossibilities, and scary things
 * that happen within.
 */
class Serializer implements SerializerInterface
{

    /**
     * The closure analyzer instance.
     *
     * @var ClosureAnalyzer
     */
    private $analyzer;

    /**
     * The HMAC key to sign serialized closures.
     *
     * @var string
     */
    private $signingKey;

    /**
     * Create a new serializer instance.
     *
     * @param ClosureAnalyzer|null $analyzer   Closure analyzer instance.
     * @param string|null          $signingKey HMAC key to sign closure data.
     */
    public function __construct(ClosureAnalyzer $analyzer = null, $signingKey = null)
    {
        $this->analyzer = $analyzer;
        $this->signingKey = $signingKey;
    }

    /**
     * @inheritDoc
     */
    public function serialize(\Closure $closure)
    {
        $serialized = serialize(new SerializableClosure($closure, $this->analyzer));

        if ($this->signingKey) {
            $signature = $this->calculateSignature($serialized);
            $serialized = '%' . base64_encode($signature) . $serialized;
        }

        return $serialized;
    }

    /**
     * @inheritDoc
     */
    public function unserialize($serialized)
    {
        // Strip off the signature from the front of the string.
        $signature = null;
        if ($serialized[0] === '%') {
            $signature = base64_decode(substr($serialized, 1, 44));
            $serialized = substr($serialized, 45);
        }

        // If a key was provided, then verify the signature.
        if ($this->signingKey) {
            $this->verifySignature($signature, $serialized);
        }

        /** @var SerializableClosure $unserialized */
        $unserialized = unserialize($serialized);

        return $unserialized->getClosure();
    }

    /**
     * takes a Closure or a array of Closures and decorates it with a SerializableClosure object
     *
     * @param mixed     $data
     * @return mixed
     */
    public function wrapData($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if ($value instanceof \Closure) {
                    $value = new SerializableClosure($value, $this->analyzer);
                }
            }
        } else {
            if ($data instanceof \Closure) {
                $data = new SerializableClosure($data, $this->analyzer);
            }
        }

        return $data;

    }

    /**
     * Takes a decorated SerializableClosure object (or an array of it) and extracts the
     * original Closure objects
     *
     * @param mixed     $data
     * @return mixed
     */
    public function unwrapData($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if ($value instanceof SerializableClosure) {
                    $value = $value->getClosure();
                }
            }
        } else {
            if ($data instanceof SerializableClosure) {
                $data = $data->getClosure();
            }
        }

        return $data;

    }

    /**
     * Recursively traverses and wraps all Closure objects within the value.
     *
     * NOTE: THIS MAY NOT WORK IN ALL USE CASES, SO USE AT YOUR OWN RISK.
     *
     * @param mixed $data Any variable that contains closures.
     * @param SerializerInterface $serializer The serializer to use.
     */
    public static function wrapClosures(&$data, SerializerInterface $serializer)
    {
        if ($data instanceof \Closure) {
            // Handle and wrap closure objects.
            $reflection = new \ReflectionFunction($data);
            if ($binding = $reflection->getClosureThis()) {
                self::wrapClosures($binding, $serializer);
                $scope = $reflection->getClosureScopeClass();
                $scope = $scope ? $scope->getName() : 'static';
                $data = $data->bindTo($binding, $scope);
            }
            $data = new SerializableClosure($data);
        } elseif (is_array($data) || $data instanceof \stdClass || $data instanceof \Traversable) {
            // Handle members of traversable values.
            foreach ($data as &$value) {
                self::wrapClosures($value, $serializer);
            }
        } elseif (is_object($data) && !$data instanceof \Serializable) {
            // Handle objects that are not already explicitly serializable.
            $reflection = new \ReflectionObject($data);
            if (!$reflection->hasMethod('__sleep')) {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isPrivate() || $property->isProtected()) {
                        $property->setAccessible(true);
                    }
                    $value = $property->getValue($data);
                    self::wrapClosures($value, $serializer);
                    $property->setValue($data, $value);
                }
            }
        }
    }

    /**
     * Calculates a signature for a closure's serialized data.
     *
     * @param string $data Serialized closure data.
     *
     * @return string Signature of the closure's data.
     */
    private function calculateSignature($data)
    {
        return hash_hmac('sha256', $data, $this->signingKey, true);
    }

    /**
     * Verifies the signature for a closure's serialized data.
     *
     * @param string $signature The provided signature of the data.
     * @param string $data      The data for which to verify the signature.
     *
     * @throws ClosureUnserializationException if the signature is invalid.
     */
    private function verifySignature($signature, $data)
    {
        // Ensure that hash_equals() is available.
        static $hashEqualsFnExists = false;
        if (!$hashEqualsFnExists) {
            require __DIR__ . '/hash_equals.php';
            $hashEqualsFnExists = true;
        }

        // Verify that the provided signature matches the calculated signature.
        if (!hash_equals($signature, $this->calculateSignature($data))) {
            throw new ClosureUnserializationException('The signature of the'
                . ' closure\'s data is invalid, which means the serialized '
                . 'closure has been modified and is unsafe to unserialize.'
            );
        }
    }
}
