<?php

namespace atk4\api;

/**
 * Main API class.
 */
class Api
{
    /** @var \Zend\Diactoros\ServerRequest Request object */
    public $request;

    /** @var \Zend\Diactoros\Response\JsonResponse Response object */
    public $response;

    /** @var \Zend\Diactoros\Response\SapiEmitter Emitter object */
    public $emitter;

    /** @var string */
    protected $requestData;

    /** @var string Request path */
    public $path;

    /**
     * Reads everything off globals.
     *
     * @param \Zend\Diactoros\ServerRequest $request
     */
    public function __construct($request = null)
    {
        $this->request = $request ?: \Zend\Diactoros\ServerRequestFactory::fromGlobals();
        $this->path = $this->request->getUri()->getPath();

        if (isset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI'])) {
            // both script name and request uri are supplied, possibly
            // we would want to extract path relative from script location

            $script = $_SERVER['SCRIPT_NAME'];
            $path = $_SERVER['REQUEST_URI'];

            $this->path = str_replace($script, '', $path);
        }

        $ct = $this->request->getHeader('Content-Type');

        if ($ct && strtolower($ct[0]) == 'application/json') {
            $this->requestData = json_decode($this->request->getBody()->getContents(), true);
        } else {
            $this->requestData = $this->request->getParsedBody();
        }

        // This is how we will send responses
        $this->emitter = new \Zend\Diactoros\Response\SapiEmitter();
    }

    /**
     * Do pattern matching and save extracted variables.
     *
     * @param string $pattern
     *
     * @return bool
     */
    protected $_vars;

    public function match($pattern)
    {
        $path = explode('/', rtrim($this->path, '/'));
        $pattern = explode('/', rtrim($pattern, '/'));

        $this->_vars = [];

        while ($path || $pattern) {
            $p = array_shift($path);
            $r = array_shift($pattern);

            // if path ends and there is nothing in pattern (used //) then continue
            if ($p === null && $r === '') {
                continue;
            }

            // if both match, then continue
            if ($p === $r) {
                continue;
            }

            // pattern '*' accepts anything
            if ($r == '*' && strlen($p)) {
                continue;
            }

            // if pattern ends, but there is still something in path, then don't match
            if ($r === null || $r === '') {
                return false;
            }

            // parameters always start with ':', save in $vars and continue
            if ($r[0] == ':' && strlen($p)) {
                $this->_vars[] = $p;
                continue;
            }

            // pattern '**' = good until the end
            if ($r == '**') {
                break;
            }

            return false;
        }

        return true;
    }

    /**
     * Call callable and emit response.
     *
     * @param callable $callable
     * @param array    $vars
     */
    public function exec($callable, $vars = [])
    {
        // try to call callable function
        $ret = $this->call($callable, $vars);

        // if callable function returns agile data model, then export it
        // this is important for REST API implementation
        if ($ret instanceof \atk4\data\Model) {
            $ret = $this->exportModel($ret);
        }

        // no response, just step out
        if ($ret === null) {
            return;
        }

        // emit successful response
        $this->successResponse($ret);
    }

    /**
     * Call callable and return response.
     *
     * @param callable $callable
     * @param array    $vars
     *
     * @return mixed
     */
    protected function call($callable, $vars = [])
    {
        // try to call callable function
        try {
            $ret = call_user_func_array($callable, $vars);
        } catch (\Exception $e) {
            $this->caughtException($e);
        }

        return $ret;
    }

    /**
     * Exports data model.
     *
     * Extend this method to implement your own field restrictions.
     *
     * @param \atk4\data\Model $m
     *
     * @return array
     */
    protected function exportModel(\atk4\data\Model $m)
    {
        return $m->export($this->getAllowedFields($m, 'read'));
    }

    /**
     * Returns list of model field names which allow particular action - read or modify.
     * Also takes model->only_fields into account if that's defined.
     *
     * It uses custom model property apiFields[$action] which should contain array of allowed field names.
     *
     * @param \atk4\data\Model $m
     * @param string           $action read|modify
     *
     * @return null|array of field names
     */
    protected function getAllowedFields(\atk4\data\Model $m, $action = 'read')
    {
        $fields = null;

        // take model only_fields into account
        if ($m->only_fields) {
            $fields = $m->only_fields;
        }

        // limit by apiFields
        if (isset($m->apiFields[$action])) {
            $allowed = $m->apiFields[$action];
            $fields = $fields ? array_intersect($fields, $allowed) : $allowed;
        }

        return $fields;
    }

    /**
     * Filters data array by only allowed fields.
     *
     * Extend this method to implement your own field restrictions.
     *
     * @param \atk4\data\Model $m
     * @param array            $data
     *
     * @return array
     */
    /* not used and maybe will not be needed too
    protected function filterData(\atk4\data\Model $m, array $data)
    {
        $allowed = $this->getAllowedFields($m, 'modify');

        if ($allowed) {
            $data = array_intersect_key($data, array_flip($allowed));
        }

        return $data;
    }
    */

    /**
     * Emit successful response.
     *
     * @param mixed $response
     */
    protected function successResponse($response)
    {
        // create response object
        if (!$this->response) {
            $this->response =
                new \Zend\Diactoros\Response\JsonResponse(
                    $response,
                    200,
                    [],
                    JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                );
        }

        // if there is emitter, then emit response and exit
        // for testing purposes there can be situations when emitter is disabled. then do nothing.
        if ($this->emitter) {
            $this->emitter->emit($this->response);
            exit;
        }

        // @todo Should we also stop script execution if no emitter is defined or just ignore that?
        //exit;
    }

    /**
     * Do GET pattern matching.
     *
     * @param string   $pattern
     * @param callable $callable
     *
     * @return mixed
     */
    public function get($pattern, $callable = null)
    {
        if ($this->request->getMethod() === 'GET' && $this->match($pattern)) {
            return $this->exec($callable, $this->_vars);
        }
    }

    /**
     * Do POST pattern matching.
     *
     * @param string   $pattern
     * @param callable $callable
     *
     * @return mixed
     */
    public function post($pattern, $callable = null)
    {
        if ($this->request->getMethod() === 'POST' && $this->match($pattern)) {
            return $this->exec($callable, $this->_vars);
        }
    }

    /**
     * Do PATCH pattern matching.
     *
     * @param string   $pattern
     * @param callable $callable
     *
     * @return mixed
     */
    public function patch($pattern, $callable = null)
    {
        if ($this->request->getMethod() === 'PATCH' && $this->match($pattern)) {
            return $this->exec($callable, $this->_vars);
        }
    }

    /**
     * Do DELETE pattern matching.
     *
     * @param string   $pattern
     * @param callable $callable
     *
     * @return mixed
     */
    public function delete($pattern, $callable = null)
    {
        if ($this->request->getMethod() === 'DELETE' && $this->match($pattern)) {
            return $this->exec($callable, $this->_vars);
        }
    }

    /**
     * Implement REST pattern matching.
     *
     * @param string                    $pattern
     * @param \atk4\data\Model|callable $model
     *
     * @return mixed
     */
    public function rest($pattern, $model = null)
    {
        // GET all records
        $f = function () use ($model) {
            $args = func_get_args();

            if (is_callable($model)) {
                $model = $this->call($model, $args);
            }

            return $model;
        };
        $this->get($pattern, $f);

        // GET :id - one record
        $f = function () use ($model) {
            $args = func_get_args();
            $id = array_pop($args); // pop last element of args array, it's :id

            if (is_callable($model)) {
                $model = $this->call($model, $args);
            }

            // limit fields
            $model->onlyFields($this->getAllowedFields($model, 'read'));

            return $model->load($id)->get();
        };
        $this->get($pattern.'/:id', $f);

        // POST :id - update one record
        // PATCH :id - update one record (same as POST :id)
        $f = function () use ($model) {
            $args = func_get_args();
            $id = array_pop($args); // pop last element of args array, it's :id

            if (is_callable($model)) {
                $model = $this->call($model, $args);
            }

            // limit fields
            $model->onlyFields($this->getAllowedFields($model, 'modify'));
            $model->load($id)->save($this->requestData);
            $model->onlyFields($this->getAllowedFields($model, 'read'));

            return $model->get();
        };
        $this->patch($pattern.'/:id', $f);
        $this->post($pattern.'/:id', $f);

        // POST - insert new record
        $f = function () use ($model) {
            $args = func_get_args();

            if (is_callable($model)) {
                $model = $this->call($model, $args);
            }

            // limit fields
            $model->onlyFields($this->getAllowedFields($model, 'modify'));
            $model->unload()->save($this->requestData);
            $model->onlyFields($this->getAllowedFields($model, 'read'));

            return $model->get();
        };
        $this->post($pattern, $f);

        // DELETE :id - delete one record
        $f = function () use ($model) {
            $args = func_get_args();
            $id = array_pop($args); // pop last element of args array, it's :id

            if (is_callable($model)) {
                $model = $this->call($model, $args);
            }

            // limit fields (not necessary, but will limit field list for performance)
            $model->onlyFields($this->getAllowedFields($model, 'read'));

            return !$model->delete($id)->loaded();
        };
        $this->delete($pattern.'/:id', $f);
    }

    /**
     * Our own exception handling.
     *
     * @param \Exception $e
     */
    public function caughtException(\Exception $e)
    {
        $params = [];
        foreach ($e->getParams() as $key => $val) {
            $params[$key] = $e->toString($val);
        }

        $this->response =
            new \Zend\Diactoros\Response\JsonResponse(
                [
                    'error'=> [
                        'message'=> $e->getMessage(),
                        'args'   => $params,
                    ],
                ],
                $e->getCode() ?: 500,
                [],
                JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            );

        $emitter = new \Zend\Diactoros\Response\SapiEmitter();
        $emitter->emit($this->response);
        exit;
    }
}
