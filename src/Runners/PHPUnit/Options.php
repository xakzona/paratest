<?php

declare(strict_types=1);

namespace ParaTest\Runners\PHPUnit;

/**
 * Class Options.
 *
 * An object containing all configurable information used
 * to run PHPUnit via ParaTest
 */
class Options
{
    /**
     * The number of processes to run at a time.
     *
     * @var int
     */
    protected $processes;

    /**
     * The test path pointing to tests that will
     * be run.
     *
     * @var string
     */
    protected $path;

    /**
     * The path to the PHPUnit binary that will be run.
     *
     * @var string
     */
    protected $phpunit;

    /**
     * Determines whether or not ParaTest runs in
     * functional mode. If enabled, ParaTest will run
     * every test method in a separate process.
     *
     * @var string
     */
    protected $functional;

    /**
     * Prevents starting new tests after a test has failed.
     *
     * @var bool
     */
    protected $stopOnFailure;

    /**
     * A collection of post-processed option values. This is the collection
     * containing ParaTest specific options.
     *
     * @var array
     */
    protected $filtered;

    /**
     * @var string
     */
    protected $runner;

    /**
     * @var bool
     */
    protected $noTestTokens;

    /**
     * @var bool
     */
    protected $colors;

    /**
     * Filters which tests to run.
     *
     * @var string|null
     */
    protected $testsuite;

    /**
     * @var int|null
     */
    protected $maxBatchSize;

    /**
     * @var string
     */
    protected $filter;

    /**
     * @var string[]
     */
    protected $groups;

    /**
     * @var string[]
     */
    protected $excludeGroups;

    /**
     * A collection of option values directly corresponding
     * to certain annotations - i.e group.
     *
     * @var array
     */
    protected $annotations = [];

    public function __construct(array $opts = [])
    {
        foreach (self::defaults() as $opt => $value) {
            $opts[$opt] = $opts[$opt] ?? $value;
        }

        $this->processes = $opts['processes'];
        $this->path = $opts['path'];
        $this->phpunit = $opts['phpunit'];
        $this->functional = $opts['functional'];
        $this->stopOnFailure = $opts['stop-on-failure'];
        $this->runner = $opts['runner'];
        $this->noTestTokens = $opts['no-test-tokens'];
        $this->colors = $opts['colors'];
        $this->testsuite = $opts['testsuite'];
        $this->maxBatchSize = (int) $opts['max-batch-size'];
        $this->filter = $opts['filter'];

        // we need to register that options if they are blank but do not get them as
        // key with null value in $this->filtered as it will create problems for
        // phpunit command line generation (it will add them in command line with no value
        // and it's wrong because group and exclude-group options require value when passed
        // to phpunit)
        $this->groups = isset($opts['group']) && $opts['group'] !== ''
                      ? explode(',', $opts['group'])
                      : [];
        $this->excludeGroups = isset($opts['exclude-group']) && $opts['exclude-group'] !== ''
                             ? explode(',', $opts['exclude-group'])
                             : [];

        if (isset($opts['filter']) && strlen($opts['filter']) > 0 && !$this->functional) {
            throw new \RuntimeException('Option --filter is not implemented for non functional mode');
        }

        $this->filtered = $this->filterOptions($opts);
        $this->initAnnotations();
    }

    /**
     * Public read accessibility.
     *
     * @param string $var
     *
     * @return mixed
     */
    public function __get(string $var)
    {
        return $this->{$var};
    }

    /**
     * Public read accessibility
     * (e.g. to make empty($options->property) work as expected).
     *
     * @param string $var
     *
     * @return mixed
     */
    public function __isset(string $var): bool
    {
        return isset($this->{$var});
    }

    /**
     * Returns a collection of ParaTest's default
     * option values.
     *
     * @return array
     */
    protected static function defaults(): array
    {
        return [
            'processes' => 5,
            'path' => '',
            'phpunit' => static::phpunit(),
            'functional' => false,
            'stop-on-failure' => false,
            'runner' => 'Runner',
            'no-test-tokens' => false,
            'colors' => false,
            'testsuite' => '',
            'max-batch-size' => 0,
            'filter' => null,
        ];
    }

    /**
     * Get the path to phpunit
     * First checks if a Windows batch script is in the composer vendors directory.
     * Composer automatically handles creating a .bat file, so if on windows this should be the case.
     * Second look for the phpunit binary under nix
     * Defaults to phpunit on the users PATH.
     *
     * @return string $phpunit the path to phpunit
     */
    protected static function phpunit(): string
    {
        $vendor = static::vendorDir();

        $phpunit = $vendor . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        $batch = $phpunit . '.bat';

        if (DIRECTORY_SEPARATOR === '\\' && file_exists($batch)) {
            return $phpunit . '.bat';
        } elseif (file_exists($phpunit)) {
            return $phpunit;
        }

        return 'phpunit';
    }

    /**
     * Get the path to the vendor directory
     * First assumes vendor directory is accessible from src (i.e development)
     * Second assumes vendor directory is accessible within src.
     */
    protected static function vendorDir(): string
    {
        $vendor = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'vendor';
        if (!file_exists($vendor)) {
            $vendor = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        }

        return $vendor;
    }

    /**
     * Filter options to distinguish between paratest
     * internal options and any other options.
     */
    protected function filterOptions(array $options): array
    {
        $filtered = array_diff_key($options, [
            'processes' => $this->processes,
            'path' => $this->path,
            'phpunit' => $this->phpunit,
            'functional' => $this->functional,
            'stop-on-failure' => $this->stopOnFailure,
            'runner' => $this->runner,
            'no-test-tokens' => $this->noTestTokens,
            'colors' => $this->colors,
            'testsuite' => $this->testsuite,
            'max-batch-size' => $this->maxBatchSize,
            'filter' => $this->filter,
        ]);
        if ($configuration = $this->getConfigurationPath($filtered)) {
            $filtered['configuration'] = new Configuration($configuration);
        }

        return $filtered;
    }

    /**
     * Take an array of filtered options and return a
     * configuration path.
     *
     * @param $filtered
     *
     * @return string|null
     */
    protected function getConfigurationPath(array $filtered)
    {
        if (isset($filtered['configuration'])) {
            return $this->getDefaultConfigurationForPath($filtered['configuration'], $filtered['configuration']);
        }

        return $this->getDefaultConfigurationForPath();
    }

    /**
     * Retrieve the default configuration given a path (directory or file).
     * This will search into the directory, if a directory is specified.
     *
     * @param string $path    The path to search into
     * @param string $default The default value to give back
     *
     * @return string|null
     */
    private function getDefaultConfigurationForPath(string $path = '.', string $default = null)
    {
        if ($this->isFile($path)) {
            return realpath($path);
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $suffixes = ['phpunit.xml', 'phpunit.xml.dist'];

        foreach ($suffixes as $suffix) {
            if ($this->isFile($path . $suffix)) {
                return realpath($path . $suffix);
            }
        }

        return $default;
    }

    /**
     * Load options that are represented by annotations
     * inside of tests i.e @group group1 = --group group1.
     */
    protected function initAnnotations()
    {
        $annotatedOptions = ['group'];
        foreach ($this->filtered as $key => $value) {
            if (array_search($key, $annotatedOptions, true) !== false) {
                $this->annotations[$key] = $value;
            }
        }
    }

    /**
     * @param $file
     *
     * @return bool
     */
    private function isFile(string $file): bool
    {
        return file_exists($file) && !is_dir($file);
    }
}
