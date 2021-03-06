<?php

namespace Doctrine\ODM\CouchDB\Proxy;

use Doctrine\ODM\CouchDB\DocumentManager;
use Doctrine\ODM\CouchDB\Mapping\ClassMetadata;
use Doctrine\Common\Util\ClassUtils;

/**
 * This factory is used to create proxy objects for entities at runtime.
 *
 * @author Roman Borschel <roman@code-factory.org>
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 *
 * This whole thing is copy & pasted from ORM - should really be slightly
 * refactored to generate
 */
class ProxyFactory
{
    /** The DocumentManager this factory is bound to. */
    private $dm;
    /** Whether to automatically (re)generate proxy classes. */
    private $autoGenerate;
    /** The namespace that contains all proxy classes. */
    private $proxyNamespace;
    /** The directory that contains all proxy classes. */
    private $proxyDir;

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>DocumentManager</tt>.
     *
     * @param DocumentManager $dm The DocumentManager the new factory works for.
     * @param string $proxyDir The directory to use for the proxy classes. It must exist.
     * @param string $proxyNs The namespace to use for the proxy classes.
     * @param boolean $autoGenerate Whether to automatically generate proxy classes.
     * @throws ProxyException
     */
    public function __construct(DocumentManager $dm, $proxyDir, $proxyNs, $autoGenerate = false)
    {
        if ( ! $proxyDir) {
            throw ProxyException::proxyDirectoryRequired();
        }
        if ( ! $proxyNs) {
            throw ProxyException::proxyNamespaceRequired();
        }
        $this->dm = $dm;
        $this->proxyDir = $proxyDir;
        $this->autoGenerate = $autoGenerate;
        $this->proxyNamespace = $proxyNs;
    }

    /**
     * Gets a reference proxy instance for the entity of the given type and identified by
     * the given identifier.
     *
     * @param string $className
     * @param mixed $identifier
     * @return object
     */
    public function getProxy($className, $identifier)
    {
        $fqn = ClassUtils::generateProxyClassName($className, $this->proxyNamespace);

        if ( ! class_exists($fqn, false)) {
            $fileName = $this->getProxyFileName($className);
            if ($this->autoGenerate) {
                $this->generateProxyClass($this->dm->getClassMetadata($className), $fileName, self::$proxyClassTemplate);
            }
            require $fileName;
        }

        if ( ! $this->dm->getMetadataFactory()->hasMetadataFor($fqn)) {
            $this->dm->getMetadataFactory()->setMetadataFor($fqn, $this->dm->getClassMetadata($className));
        }

        return new $fqn($this->dm, $identifier);
    }

    /**
     * Generate the Proxy file name
     *
     * @param string $className
     * @param string $baseDir Optional base directory for proxy file name generation.
     *                        If not specified, the directory configured on the Configuration of the
     *                        EntityManager will be used by this factory.
     * @return string
     */
    private function getProxyFileName($className, $baseDir = null)
    {
        $proxyDir = $baseDir ?: $this->proxyDir;

        return $proxyDir . DIRECTORY_SEPARATOR . '__CG__' . str_replace('\\', '', $className) . '.php';
    }

    /**
     * Generates proxy classes for all given classes.
     *
     * @param array $classes The classes (ClassMetadata instances) for which to generate proxies.
     * @param string $toDir The target directory of the proxy classes. If not specified, the
     *                      directory configured on the Configuration of the DocumentManager used
     *                      by this factory is used.
     */
    public function generateProxyClasses(array $classes, $toDir = null)
    {
        $proxyDir = $toDir ?: $this->proxyDir;
        $proxyDir = rtrim($proxyDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($classes as $class) {
            /* @var $class ClassMetadata */
            if ($class->isMappedSuperclass) {
                continue;
            }

            $proxyFileName = $this->getProxyFileName($class->name, $toDir);
            $this->generateProxyClass($class, $proxyFileName, self::$proxyClassTemplate);
        }
    }

    /**
     * Generates a proxy class file.
     *
     * @param $class
     * @param $fileName
     * @param $template
     */
    private function generateProxyClass($class, $fileName, $template)
    {
        $methods = $this->generateMethods($class);
        $sleepImpl = $this->generateSleep($class);

        $placeholders = array(
            '<namespace>',
            '<proxyClassName>', '<className>',
            '<methods>', '<sleepImpl>'
        );

        $className = ltrim($class->name, '\\');
        $proxyClassName = ClassUtils::generateProxyClassName($class->name, $this->proxyNamespace);
        $parts = explode('\\', strrev($proxyClassName), 2);
        $proxyClassNamespace = strrev($parts[1]);
        $proxyClassName = strrev($parts[0]);

        $replacements = array(
            $proxyClassNamespace,
            $proxyClassName,
            $className,
            $methods,
            $sleepImpl
        );

        $template = str_replace($placeholders, $replacements, $template);

        file_put_contents($fileName, $template, LOCK_EX);
    }

    /**
     * Generates the methods of a proxy class.
     *
     * @param ClassMetadata $class
     * @return string The code of the generated methods.
     */
    private function generateMethods(ClassMetadata $class)
    {
        $methods = '';

        foreach ($class->reflClass->getMethods() as $method) {
            /* @var $method \ReflectionMethod */
            if ($method->isConstructor() || strtolower($method->getName()) == "__sleep") {
                continue;
            }

            if ($method->isPublic() && ! $method->isFinal() && ! $method->isStatic()) {
                $methods .= PHP_EOL . '    public function ';
                if ($method->returnsReference()) {
                    $methods .= '&';
                }
                $methods .= $method->getName() . '(';
                $firstParam = true;
                $parameterString = $argumentString = '';

                foreach ($method->getParameters() as $param) {
                    if ($firstParam) {
                        $firstParam = false;
                    } else {
                        $parameterString .= ', ';
                        $argumentString  .= ', ';
                    }

                    // We need to pick the type hint class too
                    if (($paramClass = $param->getClass()) !== null) {
                        $parameterString .= '\\' . $paramClass->getName() . ' ';
                    } else if ($param->isArray()) {
                        $parameterString .= 'array ';
                    }

                    if ($param->isPassedByReference()) {
                        $parameterString .= '&';
                    }

                    $parameterString .= '$' . $param->getName();
                    $argumentString  .= '$' . $param->getName();

                    if ($param->isDefaultValueAvailable()) {
                        $parameterString .= ' = ' . var_export($param->getDefaultValue(), true);
                    }
                }

                $methods .= $parameterString . ')';
                $methods .= PHP_EOL . '    {' . PHP_EOL;
                $methods .= '        $this->__load();' . PHP_EOL;
                $methods .= '        return parent::' . $method->getName() . '(' . $argumentString . ');';
                $methods .= PHP_EOL . '    }' . PHP_EOL;
            }
        }

        return $methods;
    }

    /**
     * Generates the code for the __sleep method for a proxy class.
     *
     * @param $class
     * @return string
     */
    private function generateSleep(ClassMetadata $class)
    {
        $sleepImpl = '';

        if ($class->reflClass->hasMethod('__sleep')) {
            $sleepImpl .= "return array_merge(array('__isInitialized__'), parent::__sleep());";
        } else {
            $sleepImpl .= "return array('__isInitialized__', ";

            $properties = array();
            foreach ($class->fieldMappings as $name => $prop) {
                $properties[] = "'$name'";
            }

            $sleepImpl .= implode(',', $properties) . ');';
        }

        return $sleepImpl;
    }

    /** Proxy class code template */
    private static $proxyClassTemplate = <<<'PHP'
<?php

namespace <namespace>;

/**
 * THIS CLASS WAS GENERATED BY THE DOCTRINE ORM. DO NOT EDIT THIS FILE.
 */
class <proxyClassName> extends \<className> implements \Doctrine\ODM\CouchDB\Proxy\Proxy
{
    private $__doctrineDocumentManager__;
    private $__doctrineIdentifier__;
    public $__isInitialized__ = false;
    public function __construct($documentManager, $identifier)
    {
        $this->__doctrineDocumentManager__ = $documentManager;
        $this->__doctrineIdentifier__ = $identifier;
    }
    public function __load()
    {
        if (!$this->__isInitialized__ && $this->__doctrineDocumentManager__) {
            $this->__isInitialized__ = true;
            $this->__doctrineDocumentManager__->refresh($this);
            unset($this->__doctrineDocumentManager__, $this->__doctrineIdentifier__);
        }
    }

    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    <methods>

    public function __sleep()
    {
        <sleepImpl>
    }
}
PHP;
}

