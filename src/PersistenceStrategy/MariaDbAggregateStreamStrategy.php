<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\Pdo\PersistenceStrategy;

use Iterator;
use Prooph\EventStore\Pdo\Exception;
use Prooph\EventStore\Pdo\MariaDBIndexedPersistenceStrategy;
use Prooph\EventStore\Pdo\PersistenceStrategy;
use Prooph\EventStore\StreamName;

final class MariaDbAggregateStreamStrategy implements PersistenceStrategy, MariaDBIndexedPersistenceStrategy
{
    /**
     * @param string $tableName
     * @return string[]
     */
    public function createSchema(string $tableName): array
    {
        $statement = <<<EOT
CREATE TABLE `$tableName` (
    `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `event_id` CHAR(36) COLLATE utf8mb4_bin NOT NULL,
    `event_name` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `metadata` LONGTEXT NOT NULL,
    `created_at` DATETIME(6) NOT NULL,
    `aggregate_version` INT(11) UNSIGNED GENERATED ALWAYS AS (JSON_EXTRACT(metadata, '$._aggregate_version')) STORED,
    CHECK (`payload` IS NOT NULL AND JSON_VALID(`payload`)),
    CHECK (`metadata` IS NOT NULL AND JSON_VALID(`metadata`)),
    PRIMARY KEY (`no`),
    UNIQUE KEY `ix_event_id` (`event_id`),
    UNIQUE KEY `ix_aggregate_version` (`aggregate_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
EOT;

        return [$statement];
    }

    public function columnNames(): array
    {
        return [
            'no',
            'event_id',
            'event_name',
            'payload',
            'metadata',
            'created_at',
        ];
    }

    public function prepareData(Iterator $streamEvents): array
    {
        $data = [];

        foreach ($streamEvents as $event) {
            if (! isset($event->metadata()['_aggregate_version'])) {
                throw new Exception\RuntimeException('_aggregate_version is missing in metadata');
            }

            $data[] = $event->metadata()['_aggregate_version'];
            $data[] = $event->uuid()->toString();
            $data[] = $event->messageName();
            $data[] = json_encode($event->payload());
            $data[] = json_encode($event->metadata());
            $data[] = $event->createdAt()->format('Y-m-d\TH:i:s.u');
        }

        return $data;
    }

    public function indexedMetadataFields(): array
    {
        return [
            '_aggregate_version' => 'aggregate_version',
        ];
    }

    public function generateTableName(StreamName $streamName): string
    {
        return '_' . sha1($streamName->toString());
    }
}
