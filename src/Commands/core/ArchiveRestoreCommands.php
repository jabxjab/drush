<?php

namespace Drush\Commands\core;

use Consolidation\SiteAlias\SiteAlias;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Backend\BackendPathEvaluator;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\DrushCommands;
use Drush\Config\ConfigLocator;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Consolidation\SiteAlias\HostPath;
use Drush\Utils\FsUtils;
use Exception;
use PharData;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Webmozart\PathUtil\Path;

/**
 * Class ArchiveRestoreCommands.
 */
class ArchiveRestoreCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var array
     */
    private array $siteStatus;

    /**
     * @var string
     */
    private string $extractedPath;

    private const COMPONENT_CODE = 'code';

    private const COMPONENT_FILES = 'files';

    private const COMPONENT_DATABASE = 'database';
    private const SQL_DUMP_FILE_NAME = 'database.sql';

    private const TEMP_DIR_NAME = 'uncompressed';

    /**
     * Restore (import) your code, files, and database.
     *
     * @command archive:restore
     * @aliases arr
     *
     * @option code Import code.
     * @option code_path Import code from specified directory. Has higher priority over "path" argument.
     * @option db Import database.
     * @option db_path Import database from specified dump file. Has higher priority over "path" argument.
     * @option files Import Drupal files.
     * @option files_path Import Drupal files from specified directory. Has higher priority over "path" argument.
     * @option overwrite Overwrite files if exists when un-compressing an archive.
     *
     * @optionset_sql
     * @optionset_table_selection
     *
     * @bootstrap max configuration
     *
     * @param string|null $path
     *   The full path to a single archive file (*.tar.gz) or a directory with components to import.
     *   May contain the following components generated by `archive:dump` command:
     *   1) code ("code" directory);
     *   2) database dump file ("database/database.sql" file);
     *   3) Drupal files ("files" directory).
     * @param string|null $site
     *   Destination site alias. Defaults to @self.
     * @param array $options
     *
     * @throws \Exception
     */
    public function restore(
        string  $path = null,
        ?string $site = null,
        array $options = [
            'code' => false,
            'code_path' => null,
            'db' => false,
            'db_path' => null,
            'files' => false,
            'files_path' => null,
            'overwrite' => false,
        ]): void
    {
        $this->prepareTempDir();

        $extractDir = null;
        if (null !== $path) {
            $extractDir = is_dir($path) ? $path : $this->extractArchive($path, $options);
        }

        if (!$options['code'] && !$options['files'] && !$options['db']) {
            $options['code'] = $options['files'] = $options['db'] = true;
        }

        foreach (['code' => 'code', 'db' => 'database', 'files' => 'files'] as $component => $label) {
            if (!$options[$component]) {
                continue;
            }

            // Validate requested components have sources.
            if (null === $extractDir && null === $options[$component . '_path']) {
                throw new Exception(
                    dt('Missing either "path" input or "!component_path" option for the !label component.',
                        [
                            '!component' => $component,
                            '!label' => $label,
                        ]
                    )
                );
            }
        }

        if ($options['code']) {
            $codeComponentPath = $options['code_path'] ?? Path::join($extractDir, self::COMPONENT_CODE);
            $this->importCode($codeComponentPath, $site);
        }

        if ($options['files']) {
            $filesComponentPath = $options['files_path'] ?? Path::join($extractDir, self::COMPONENT_FILES);
            $this->importFiles($filesComponentPath, $site);
            return;
        }

        if ($options['db']) {
            $databaseComponentPath = $options['db_path'] ?? Path::join($extractDir, self::COMPONENT_DATABASE, self::SQL_DUMP_FILE_NAME);
            $this->importDatabase($databaseComponentPath);
        }

        $this->logger()->info(dt('Done!'));
    }

    /**
     * Creates a temporary directory to extract the archive onto.
     *
     * @throws \Exception
     */
    protected function prepareTempDir(): void
    {
        $this->filesystem = new Filesystem();
        $this->extractedPath = FsUtils::prepareBackupDir(self::TEMP_DIR_NAME);
        register_shutdown_function([$this, 'cleanUp']);
    }

    /**
     * Extracts the archive.
     *
     * @param string $path
     *   The path to the archive file.
     * @param array $options
     *   Command options.
     *
     * @return string
     *
     * @throws \Exception
     */
    protected function extractArchive(string $path, array $options): string
    {
        $this->logger()->info('Extracting the archive...');

        if (!is_file($path)) {
            throw new Exception(dt('File !path is not found.', ['!path' => $path]));
        }

        if (!preg_match('/\.tar\.gz$/', $path)) {
            throw new Exception(dt('File !path is not a *.tar.gz file.', ['!path' => $path]));
        }

        ['filename' => $archiveFileName] = pathinfo($path);
        $archiveFileName = str_replace('.tar', '', $archiveFileName);

        $extractDir = Path::join(dirname($path), $archiveFileName);
        if (is_dir($extractDir)) {
            if ($options['overwrite']) {
                $this->filesystem->remove($extractDir);
            } else {
                throw new Exception(
                    dt('Extract directory !path already exists (use "--overwrite" option).', ['!path' => $extractDir])
                );
            }
        }

        $this->filesystem->mkdir($extractDir);

        $archive = new PharData($path);
        $archive->extractTo($extractDir);

        $this->logger()->info(dt('The archive successfully extracted into !path', ['!path' => $extractDir]));

        return $extractDir;
    }

    /**
     * Imports the code to the site.
     *
     * @param string $source
     *   The path to the code files directory.
     * @param string|null $destinationSite
     *   The destination site alias.
     *
     * @throws \Drush\Exceptions\UserAbortException
     * @throws \Exception
     */
    protected function importCode(string $source, ?string $destinationSite): void
    {
        $this->logger()->info('Importing code...');

        if (!is_dir($source)) {
            throw new Exception(dt('Directory !path not found.', ['!path' => $source]));
        }

        if (!$this->io()->confirm(dt('Are you sure you want to import the code?'))) {
            // @todo: move this right before executing rsync so that to provide source/destination paths.
            throw new UserAbortException();
        }

        $siteAlias = $this->getSiteAlias($destinationSite);

        if ($siteAlias->isLocal()) {
            $bootstrapManager = Drush::bootstrapManager();
            $destination = $bootstrapManager->getComposerRoot();
            $this->rsyncFiles($source, $destination);

            return;
        }

        $siteStatus = $this->getSiteStatus($siteAlias);
        if (!isset($siteStatus['composer-root'])) {
            throw new Exception('Failed to get path to Composer root.');
        }
        if (!$siteStatus['composer-root']) {
            throw new Exception('Path to Composer root is empty.');
        }

        $destination = sprintf('%s:%s', $siteAlias->remoteHostWithUser(), $siteStatus['composer-root']);

        $this->rsyncFiles($source, $destination);
    }

    /**
     * Imports Drupal files to the site.
     *
     * @param string|null $source
     *   The path to the Drupal files directory.
     *
     * @throws \Drush\Exceptions\UserAbortException
     * @throws \Exception
     */
    protected function importFiles(string $source, ?string $destinationSite): void
    {
        $this->logger()->info('Importing files...');

        if (!is_dir($source)) {
            throw new Exception(dt('Directory !path not found.', ['!path' => $source]));
        }

        if (!$this->io()->confirm(dt('Are you sure you want to import the Drupal files?'))) {
            // @todo: move this right before executing rsync so that to provide source/destination paths.
            throw new UserAbortException();
        }

        $siteAlias = $this->getSiteAlias($destinationSite);

        if ($siteAlias->isLocal()) {
            Drush::bootstrapManager()->doBootstrap(DrupalBootLevels::FULL);
            $drupalFilesPath = \Drupal::service('file_system')->realpath('public://');
            if (!$drupalFilesPath) {
                throw new Exception('Path to Drupal files is empty.');
            }

            $this->rsyncFiles($source, $drupalFilesPath);

            return;
        }

        $siteStatus = $this->getSiteStatus($siteAlias);
        if (!isset($siteStatus['root'])) {
            throw new Exception('Failed to get the site root path.');
        }

        if (!isset($siteStatus['files'])) {
            throw new Exception('Failed to get path to Drupal files directory.');
        }

        $drupalFilesPath = Path::join($siteStatus['root'], $siteStatus['files']);
        $destination = sprintf('%s:%s', $siteAlias->remoteHostWithUser(), $drupalFilesPath);

        $this->rsyncFiles($source, $destination);
    }

    /**
     * Returns SiteAlias object by the site alias name.
     *
     * @param string|null $site
     *   The site alias.
     *
     * @return \Consolidation\SiteAlias\SiteAlias
     *
     * @throws \Exception
     */
    protected function getSiteAlias(?string $site): SiteAlias
    {
        $pathEvaluator = new BackendPathEvaluator();
        $manager = $this->siteAliasManager();

        if (null !== $site) {
            $site .= ':%root';
        }
        $evaluatedPath = HostPath::create($manager, $site);
        $pathEvaluator->evaluate($evaluatedPath);

        return $evaluatedPath->getSiteAlias();
    }

    /**
     * Returns the site status fields (composer-root, root, files).
     *
     * @param \Consolidation\SiteAlias\SiteAlias $siteAlias
     *   The site alias object.
     *
     * @return array
     */
    protected function getSiteStatus(SiteAlias $siteAlias): array
    {
        if (isset($this->siteStatus)) {
            return $this->siteStatus;
        }

        if ($siteAlias->isRemote()) {
            $aliasConfigContext = $this->getConfig()->getContext(ConfigLocator::ALIAS_CONTEXT);
            $aliasConfigContext->combine($siteAlias->export());
        }

        /** @var \Drush\SiteAlias\ProcessManager $processManager */
        $processManager = $this->processManager();
        $process = $processManager->drush(
            $siteAlias,
            'core-status',
            [],
            ['fields' => 'composer-root,root,files', 'format' => 'json']
        );
        $process->mustRun();

        return $this->siteStatus = $process->getOutputAsJson();
    }

    /**
     * Copies files from the source to the destination.
     *
     * @param string $source
     *   The source path.
     * @param string $destination
     *   The destination path.
     *
     * @throws \Exception
     */
    protected function rsyncFiles(string $source, string $destination): void
    {
        $source = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $destination = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $this->logger()->info(dt('Copying files from "!source" to "!destination"...',
        [
            '!source' => $source,
            '!destination' => $destination,
        ]));

        $options[] = sprintf("-e 'ssh %s'", $this->getConfig()->get('ssh.options'));
        $options[] = '-akz';
        if ($this->output()->isVerbose()) {
            $options[] = '--stats';
            $options[] = '--progress';
            $options[] = '-v';
        }

        $command = sprintf(
            'rsync %s %s %s',
            implode(' ', $options),
            $source,
            $destination
        );

        /** @var \Consolidation\SiteProcess\ProcessBase $process */
        $process = $this->processManager()->shell($command);
        $process->run($process->showRealtime());
        if ($process->isSuccessful()) {
            return;
        }

        throw new Exception(
            dt(
                'Failed to copy files from !source to !destination: !error',
                [
                    '!source' => $source,
                    '!destination' => $destination,
                    '!error' => $process->getErrorOutput(),
                ]
            )
        );
    }

    /**
     * Imports the database dump to the site.
     *
     * @param string $databaseDumpPath
     *   The path to the database dump file.
     *
     * @throws \Drush\Exceptions\UserAbortException
     * @throws \Exception
     */
    protected function importDatabase(string $databaseDumpPath): void
    {
        $this->logger()->info('Importing database...');

        if (!is_file($databaseDumpPath)) {
            throw new Exception(dt('Database dump file !path not found.', ['!path' => $databaseDumpPath]));
        }

        if (!$this->io()->confirm(dt('Are you sure you want to import the database dump?'))) {
            throw new UserAbortException();
        }
    }

    /**
     * Performs clean-up tasks.
     *
     * Deletes temporary directory.
     */
    public function cleanUp(): void
    {
        try {
            $this->logger()->info(dt('Deleting !path...', ['!path' => $this->extractedPath]));
            $this->filesystem->remove($this->extractedPath);
        } catch (IOException $e) {
            $this->logger()->info(
                dt(
                    'Failed deleting !path: !message',
                    ['!path' => $this->extractedPath, '!message' => $e->getMessage()]
                )
            );
        }
    }
}
