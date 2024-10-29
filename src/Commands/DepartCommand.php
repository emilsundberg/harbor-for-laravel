<?php

namespace Emilsundberg\Harbor\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DepartCommand extends Command
{
    protected $signature = 'harbor:depart {fork : The fork repository in format username/repo-name}';
    protected $description = 'Remove a forked repository and revert to the original package';

    protected string $fork;
    protected string $repo;
    protected string $forksPath;
    protected string $repoPath;
    protected string $packageName;

    public function handle()
    {
        $this->fork = $this->argument('fork');

        try {
            $this->ensureValidForkFormat();
            $this->extractRepositoryInfo();
            $this->ensureForkExists();
            $this->checkUncommittedChanges();
            $this->loadPackageInfo();
            $this->updateComposerConfig();
            $this->removeForkDirectory();
            $this->reinstallOriginalPackage();
            $this->cleanupIfEmpty();

            $this->info('Successfully removed fork and reverted to original package!');

            return 0;
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    protected function ensureValidForkFormat(): void
    {
        if (!preg_match('/^[a-zA-Z0-9-]+\/[a-zA-Z0-9-]+$/', $this->fork)) {
            throw new Exception('Invalid fork format. Please use format: username/repo-name');
        }
    }

    protected function extractRepositoryInfo(): void
    {
        [, $this->repo] = explode('/', $this->fork);
        $this->forksPath = base_path('.forks');
        $this->repoPath = "{$this->forksPath}/{$this->repo}";
    }

    protected function ensureForkExists(): void
    {
        if (!File::exists($this->repoPath)) {
            throw new Exception('Fork not found in .forks directory');
        }
    }

    protected function checkUncommittedChanges(): void
    {
        try {
            $process = new Process(['git', 'status', '--porcelain'], $this->repoPath);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $gitStatus = $process->getOutput();

            if (!empty($gitStatus)) {
                if (!$this->confirmUncommittedChanges($gitStatus)) {
                    throw new Exception('Operation cancelled.');
                }
            }
        } catch (ProcessFailedException $e) {
            throw new Exception('Failed to check git status: ' . $e->getMessage());
        }
    }

    protected function confirmUncommittedChanges(string $gitStatus): bool
    {
        if (!$this->confirm('There are uncommitted changes in the fork directory. These changes will be lost. Do you want to continue?')) {
            return false;
        }

        $this->warn('The following changes will be lost:');
        $this->line($gitStatus);

        return $this->confirm('Are you absolutely sure you want to continue?');
    }

    protected function loadPackageInfo(): void
    {
        $composerPath = "{$this->repoPath}/composer.json";

        if (!File::exists($composerPath)) {
            throw new Exception('composer.json not found in forked repository');
        }

        $composer = json_decode(File::get($composerPath), true);
        $this->packageName = $composer['name'];
    }

    protected function updateComposerConfig(): void
    {
        $composerPath = base_path('composer.json');
        $composer = json_decode(File::get($composerPath), true);

        if (isset($composer['repositories'])) {
            $composer['repositories'] = array_values(array_filter(
                $composer['repositories'],
                fn($repository) => !isset($repository['url']) || $repository['url'] !== "./.forks/{$this->repo}"
            ));

            if (empty($composer['repositories'])) {
                unset($composer['repositories']);
            }

            File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Removed repository from composer.json');
        }
    }

    protected function removeForkDirectory(): void
    {
        try {
            File::deleteDirectory($this->repoPath);
            $this->info('Removed forked repository directory');
        } catch (Exception $e) {
            throw new Exception('Failed to remove fork directory: ' . $e->getMessage());
        }
    }

    protected function reinstallOriginalPackage(): void
    {
        try {
            // Remove dev version
            $this->runComposerCommand(['composer', 'remove', $this->packageName]);

            // Install original version
            $this->runComposerCommand(['composer', 'require', $this->packageName]);
        } catch (Exception $e) {
            throw new Exception('Failed to reinstall original package: ' . $e->getMessage());
        }
    }

    protected function runComposerCommand(array $command): void
    {
        $process = new Process($command);
        $process->setTty(true);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    protected function cleanupIfEmpty(): void
    {
        if (count(File::files($this->forksPath)) === 0 && count(File::directories($this->forksPath)) === 0) {
            File::deleteDirectory($this->forksPath);
            $this->cleanupGitignore();
            $this->info('Removed empty .forks directory and cleaned up .gitignore');
        }
    }

    protected function cleanupGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');

        if (File::exists($gitignorePath)) {
            $gitignore = File::get($gitignorePath);
            $gitignore = str_replace("/.forks\n", '', $gitignore);
            $gitignore = str_replace("/.forks", '', $gitignore);
            File::put($gitignorePath, $gitignore);
        }
    }
}
