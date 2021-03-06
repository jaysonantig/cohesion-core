<?php
namespace Cohesion\Route;

use \Cohesion\Config\Configurable;
use \Cohesion\Config\Config;

class Route implements Configurable {

    protected $uri;
    protected $config;

    protected $redirect;
    protected $className;
    protected $functionName;
    protected $params;

    public function __construct(Config $config, $uri = null) {
        $this->uri = $uri;
        $this->config = $config;
        if (!$redirect = $this->getRedirect()) {
            try {
                $this->setByDefaultRoute();
            } catch (RouteException $e) {
                // No need to do anything. Should always be checking if the className is set
            }
        }
    }

    protected function setByDefaultRoute() {
        if (preg_match('/\.\./', $this->uri)) {
            throw new RouteException("Invalid URI {$this->uri}");
        }
        $components = explode('/', ltrim(preg_replace('/\/+/', '/', $this->uri), '/'));
        $defaultClassName = $this->constructClassName($this->config->get('class.default'));
        $className = $defaultClassName;
        $functionName = null;
        $params = array();
        $dir = BASE_DIR . $this->config->get('directory') . DIRECTORY_SEPARATOR;
        $ext = '.' . $this->config->get('extension', 'php');
        foreach ($components as $component) {
            if (!$functionName) {
                if ($className === $defaultClassName) {
                    $directory = $dir . $component;
                    if (file_exists($directory) && is_dir($directory)) {
                        $dir = $directory . DIRECTORY_SEPARATOR;
                        continue;
                    }
                    $checkClassName = $this->constructClassName($component);
                    $filePath = $dir . $checkClassName . $ext;
                    if (file_exists($filePath)) {
                        include_once($filePath);
                        if (!class_exists($checkClassName)) {
                            throw new RouteException("$filePath doesn't contain a $checkClassName class");
                        }
                        $className = $checkClassName;
                        continue;
                    } else {
                        // As soon as there's no file or folder that matches, load the default controller
                        $filePath = $dir . $className . $ext;
                        if (!file_exists($filePath)) {
                            throw new RouteException("Default controller $className cannot be found");
                        }
                        include_once($filePath);
                        if (!class_exists($className)) {
                            throw new RouteException("$filePath doesn't contain a $className class");
                        }
                    }
                }
                $checkFunctionName = $this->constructFunctionName($component);
                if (method_exists($className, $checkFunctionName)) {
                    $functionName = $checkFunctionName;
                } else if ($className !== $defaultClassName) {
                    $functionName = $this->constructFunctionName($this->config->get('function.default'));
                    if (method_exists($className, $functionName)) {
                        $params[] = $component;
                    } else {
                        throw new RouteException("$className doesn't have a $functionName function");
                    }
                }
            } else {
                $params[] = $component;
            }
        }
        if (!$functionName) {
            $defaultFunction = $this->constructFunctionName($this->config->get('function.default'));
            if ($className === $defaultClassName) {
                $filePath = $dir . $className . $ext;
                if (!file_exists($filePath)) {
                    throw new RouteException("Default controller $className cannot be found");
                }
                include_once($filePath);
                if (!class_exists($className)) {
                    throw new RouteException("$filePath doesn't contain a $className class");
                }
            }
            if (method_exists($className, $defaultFunction)) {
                $functionName = $defaultFunction;
                if ($className == $defaultClassName) {
                    if ($components[0] != '') {
                        $params = $components;
                    }
                }
            } else {
                throw new RouteException("$className doesn't have an $defaultFunction function");
            }
        }
        $reflection = new \ReflectionMethod($className, $functionName);
        $minParams = $reflection->getNumberOfRequiredParameters();
        $maxParams = $reflection->getNumberOfParameters();
        if (count($params) < $minParams) {
            throw new RouteException("$className->$functionName requires at least $minParams parameters");
        } else if (count($params) > $maxParams) {
            throw new RouteException("$className->$functionName only accepts up to $maxParams parameters");
        }
        foreach ($params as &$param) {
            $param = urldecode($param);
        }

        $this->className = $className;
        $this->functionName = $functionName;
        $this->params = $params;
    }

    public function getRedirect() {
        if (!isset($this->redirect)) {
            $redirects = $this->config->get('redirects');
            foreach ($redirects as $regex => $location) {
                if (preg_match("!$regex!", $this->uri)) {
                    $this->redirect = $location;
                    return $location;
                }
            }
        }
        return $this->redirect;
    }

    public function getUri() {
        return $this->uri;
    }

    public function getClassName() {
        return $this->className;
    }

    public function getFunctionName() {
        return $this->functionName;
    }

    public function getParameterValues() {
        return $this->params;
    }

    protected function constructClassName($name) {
        $words = explode('-', $name);
        $name = '';
        foreach ($words as $word) {
            $name .= ucfirst($word);
        }
        return $this->config->get('class.prefix') . $name . $this->config->get('class.suffix');
    }

    protected function constructFunctionName($name) {
        $prefix = $this->config->get('function.prefix');
        $words = explode('-', $name);
        $name = '';
        if (!$prefix) {
            $name = lcfirst(array_shift($words));
        }
        foreach ($words as $word) {
            $name .= ucfirst($word);
        }
        return $prefix . $name . $this->config->get('function.suffix');
    }
}

class RouteException extends \RuntimeException {}
