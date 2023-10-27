<?php

namespace wcf\system\database;

use wcf\system\database\exception\DatabaseException as GenericDatabaseException;

/**
 * This is the database implementation for PostgreSQL using PDO.
 *
 * @author      Marcel Werk
 * @copyright   2001-2023 WoltLab GmbH
 * @license     GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 */
class PostgreSQLDatabase extends Database
{
    /**
     * @inheritDoc
     */
    public function connect()
    {
        if (!$this->port) {
            $this->port = 5432; // postgreSQL default port
        }

        try {
            $driverOptions = $this->defaultDriverOptions;

            // throw PDOException instead of dumb false return values
            $driverOptions[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

            $dsn = "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
            $this->pdo = new \PDO($dsn, $this->user, $this->password, $driverOptions);
            $this->setAttributes();
        } catch (\PDOException $e) {
            throw new GenericDatabaseException("Connecting to PostgreSQL server '" . $this->host . "' failed", $e);
        }
    }

    /**
     * @inheritDoc
     */
    public static function isSupported()
    {
        return \extension_loaded('PDO') && \extension_loaded('pdo_pgsql');
    }

    /**
     * @inheritDoc
     */
    public function getVersion()
    {
        try {
            $statement = $this->prepareStatement('SELECT VERSION()');
            $statement->execute();

            return $statement->fetchSingleColumn();
        } catch (\PDOException $e) {
        }

        return 'unknown';
    }
}
