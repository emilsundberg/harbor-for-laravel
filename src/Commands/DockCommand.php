<?php

namespace Emilsundberg\Harbor\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DockCommand extends Command
{
    protected $signature = 'harbor:dock {fork : The fork repository in format username/repo-name} {--branch= : The branch to checkout after cloning}';
    protected $description = 'Clone a fork repository and set it up for local development';

    protected string $fork;
    protected string $username;
    protected string $repo;
    protected string $forksPath;
    protected string $clonePath;

    public function handle()
    {
        $this->fork = $this->argument('fork');

        try {
            $this->ensureValidForkFormat();
            $this->extractRepositoryInfo();
            $this->setupForksDirectory();
            $this->updateGitignore();
            $this->ensureForkNotExists();
            $this->cloneForkRepository();
            $this->validateComposerJson();
            $this->ensurePackageInstalled();
            $this->updateComposerConfig();
            $this->checkoutBranch();
            $this->requireDevPackage();

            $this->info('Successfully docked fork repository');

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
        [$this->username, $this->repo] = explode('/', $this->fork);
        $this->forksPath = base_path('.forks');
        $this->clonePath = "{$this->forksPath}/{$this->repo}";
    }

    protected function setupForksDirectory(): void
    {
        if (!File::exists($this->forksPath)) {
            File::makeDirectory($this->forksPath);
            $this->info('Created .forks directory');
        }
    }

    protected function updateGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');

        if (!File::exists($gitignorePath)) {
            File::put($gitignorePath, "/.forks\n");
        } elseif (!str_contains(File::get($gitignorePath), '/.forks')) {
            File::append($gitignorePath, "\n/.forks\n");
        }

        $this->info('Updated .gitignore');
    }

    protected function ensureForkNotExists(): void
    {
        if (File::exists($this->clonePath)) {
            throw new Exception('Fork already exists in .forks directory');
        }
    }

    protected function cloneForkRepository(): void
    {
        try {
            $process = new Process(['git', 'clone', "git@github.com:{$this->fork}.git", $this->clonePath]);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->info('Cloned fork repository');
        } catch (Exception $e) {
            throw new Exception('Failed to clone repository: ' . $e->getMessage());
        }
    }

    public function checkoutBranch(): void
    {
        $branch = $this->option('branch');

        if (! $branch) {
            return;
        }

        try {
            $process = new Process(['git', 'checkout', $branch], $this->clonePath);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $this->info("Checked out branch: {$branch}");
        } catch (Exception $e) {
            throw new Exception('Failed to checkout branch: ' . $e->getMessage());
        }
    }

    protected function validateComposerJson(): void
    {
        $composerPath = "{$this->clonePath}/composer.json";

        if (!File::exists($composerPath)) {
            File::deleteDirectory($this->clonePath);
            throw new Exception('composer.json not found in forked repository');
        }

        $this->info('Found package name: ' . $this->getPackageName());
    }

    protected function ensurePackageInstalled(): void
    {
        $composerJson = json_decode(File::get(base_path('composer.json')), true);
        $packageName = $this->getPackageName();

        $hasPackage =
            isset($composerJson['require'][$packageName]) ||
            isset($composerJson['require-dev'][$packageName]);

        if (!$hasPackage) {
            File::deleteDirectory($this->clonePath);

            throw new Exception(
                "The package ({$packageName}) must be installed first.\n" .
                "Please run: composer require {$packageName}"
            );
        }

        $this->info("Package ({$packageName}) is installed.");
    }

    protected function updateComposerConfig(): void
    {
        $composerPath = base_path('composer.json');
        $composer = json_decode(File::get($composerPath), true);

        if (!isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }

        if (!$this->repositoryExists($composer['repositories'])) {
            $composer['repositories'][] = [
                'type' => 'path',
                'url'  => "./.forks/{$this->repo}"
            ];
        }

        File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Updated composer.json');
    }

    protected function requireDevPackage(): void
    {
        try {
            $process = new Process(['composer', 'require', "{$this->getPackageName()}:@dev"]);
            $process->setTty(true);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } catch (Exception $e) {
            throw new Exception('Failed to run composer require: ' . $e->getMessage());
        }
    }

    protected function getPackageName(): string
    {
        $composerPath = "{$this->clonePath}/composer.json";
        $composer = json_decode(File::get($composerPath), true);

        return $composer['name'];
    }

    protected function repositoryExists(array $repositories): bool
    {
        foreach ($repositories as $repository) {
            if (isset($repository['url']) && $repository['url'] === "./.forks/{$this->repo}") {
                return true;
            }
        }

        return false;
    }
}
