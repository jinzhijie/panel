<?php

namespace Pterodactyl\Tests\Integration\Services\Databases;

use Mockery;
use Exception;
use BadMethodCallException;
use InvalidArgumentException;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\DatabaseHost;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Repositories\Eloquent\DatabaseRepository;
use Pterodactyl\Services\Databases\DatabaseManagementService;
use Pterodactyl\Exceptions\Repository\DuplicateDatabaseNameException;
use Pterodactyl\Exceptions\Service\Database\TooManyDatabasesException;
use Pterodactyl\Exceptions\Service\Database\DatabaseClientFeatureNotEnabledException;

class DatabaseManagementServiceTest extends IntegrationTestCase
{
    /** @var \Mockery\MockInterface */
    private $repository;

    /**
     * Setup tests.
     */
    public function setUp(): void
    {
        parent::setUp();

        config()->set('pterodactyl.client_features.databases.enabled', true);

        $this->repository = Mockery::mock(DatabaseRepository::class);
        $this->swap(DatabaseRepository::class, $this->repository);
    }

    /**
     * Test that the name generated by the unique name function is what we expect.
     */
    public function testUniqueDatabaseNameIsGeneratedCorrectly()
    {
        $this->assertSame('s1_example', DatabaseManagementService::generateUniqueDatabaseName('example', 1));
        $this->assertSame('s123_something_else', DatabaseManagementService::generateUniqueDatabaseName('something_else', 123));
        $this->assertSame('s123_' . str_repeat('a', 43), DatabaseManagementService::generateUniqueDatabaseName(str_repeat('a', 100), 123));
    }

    /**
     * Test that disabling the client database feature flag prevents the creation of databases.
     */
    public function testExceptionIsThrownIfClientDatabasesAreNotEnabled()
    {
        config()->set('pterodactyl.client_features.databases.enabled', false);

        $this->expectException(DatabaseClientFeatureNotEnabledException::class);

        $server = $this->createServerModel();
        $this->getService()->create($server, []);
    }

    /**
     * Test that a server at its database limit cannot have an additional one created if
     * the $validateDatabaseLimit flag is not set to false.
     */
    public function testDatabaseCannotBeCreatedIfServerHasReachedLimit()
    {
        $server = $this->createServerModel(['database_limit' => 2]);
        $host = factory(DatabaseHost::class)->create(['node_id' => $server->node_id]);

        factory(Database::class)->times(2)->create(['server_id' => $server->id, 'database_host_id' => $host->id]);

        $this->expectException(TooManyDatabasesException::class);

        $this->getService()->create($server, []);
    }

    /**
     * Test that a missing or invalid database name format causes an exception to be thrown.
     *
     * @param array $data
     * @dataProvider invalidDataDataProvider
     */
    public function testEmptyDatabaseNameOrInvalidNameTriggersAnException($data)
    {
        $server = $this->createServerModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The database name passed to DatabaseManagementService::handle MUST be prefixed with "s{server_id}_".');

        $this->getService()->create($server, $data);
    }

    /**
     * Test that creating a server database with an identical name triggers an exception.
     */
    public function testCreatingDatabaseWithIdenticalNameTriggersAnException()
    {
        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('soemthing', $server->id);

        $host = factory(DatabaseHost::class)->create(['node_id' => $server->node_id]);
        $host2 = factory(DatabaseHost::class)->create(['node_id' => $server->node_id]);
        factory(Database::class)->create([
            'database' => $name,
            'database_host_id' => $host->id,
            'server_id' => $server->id,
        ]);

        $this->expectException(DuplicateDatabaseNameException::class);
        $this->expectExceptionMessage('A database with that name already exists for this server.');

        // Try to create a database with the same name as a database on a different host. We expect
        // this to fail since we don't account for the specific host when checking uniqueness.
        $this->getService()->create($server, [
            'database' => $name,
            'database_host_id' => $host2->id,
        ]);

        $this->assertDatabaseMissing('databases', ['server_id' => $server->id]);
    }

    /**
     * Test that a server database can be created successfully.
     */
    public function testServerDatabaseCanBeCreated()
    {
        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('soemthing', $server->id);

        $host = factory(DatabaseHost::class)->create(['node_id' => $server->node_id]);

        $this->repository->expects('createDatabase')->with($name);

        $username = null;
        $secondUsername = null;
        $password = null;

        // The value setting inside the closures if to avoid throwing an exception during the
        // assertions that would get caught by the functions catcher and thus lead to the exception
        // being swallowed incorrectly.
        $this->repository->expects('createUser')->with(
            Mockery::on(function ($value) use (&$username) {
                $username = $value;

                return true;
            }),
            '%',
            Mockery::on(function ($value) use (&$password) {
                $password = $value;

                return true;
            }),
            null
        );

        $this->repository->expects('assignUserToDatabase')->with($name, Mockery::on(function ($value) use (&$secondUsername) {
            $secondUsername = $value;

            return true;
        }), '%');

        $this->repository->expects('flush')->withNoArgs();

        $response = $this->getService()->create($server, [
            'remote' => '%',
            'database' => $name,
            'database_host_id' => $host->id,
        ]);

        $this->assertInstanceOf(Database::class, $response);
        $this->assertSame($response->server_id, $server->id);
        $this->assertRegExp('/^(u[\d]+_)(\w){10}$/', $username);
        $this->assertSame($username, $secondUsername);
        $this->assertSame(24, strlen($password));

        $this->assertDatabaseHas('databases', ['server_id' => $server->id, 'id' => $response->id]);
    }

    /**
     * Test that an exception encountered while creating the database leads to cleanup code being called
     * and any exceptions encountered while cleaning up go unreported.
     */
    public function testExceptionEncounteredWhileCreatingDatabaseAttemptsToCleanup()
    {
        $server = $this->createServerModel();
        $name = DatabaseManagementService::generateUniqueDatabaseName('soemthing', $server->id);

        $host = factory(DatabaseHost::class)->create(['node_id' => $server->node_id]);

        $this->repository->expects('createDatabase')->with($name)->andThrows(new BadMethodCallException);
        $this->repository->expects('dropDatabase')->with($name);
        $this->repository->expects('dropUser')->withAnyArgs()->andThrows(new InvalidArgumentException);

        $this->expectException(BadMethodCallException::class);

        $this->getService()->create($server, [
            'remote' => '%',
            'database' => $name,
            'database_host_id' => $host->id,
        ]);

        $this->assertDatabaseMissing('databases', ['server_id' => $server->id]);
    }

    /**
     * @return array
     */
    public function invalidDataDataProvider(): array
    {
        return [
            [[]],
            [['database' => '']],
            [['database' => 'something']],
            [['database' => 's_something']],
            [['database' => 's12s_something']],
            [['database' => 's12something']],
        ];
    }

    /**
     * @return \Pterodactyl\Services\Databases\DatabaseManagementService
     */
    private function getService()
    {
        return $this->app->make(DatabaseManagementService::class);
    }
}
