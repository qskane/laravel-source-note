<?php

namespace Illuminate\Pipeline;

use Closure;
use RuntimeException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pipeline\Pipeline as PipelineContract;

class Pipeline implements PipelineContract
{
    /**
     * The container implementation.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The object being passed through the pipeline.
     *
     * @var mixed
     */
    protected $passable;

    /**
     * The array of class pipes.
     *
     * @var array
     */
    protected $pipes = [];

    /**
     * The method to call on each pipe.
     *
     * @var string
     */
    protected $method = 'handle';

    /**
     * Create a new class instance.
     *
     * @param  \Illuminate\Contracts\Container\Container|null $container
     * @return void
     */
    public function __construct(Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Set the object being sent through the pipeline.
     *
     * @param  mixed $passable
     * @return $this
     */
    public function send($passable)
    {
        $this->passable = $passable;

        return $this;
    }

    /**
     * Set the array of pipes.
     *
     * @param  array|mixed $pipes
     * @return $this
     */
    public function through($pipes)
    {
        $this->pipes = is_array($pipes) ? $pipes : func_get_args();

        return $this;
    }

    /**
     * Set the method to call on the pipes.
     *
     * @param  string $method
     * @return $this
     */
    public function via($method)
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Run the pipeline with a final destination callback.
     *
     * @param  \Closure $destination
     * @return mixed
     */
    public function then(Closure $destination)
    {
        // TODO 将中间件作为pipeline，依次处理

        $pipeline = array_reduce(
        //TODO reverse是因为由carry封装后的闭包调用顺序相反
            array_reverse($this->pipes),
            //TODO 需要经过的每个pipe适配为传入合适参数的闭包
            $this->carry(),
            //TODO 处理最终目的地为闭包,适配需要的参数
            $this->prepareDestination($destination)
        );

        // TODO 此时调用的pipeline为 $this->pipes 中的最后一个pipe即第一个middleware，也是为何需要先将pipes reverse的原因
        return $pipeline($this->passable);
    }

    /**
     * Get the final piece of the Closure onion.
     *
     * @param  \Closure $destination
     * @return \Closure
     */
    //TODO 适配pipe最终目的地为闭包形式,作用是为了和middleware形式统一
    protected function prepareDestination(Closure $destination)
    {
        return function ($passable) use ($destination) {
            return $destination($passable);
        };
    }

    /**
     * Get a Closure that represents a slice of the application onion.
     *
     * @return \Closure
     */
    protected function carry()
    {
        return function ($stack, $pipe) {
            //TODO $passable 为 $request
            //TODO $stack 下一个需要执行的pipe闭包，即middleware中handle方法的$next变量
            //TODO $pipe 此次需要通过的pipe
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    // If the pipe is an instance of a Closure, we will just call it directly but
                    // otherwise we'll resolve the pipes out of the container and call it with
                    // the appropriate method and arguments, returning the results back out.
                    // 如果管道是Closure的实例，我们将直接调用它，
                    // 否则，我们将解析容器中的管道，并使用适当的方法和参数，返回结果。
                    return $pipe($passable, $stack);
                } elseif (!is_object($pipe)) {

                    // TODO $name为middleware class name， $parameters为额外参数数组
                    list($name, $parameters) = $this->parsePipeString($pipe);

                    // If the pipe is a string we will parse the string and resolve the class out
                    // of the dependency injection container. We can then build a callable and
                    // execute the pipe function giving in the parameters that are required.
                    // 如果管道是字符串，我们将解析该字符串并从依赖项注入容器中解析该类。
                    // 然后，我们可以构建一个可调用的执行管道功能，输入所需的参数。
                    $pipe = $this->getContainer()->make($name);

                    $parameters = array_merge([$passable, $stack], $parameters);
                } else {
                    // If the pipe is already an object we'll just make a callable and pass it to
                    // the pipe as-is. There is no need to do any extra parsing and formatting
                    // since the object we're given was already a fully instantiated object.
                    // 如果管道已经是一个对象，我们将使其可调用并将其保持原样传递给管道。
                    // 无需进行任何额外的解析和格式化，因为我们得到的对象已经是完全实例化的对象。
                    $parameters = [$passable, $stack];
                }

                //TODO 执行pipe的具体方法,参数形式: $request,$next,$parameter1,$parameter2...
                return method_exists($pipe, $this->method)
                    ? $pipe->{$this->method}(...$parameters)
                    : $pipe(...$parameters);
            };
        };
    }

    /**
     * Parse full pipe string to get name and parameters.
     *
     * @param  string $pipe
     * @return array
     */
    protected function parsePipeString($pipe)
    {
        //TODO 如果$pipe 为$name:$parameters形式,则解析,否则 $parameters=[]
        list($name, $parameters) = array_pad(explode(':', $pipe, 2), 2, []);

        //TODO $parameters 允许多个参数使用","分割: a,b,c
        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }

    /**
     * Get the container instance.
     *
     * @return \Illuminate\Contracts\Container\Container
     * @throws \RuntimeException
     */
    protected function getContainer()
    {
        if (!$this->container) {
            throw new RuntimeException('A container instance has not been passed to the Pipeline.');
        }

        return $this->container;
    }
}
