<?php

namespace Illuminate\Routing;

use Countable;
use ArrayIterator;
use IteratorAggregate;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class RouteCollection implements Countable, IteratorAggregate
{
    /**
     * An array of the routes keyed by method.
     * 格式：
     * [
     *  "GET"=>[
     *    "/url/1" => Illuminate\Routing\Route Object
     *    ...
     *   ]
     *  "POST"=>[
     *     ...
     *   ]
     * ]
     * @var array
     */
    protected $routes = [];


    /**
     * An flattened array of all of the routes.
     *
     * @var array
     */
    protected $allRoutes = [];

    /**
     * A look-up table of routes by their names.
     *
     * @var array
     */
    protected $nameList = [];

    /**
     * A look-up table of routes by controller action.
     *
     * @var array
     */
    protected $actionList = [];

    /**
     * Add a Route instance to the collection.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return \Illuminate\Routing\Route
     */
    public function add(Route $route)
    {
        $this->addToCollections($route);

        $this->addLookups($route);

        return $route;
    }

    /**
     * Add the given route to the arrays of routes.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addToCollections($route)
    {
        $domainAndUri = $route->getDomain().$route->uri();

        foreach ($route->methods() as $method) {
            $this->routes[$method][$domainAndUri] = $route;
        }

        $this->allRoutes[$method.$domainAndUri] = $route;
    }

    /**
     * Add the route to any look-up tables if necessary.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addLookups($route)
    {
        // If the route has a name, we will add it to the name look-up table so that we
        // will quickly be able to find any route associate with a name and not have
        // to iterate through every route every time we need to perform a look-up.
        $action = $route->getAction();

        if (isset($action['as'])) {
            $this->nameList[$action['as']] = $route;
        }

        // When the route is routing to a controller we will also store the action that
        // is used by the route. This will let us reverse route to controllers while
        // processing a request and easily generate URLs to the given controllers.
        if (isset($action['controller'])) {
            $this->addToActionList($action, $route);
        }
    }

    /**
     * Add a route to the controller action dictionary.
     *
     * @param  array  $action
     * @param  \Illuminate\Routing\Route  $route
     * @return void
     */
    protected function addToActionList($action, $route)
    {
        $this->actionList[trim($action['controller'], '\\')] = $route;
    }

    /**
     * Refresh the name look-up table.
     *
     * This is done in case any names are fluently defined or if routes are overwritten.
     *
     * @return void
     */
    public function refreshNameLookups()
    {
        $this->nameList = [];

        foreach ($this->allRoutes as $route) {
            if ($route->getName()) {
                $this->nameList[$route->getName()] = $route;
            }
        }
    }

    /**
     * Refresh the action look-up table.
     *
     * This is done in case any actions are overwritten with new controllers.
     *
     * @return void
     */
    public function refreshActionLookups()
    {
        $this->actionList = [];

        foreach ($this->allRoutes as $route) {
            if (isset($route->getAction()['controller'])) {
                $this->addToActionList($route->getAction(), $route);
            }
        }
    }

    /**
     * Find the first route matching a given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Routing\Route
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    //TODO 根据$request找出最先匹配成功的路由
    public function match(Request $request)
    {
        // TODO  $request->getMethod()流程：
        // TODO  1. 当前 $request 被手动指定的 method
        // TODO  2. 当前请求为非POST，直接使用GET
        // TODO  3. $header['X-HTTP-METHOD-OVERRIDE']
        // TODO  4. post body 中的 _method
        // TODO  5. $query中的 _method
        // TODO  6. _method 为 ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'PATCH', 'PURGE', 'TRACE']之一，合法直接使用
        // TODO  7. _method 不匹配 /^[A-Z]++$/D 抛出异常
        $routes = $this->get($request->getMethod());

        // First, we will see if we can find a matching route for this current request
        // method. If we can, great, we can just return it so that it can be called
        // by the consumer. Otherwise we will check for routes with another verb.
        // 首先，我们将查看是否可以找到与此当前请求匹配的路由方法。 如果可以的话，我们直接返回它，以便消费方调用。
        // 否则，我们将检查另一个 method 动词的路由。
        $route = $this->matchAgainstRoutes($routes, $request);

        //TODO 已成功匹配,绑定$request后返回该route
        if (! is_null($route)) {
            return $route->bind($request);
        }

        // If no route was found we will now check if a matching route is specified by
        // another HTTP verb. If it is we will need to throw a MethodNotAllowed and
        // inform the user agent of which HTTP verb it should use for this route.
        // 如果找不到路由，我们现在将检查是否指定了匹配的路由另一个HTTP动词。
        // 如果是这样，我们将需要抛出MethodNotAllowed和告知用户代理此路由应使用哪个HTTP动词。
        //TODO 检查是否有其他method绑定了当前url
        $others = $this->checkForAlternateVerbs($request);

        if (count($others) > 0) {
            return $this->getRouteForMethods($request, $others);
        }

        throw new NotFoundHttpException;
    }

    /**
     * Determine if a route in the array matches the request.
     *
     * @param  array  $routes
     * @param  \Illuminate\http\Request  $request
     * @param  bool  $includingMethod
     * @return \Illuminate\Routing\Route|null
     */
    protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
    {
        //TODO 切分 $routes 为两组数组 , $fallback为后备组, fallback标记由开发者设置的 ???
        list($fallbacks, $routes) = collect($routes)->partition(function ($route) {
            //TODO Illuminate\Routing\Route $route
            return $route->isFallback;
        });

        //TODO merge之后,$fallbacks会依次在$routes之后,实现fallback目的
        //TODO first依次调用callback,匹配首个返回true的$value
        return $routes->merge($fallbacks)->first(function ($value) use ($request, $includingMethod,&$i ) {
            //TODO Illuminate\Routing\Route $value
            return $value->matches($request, $includingMethod);
        });
    }

    /**
     * Determine if any routes match on another HTTP verb.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function checkForAlternateVerbs($request)
    {
        $methods = array_diff(Router::$verbs, [$request->getMethod()]);

        // Here we will spin through all verbs except for the current request verb and
        // check to see if any routes respond to them. If they do, we will return a
        // proper error response with the correct headers on the response string.
        // 在这里，我们将遍历除了当前请求动词的所有其他动词，检查是否有任何路由对其做出响应。
        // 如果响应，我们将返回一个在响应字符串上包含正确头部的错误响应。
        //TODO 检查是否有除当前$request使用的method之外的其他method绑定了当前url,若有返回其他绑定的method(便于debug)
        $others = [];

        foreach ($methods as $method) {
            if (! is_null($this->matchAgainstRoutes($this->get($method), $request, false))) {
                $others[] = $method;
            }
        }

        return $others;
    }

    /**
     * Get a route (if necessary) that responds when other available methods are present.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $methods
     * @return \Illuminate\Routing\Route
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function getRouteForMethods($request, array $methods)
    {
        //TODO 如果$request method为OPTIONS,则$request希望查看有哪些可用的methods,直接构造路由和处理方法后返回正确响应
        if ($request->method() == 'OPTIONS') {
            return (new Route('OPTIONS', $request->path(), function () use ($methods) {
                return new Response('', 200, ['Allow' => implode(',', $methods)]);
            }))->bind($request);
        }

        $this->methodNotAllowed($methods);
    }

    /**
     * Throw a method not allowed HTTP exception.
     *
     * @param  array  $others
     * @return void
     *
     * @throws \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException
     */
    protected function methodNotAllowed(array $others)
    {
        throw new MethodNotAllowedHttpException($others);
    }

    /**
     * Get routes from the collection by method.
     *
     * @param  string|null  $method
     * @return array
     */
    // TODO 根据method获取route集合
    public function get($method = null)
    {
        //TODO $this->routes 由developer自定的router文件定义并添加到该变量,但是何时执行的 ???
        return is_null($method) ? $this->getRoutes() : Arr::get($this->routes, $method, []);
    }

    /**
     * Determine if the route collection contains a given named route.
     *
     * @param  string  $name
     * @return bool
     */
    public function hasNamedRoute($name)
    {
        return ! is_null($this->getByName($name));
    }

    /**
     * Get a route instance by its name.
     *
     * @param  string  $name
     * @return \Illuminate\Routing\Route|null
     */
    public function getByName($name)
    {
        return $this->nameList[$name] ?? null;
    }

    /**
     * Get a route instance by its controller action.
     *
     * @param  string  $action
     * @return \Illuminate\Routing\Route|null
     */
    public function getByAction($action)
    {
        return $this->actionList[$action] ?? null;
    }

    /**
     * Get all of the routes in the collection.
     *
     * @return array
     */
    public function getRoutes()
    {
        return array_values($this->allRoutes);
    }

    /**
     * Get all of the routes keyed by their HTTP verb / method.
     *
     * @return array
     */
    public function getRoutesByMethod()
    {
        return $this->routes;
    }

    /**
     * Get all of the routes keyed by their name.
     *
     * @return array
     */
    public function getRoutesByName()
    {
        return $this->nameList;
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->getRoutes());
    }

    /**
     * Count the number of items in the collection.
     *
     * @return int
     */
    public function count()
    {
        return count($this->getRoutes());
    }
}
