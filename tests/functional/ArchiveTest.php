<?php

namespace Unish;

use Webmozart\PathUtil\Path;

/**
 * Class ArchiveTest.
 *
 * @group slow
 * @group commands
 * @group archive
 */
class ArchiveTest extends CommandUnishTestCase
{
    /**
     * @var string
     */
    protected string $archivePath;

    /**
     * @var array
     */
    protected array $archiveDumpOptions;

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        $this->setUpDrupal(1, true);
        $this->archiveDumpOptions = [
            'db' => null,
            'files' => null,
            'code' => null,
            'exclude-code-paths' => 'sut/sites/.+/settings.php,(?!sut).*',
        ];

        $this->archivePath = Path::join($this->getSandbox(), 'archive.tar.gz');
        $this->drush(
            'archive:dump',
            [],
            array_merge($this->archiveDumpOptions, [
                'destination' => $this->archivePath,
                'overwrite' => null,
            ])
        );
        $actualArchivePath = $this->getOutput();
        $this->assertEquals($this->archivePath, $actualArchivePath);
    }

    public function testArchiveDumpCommand(): void
    {
        // Create a file at the destination to confirm that archive:dump
        // will fail without --overwrite in this instance.
        file_put_contents($this->archivePath, "Existing file at destination");

        // Try to overwrite the existing archive with "--destination".
        $this->drush(
            'archive:dump',
            [],
            array_merge($this->archiveDumpOptions, [
                'destination' => $this->archivePath,
            ]),
            null,
            null,
            self::EXIT_ERROR
        );
        $this->assertStringContainsString('The destination file already exists.', $this->getErrorOutput());

        // Overwrite the existing archive with "--destination" and "--override".
        $this->drush(
            'archive:dump',
            [],
            array_merge($this->archiveDumpOptions, [
                'destination' => $this->archivePath,
                'overwrite' => null,
            ])
        );
        $actualArchivePath = $this->getOutput();
        $this->assertEquals($this->archivePath, $actualArchivePath);

        // Validate database credentials are present in settings.php file.
        $this->drush(
            'archive:dump',
            [],
            [],
            null,
            null,
            self::EXIT_ERROR
        );
        $this->assertStringContainsString(
            'Found database connection settings',
            $this->getErrorOutput()
        );
    }

    public function testArchiveRestoreCommand(): void
    {
        // @todo: remove once archive:restore command has added.
        if (!class_exists('\Drush\Commands\core\ArchiveRestoreCommands')) {
            $this->markTestSkipped('The command archive:restore is not found.');
        }

        // Restore archive from an existing file.
        $archivePath = Path::join($this->getSandbox(), 'archive.tar.gz');
        $this->drush(
            'archive:restore',
            [$archivePath]
        );

        // Restore archive from an existing file and an existing uncompressed directory.
        $this->drush(
            'archive:restore',
            [$archivePath],
            [],
            null,
            null,
            self::EXIT_ERROR
        );
        $this->assertMatchesRegularExpression(
            '/Extract directory .+ already exists/',
            $this->getErrorOutput()
        );
        $this->drush(
            'archive:restore',
            [$archivePath],
            ['overwrite' => null],
        );

        // Restore archive from paths.
        $archiveBasePath = Path::join($this->getSandbox(), 'archive');
        $this->drush(
            'archive:restore',
            [],
            [
                'code' => null,
                'code_path' => Path::join($archiveBasePath, 'code'),
            ]
        );
        $this->drush(
            'archive:restore',
            [],
            [
                'files' => null,
                'files_path' => Path::join($archiveBasePath, 'files'),
            ]
        );
        $this->drush(
            'archive:restore',
            [],
            [
                'db' => null,
                'db_path' => Path::join($archiveBasePath, 'database', 'database.sql'),
            ]
        );

        // Restore archive from a non-existing file.
        $nonExistingArchivePath = Path::join($this->getSandbox(), 'non-existing-archive.tar.gz');
        $this->drush(
            'archive:restore',
            [$nonExistingArchivePath],
            [],
            null,
            null,
            self::EXIT_ERROR
        );
        $this->assertStringContainsString(
            'non-existing-archive.tar.gz is not found',
            $this->getErrorOutput()
        );
    }
}
