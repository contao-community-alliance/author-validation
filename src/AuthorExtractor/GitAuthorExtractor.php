<?php

/**
 * This file is part of contao-community-alliance/build-system-tool-author-validation.
 *
 * (c) Contao Community Alliance <https://c-c-a.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/build-system-tool-author-validation
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  Contao Community Alliance <https://c-c-a.org>
 * @link       https://github.com/contao-community-alliance/build-system-tool-author-validation
 * @license    https://github.com/contao-community-alliance/build-system-tool-author-validation/blob/master/LICENSE MIT
 * @filesource
 */

namespace ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor;

use ContaoCommunityAlliance\BuildSystem\Repository\GitException;
use ContaoCommunityAlliance\BuildSystem\Repository\GitRepository;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Extract the author information from a git repository.
 */
class GitAuthorExtractor extends AbstractAuthorExtractor
{
    /**
     * Optional attached finder for processing multiple files.
     *
     * @var Finder
     */
    protected $finder;

    /**
     * Create a git repository instance.
     *
     * @param string $path A path within a git repository.
     *
     * @return GitRepository.
     */
    private function getGitRepositoryFor($path)
    {
        $git = new GitRepository($this->determineGitRoot($path));
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $git->getConfig()->setLogger(
                new ConsoleLogger($this->output)
            );
        }

        return $git;
    }

    /**
     * Determine the git root, starting from arbitrary directory.
     *
     * @param string $path The start path.
     *
     * @return string The git root path.
     *
     * @throws \RuntimeException If the git root could not determined.
     */
    private function determineGitRoot($path)
    {
        // @codingStandardsIgnoreStart
        while (strlen($path) > 1) {
            // @codingStandardsIgnoreEnd
            if (is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
                return $path;
            }

            $path = dirname($path);
        }

        throw new \RuntimeException('Could not determine git root, starting from ' . func_get_arg(0));
    }

    /**
     * Retrieve a list of all files within a git repository.
     *
     * @param GitRepository $git The repository to extract all files from.
     *
     * @return string[]
     *
     * @throws GitException When the git execution failed.
     */
    private function getAllFilesFromGit($git)
    {
        $gitDir = $git->getRepositoryPath();
        // Sadly no command in our git library for this.
        $processBuilder = new ProcessBuilder();
        $processBuilder->setWorkingDirectory($gitDir);
        $processBuilder
            ->add($git->getConfig()->getGitExecutablePath())
            ->add('ls-tree')
            ->add('HEAD')
            ->add('-r')
            ->add('--full-name')
            ->add('--name-only');

        $process = $processBuilder->getProcess();

        $git->getConfig()->getLogger()->debug(
            sprintf('[ccabs-repository-git] exec [%s] %s', $process->getWorkingDirectory(), $process->getCommandLine())
        );

        $process->run();
        $output = rtrim($process->getOutput(), "\r\n");

        if (!$process->isSuccessful()) {
            throw GitException::createFromProcess('Could not execute git command', $process);
        }

        $files = array();
        foreach (explode(PHP_EOL, $output) as $file) {
            $absolutePath = $gitDir . '/' . $file;
            if (!$this->config->isPathExcluded($absolutePath)) {
                $files[trim($absolutePath)] = trim($absolutePath);
            }
        }

        return $files;
    }

    /**
     * Convert the git binary output to a valid author list.
     *
     * @param string[] $authors The author list to convert.
     *
     * @return string[]
     */
    private function convertAuthorList($authors)
    {
        if (!$authors) {
            return array();
        }
        return preg_split('~[\r\n]+~', $authors);
    }

    /**
     * Retrieve the file path to use in reporting.
     *
     * @return string
     */
    public function getFilePaths()
    {
        $files = array();
        foreach ($this->config->getIncludedPaths() as $path) {
            $files = array_merge($files, $this->getAllFilesFromGit($this->getGitRepositoryFor($path)));
        }

        return $files;
    }

    /**
     * Check if the current file path is a file and if so, if it has staged modifications.
     *
     * @param string        $path A path obtained via a prior call to AuthorExtractor::getFilePaths().
     *
     * @param GitRepository $git  The repository to extract all files from.
     *
     * @return bool
     */
    private function isDirtyFile($path, $git)
    {
        if (!is_file($path)) {
            return false;
        }

        $status  = $git->status()->short()->getIndexStatus();
        $relPath = substr($path, (strlen($git->getRepositoryPath()) + 1));

        if (isset($status[$relPath]) && $status[$relPath]) {
            return true;
        }

        return false;
    }

    /**
     * Retrieve the author list from the given path via calling git.
     *
     * @param string        $path The path to check.
     *
     * @param GitRepository $git  The repository to extract all files from.
     *
     * @return string[]
     */
    private function getAuthorListFrom($path, $git)
    {
        return $git->log()->format('%aN <%ae>')->follow()->execute($path);
    }

    /**
     * Retrieve the data of the current user on the system.
     *
     * @param GitRepository $git The repository to extract all files from.
     *
     * @return string
     *
     * @throws GitException When the git execution failed.
     */
    private function getCurrentUserInfo($git)
    {
        // Sadly no command in our git library for this.
        $processBuilder = new ProcessBuilder();
        $processBuilder->setWorkingDirectory($git->getRepositoryPath());
        $processBuilder
            ->add($git->getConfig()->getGitExecutablePath())
            ->add('config')
            ->add('--get-regexp')
            ->add('user.[name|email]');

        $process = $processBuilder->getProcess();

        $git->getConfig()->getLogger()->debug(
            sprintf('[ccabs-repository-git] exec [%s] %s', $process->getWorkingDirectory(), $process->getCommandLine())
        );

        $process->run();
        $output = rtrim($process->getOutput(), "\r\n");

        if (!$process->isSuccessful()) {
            throw GitException::createFromProcess('Could not execute git command', $process);
        }

        $config = array();
        foreach (explode(PHP_EOL, $output) as $line) {
            list($name, $value)  = explode(' ', $line, 2);
            $config[trim($name)] = trim($value);
        }

        if (isset($config['user.name']) && $config['user.email']) {
            return sprintf('%s <%s>', $config['user.name'], $config['user.email']);
        }

        return '';
    }

    /**
     * Perform the extraction of authors.
     *
     * @param string $path A path obtained via a prior call to AuthorExtractor::getFilePaths().
     *
     * @return string[]|null
     */
    protected function doExtract($path)
    {
        $git = $this->getGitRepositoryFor($path);

        $authors = $this->convertAuthorList($this->getAuthorListFrom($path, $git));

        // Check if the file path is a file, if so, we need to check if it is "dirty" and someone is currently working
        // on it.
        if ($this->isDirtyFile($path, $git)) {
            $authors[] = $this->getCurrentUserInfo($git);
        }

        return $authors;
    }
}
