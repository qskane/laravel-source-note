<?php

namespace Illuminate\Foundation;

use Exception;
use Illuminate\Filesystem\Filesystem;

class PackageManifest
{
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    //TODO 文件系统实例
    public $files;

    /**
     * The base path.
     *
     * @var string
     */
    public $basePath;

    /**
     * The vendor path.
     *
     * @var string
     */
    public $vendorPath;

    /**
     * The manifest path.
     *
     * @var string|null
     */
    public $manifestPath;

    /**
     * The loaded manifest array.
     *
     * @var array
     */
    public $manifest;

    /**
     * Create a new package manifest instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem $files
     * @param  string $basePath
     * @param  string $manifestPath
     * @return void
     */
    public function __construct(Filesystem $files, $basePath, $manifestPath)
    {
        $this->files = $files;
        $this->basePath = $basePath;

        //TODO /bootstrap/cache/packages.php
        $this->manifestPath = $manifestPath;

        $this->vendorPath = $basePath . '/vendor';
    }

    /**
     * Get all of the service provider class names for all packages.
     *
     * @return array
     */
    //TODO 所有provider类名
    public function providers()
    {
        return collect($this->getManifest())->flatMap(function ($configuration, $name) {
            return (array)($configuration['providers'] ?? []);
        })->filter()->all();
    }

    /**
     * Get all of the aliases for all packages.
     *
     * @return array
     */
    //TODO 所有服aliases类名
    public function aliases()
    {
        return collect($this->getManifest())->flatMap(function ($configuration, $name) {
            return (array)($configuration['aliases'] ?? []);
        })->filter()->all();
    }

    /**
     * Get the current package manifest.
     *
     * @return array
     */
    //TODO 获取manifest信息
    protected function getManifest()
    {
        //TODO 单例
        if (!is_null($this->manifest)) {
            return $this->manifest;
        }

        //TODO app首次启动,写入packages缓存
        if (!file_exists($this->manifestPath)) {
            $this->build();
        }

        //TODO 设置packages缓存
        return $this->manifest = file_exists($this->manifestPath) ?
            //TODO require 或未找到
            $this->files->getRequire($this->manifestPath) : [];
    }

    /**
     * Build the manifest and write it to disk.
     *
     * @return void
     */
    public function build()
    {
        $packages = [];

        if ($this->files->exists($path = $this->vendorPath . '/composer/installed.json')) {
            $packages = json_decode($this->files->get($path), true);
        }

        $ignoreAll = in_array('*', $ignore = $this->packagesToIgnore());

        $this->write(collect($packages)->mapWithKeys(function ($package) {
            return [$this->format($package['name']) => $package['extra']['laravel'] ?? []];
        })->each(function ($configuration) use (&$ignore) {
            $ignore += $configuration['dont-discover'] ?? [];
        })->reject(function ($configuration, $package) use ($ignore, $ignoreAll) {
            return $ignoreAll || in_array($package, $ignore);
        })->filter()->all());
    }

    /**
     * Format the given package name.
     *
     * @param  string $package
     * @return string
     */
    protected function format($package)
    {
        return str_replace($this->vendorPath . '/', '', $package);
    }

    /**
     * Get all of the package names that should be ignored.
     *
     * @return array
     */
    protected function packagesToIgnore()
    {
        if (!file_exists($this->basePath . '/composer.json')) {
            return [];
        }

        return json_decode(file_get_contents(
                $this->basePath . '/composer.json'
            ), true)['extra']['laravel']['dont-discover'] ?? [];
    }

    /**
     * Write the given manifest array to disk.
     *
     * @param  array $manifest
     * @return void
     * @throws \Exception
     */
    protected function write(array $manifest)
    {
        if (!is_writable(dirname($this->manifestPath))) {
            throw new Exception('The ' . dirname($this->manifestPath) . ' directory must be present and writable.');
        }

        $this->files->put(
            $this->manifestPath, '<?php return ' . var_export($manifest, true) . ';'
        );
    }
}
