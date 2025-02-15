<?php

namespace PHPCensor\Plugin;

use Exception;
use PHPCensor\Builder;
use PHPCensor\Common\Exception\RuntimeException;
use PHPCensor\Model\Build;
use PHPCensor\Model\BuildError;
use PHPCensor\Plugin;
use SebastianBergmann\Diff\Diff;
use SebastianBergmann\Diff\Line;
use SebastianBergmann\Diff\Parser;

/**
 * PHP CS Fixer - Works with the PHP Coding Standards Fixer for testing coding standards.
 *
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Gabriel Baker <gabriel@autonomicpilot.co.uk>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class PhpCsFixer extends Plugin
{
    protected $args = '';

    protected $config  = false;
    protected $configs = [];

    protected $errors       = false;
    protected $reportErrors = false;

    /**
     * @var int
     */
    protected $allowedWarnings;

    /**
     * @var bool
     */
    protected $supportsUdiff = false;

    /**
     * @var string|null
     */
    protected $version;

    /**
     * @return string
     */
    public static function pluginName()
    {
        return 'php_cs_fixer';
    }

    /**
     * {@inheritdoc}
     */
    public function __construct(Builder $builder, Build $build, array $options = [])
    {
        parent::__construct($builder, $build, $options);

        if (!empty($options['args'])) {
            $this->args = $options['args'];
        }

        $this->executable = $this->findBinary(['php-cs-fixer', 'php-cs-fixer.phar']);

        if (isset($options['verbose']) && $options['verbose']) {
            $this->args .= ' --verbose';
        }

        if (isset($options['diff']) && $options['diff']) {
            $this->args .= ' --diff';
        }

        if (isset($options['rules']) && $options['rules']) {
            $this->args .= ' --rules=' . $options['rules'];
        }

        if (isset($options['config']) && $options['config']) {
            $this->config = true;
            $this->args .= ' --config=' . $builder->interpolate($options['config']);
        }

        if (isset($options['errors']) && $options['errors']) {
            $this->errors = true;
            $this->args .= ' --dry-run';

            if (isset($options['report_errors']) && $options['report_errors']) {
                $this->reportErrors = true;
            }

            $this->allowedWarnings = isset($options['allowed_warnings']) ? (int)$options['allowed_warnings'] : 0;
        }
    }

    /**
     * Run PHP CS Fixer.
     *
     * @return bool
     *
     * @throws Exception
     */
    public function execute()
    {
        $phpCsFixer = $this->executable;

        // Determine the version of PHP CS Fixer
        $cmd     = $phpCsFixer . ' --version';
        $success = $this->builder->executeCommand($cmd);
        $output  = $this->builder->getLastOutput();
        $matches = [];
        if (!\preg_match('/(\d+\.\d+\.\d+)/', $output, $matches)) {
            throw new Exception('Unable to determine the version of the PHP Coding Standards Fixer.');
        }

        $this->version = $matches[1];
        // Appeared in PHP CS Fixer 2.8.0 and used by default since 3.0.0
        // https://github.com/FriendsOfPHP/PHP-CS-Fixer/blob/2.19/CHANGELOG.md#changelog-for-v280
        $this->supportsUdiff = \version_compare($this->version, '2.8.0', '>=')
            && \version_compare($this->version, '3.0.0', '<');

        $directory = '';
        if (!empty($this->directory)) {
            $directory = $this->directory;
        }

        if (!$this->config) {
            if (\version_compare($this->version, '3.0.0', '>=')) {
                $this->configs = ['.php-cs-fixer.php', '.php-cs-fixer.dist.php'];
            } else {
                $this->configs = ['.php_cs', '.php_cs.dist'];
            }
            foreach ($this->configs as $config) {
                if (\file_exists($this->builder->buildPath . $config)) {
                    $this->config = true;
                    $this->args .= ' --config=./' . $config;
                    break;
                }
            }
        }

        if (!$this->config && !$directory) {
            $directory = '.';
        }

        if ($this->errors) {
            $this->args .= ' --verbose --format json --diff';
            if ($this->supportsUdiff) {
                $this->args .= ' --diff-format udiff';
            }
            if (!$this->build->isDebug()) {
                $this->builder->logExecOutput(false); // do not show json output
            }
        }

        $cmd     = $phpCsFixer . ' fix ' . $directory . ' %s';
        $success = $this->builder->executeCommand($cmd, $this->args);
        $this->builder->logExecOutput(true);
        $output  = $this->builder->getLastOutput();

        if ($this->errors) {
            $warningCount = $this->processReport($output);

            $this->build->storeMeta((self::pluginName() . '-warnings'), $warningCount);

            if (-1 != $this->allowedWarnings && $warningCount > $this->allowedWarnings) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Process the PHP CS Fixer report.
     *
     * @param string $output
     *
     * @return int
     *
     * @throws Exception
     */
    protected function processReport($output)
    {
        $data = \json_decode(\trim($output), true);

        if (!\is_array($data)) {
            $this->builder->log($output);
            throw new RuntimeException('Could not process the report generated by PHP CS Fixer.');
        }

        $warnings = 0;

        foreach ($data['files'] as $item) {
            $filename      = $item['name'];
            $appliedFixers = isset($item['appliedFixers']) ? $item['appliedFixers'] : [];

            $parser = new Parser();
            $parsed = $parser->parse($item['diff']);

            /** @var Diff $diffItem */
            $diffItem = $parsed[0];
            foreach ($diffItem->getChunks() as $chunk) {
                $firstModifiedLine = $chunk->getStart();
                $foundChanges      = false;
                if (0 === $firstModifiedLine) {
                    $firstModifiedLine = null;
                    $foundChanges      = true;
                }
                $chunkDiff = [];
                foreach ($chunk->getLines() as $line) {
                    /** @var Line $line */
                    switch ($line->getType()) {
                        case Line::ADDED:
                            $symbol = '+';
                            break;
                        case Line::REMOVED:
                            $symbol = '-';
                            break;
                        default:
                            $symbol = ' ';
                            break;
                    }
                    $chunkDiff[] = $symbol . $line->getContent();
                    if ($foundChanges) {
                        continue;
                    }
                    if (Line::UNCHANGED === $line->getType()) {
                        ++$firstModifiedLine;
                        continue;
                    }

                    $foundChanges = true;
                }

                $warnings++;

                if ($this->reportErrors) {
                    $this->build->reportError(
                        $this->builder,
                        self::pluginName(),
                        "PHP CS Fixer suggestion:\r\n```diff\r\n" . \implode("\r\n", $chunkDiff) . "\r\n```",
                        BuildError::SEVERITY_LOW,
                        $filename,
                        $firstModifiedLine
                    );
                }
            }

            if ($this->reportErrors && !empty($appliedFixers)) {
                $this->build->reportError(
                    $this->builder,
                    self::pluginName(),
                    'PHP CS Fixer failed fixers: ' . \implode(', ', $appliedFixers),
                    BuildError::SEVERITY_LOW,
                    $filename
                );
            }
        }

        return $warnings;
    }
}
