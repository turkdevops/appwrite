<?php

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;

require_once __DIR__ . '/../init.php';

Console::title('Database V1 Worker');
Console::success(APP_NAME . ' database worker v1 has started' . "\n");

class DatabaseV1 extends Worker
{
    public function init(): void
    {
    }

    public function run(): void
    {
        $type = $this->args['type'];
        $project = new Document($this->args['project']);
        $collection = new Document($this->args['collection'] ?? []);
        $document = new Document($this->args['document'] ?? []);
        $database = new Document($this->args['database'] ?? []);

        if ($collection->isEmpty()) {
            throw new DatabaseException('Missing collection');
        }

        if ($document->isEmpty()) {
            throw new DatabaseException('Missing document');
        }

        switch (strval($type)) {
            case DATABASE_TYPE_CREATE_ATTRIBUTE:
                $this->createAttribute($database, $collection, $document, $project);
                break;
            case DATABASE_TYPE_DELETE_ATTRIBUTE:
                $this->deleteAttribute($database, $collection, $document, $project);
                break;
            case DATABASE_TYPE_CREATE_INDEX:
                $this->createIndex($database, $collection, $document, $project);
                break;
            case DATABASE_TYPE_DELETE_INDEX:
                $this->deleteIndex($database, $collection, $document, $project);
                break;

            default:
                Console::error('No database operation for type: ' . $type);
                break;
        }
    }

    public function shutdown(): void
    {
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param Document $project
     */
    protected function createAttribute(Document $database, Document $collection, Document $attribute, Document $project): void
    {
        $projectId = $project->getId();
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($project);

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        /**
         * Fetch attribute from the database, since with Resque float values are loosing informations.
         */
        $attribute = $dbForProject->getDocument('attributes', $attribute->getId());

        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', '');
        $formatOptions = $attribute->getAttribute('formatOptions', []);
        $filters = $attribute->getAttribute('filters', []);
        $options = $attribute->getAttribute('options', []);
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            switch ($type) {
                case Database::VAR_RELATIONSHIP:
                    $relatedCollection = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                    if ($relatedCollection->isEmpty()) {
                        throw new DatabaseException('Collection not found');
                    }

                    if (
                        !$dbForProject->createRelationship(
                            collection: 'database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(),
                            relatedCollection: 'database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId(),
                            type: $options['relationType'],
                            twoWay: $options['twoWay'],
                            id: $key,
                            twoWayKey: $options['twoWayKey'],
                            onDelete: $options['onDelete'],
                        )
                    ) {
                        throw new DatabaseException('Failed to create Attribute');
                    }

                    if ($options['twoWay']) {
                        $relatedAttribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);
                        $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'available'));
                    }
                    break;
                default:
                    if (!$dbForProject->createAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                        throw new Exception('Failed to create Attribute');
                    }
            }

            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Exception $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $attribute->setAttribute('error', $e->getMessage());
                if (isset($relatedAttribute)) {
                    $relatedAttribute->setAttribute('error', $e->getMessage());
                }
            }

            $dbForProject->updateDocument(
                'attributes',
                $attribute->getId(),
                $attribute->setAttribute('status', 'failed')
            );

            if (isset($relatedAttribute)) {
                $dbForProject->updateDocument(
                    'attributes',
                    $relatedAttribute->getId(),
                    $relatedAttribute->setAttribute('status', 'failed')
                );
            }
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $attribute,
                project: $project,
            );

            Realtime::send(
                projectId: 'console',
                payload: $attribute->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        if ($type === Database::VAR_RELATIONSHIP && $options['twoWay']) {
            $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $attribute
     * @param Document $project
     * @throws Throwable
     */
    protected function deleteAttribute(Document $database, Document $collection, Document $attribute, Document $project): void
    {
        $projectId = $project->getId();
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($project);

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].attributes.[attributeId].delete', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $status = $attribute->getAttribute('status', '');
        $type = $attribute->getAttribute('type', '');
        $project = $dbForConsole->getDocument('projects', $projectId);
        $options = $attribute->getAttribute('options', []);
        $relatedAttribute = new Document();
        $relatedCollection = new Document();
        // possible states at this point:
        // - available: should not land in queue; controller flips these to 'deleting'
        // - processing: hasn't finished creating
        // - deleting: was available, in deletion queue for first time
        // - failed: attribute was never created
        // - stuck: attribute was available but cannot be removed

        try {
            if ($status !== 'failed') {
                if ($type === Database::VAR_RELATIONSHIP) {
                    if ($options['twoWay']) {
                        $relatedCollection = $dbForProject->getDocument('database_' . $database->getInternalId(), $options['relatedCollection']);
                        if ($relatedCollection->isEmpty()) {
                            throw new DatabaseException('Collection not found');
                        }
                        $relatedAttribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);
                    }

                    if (!$dbForProject->deleteRelationship('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                        $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'stuck'));
                        throw new DatabaseException('Failed to delete Relationship');
                    }
                } elseif (!$dbForProject->deleteAttribute('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                    throw new DatabaseException('Failed to delete Attribute');
                }
            }

            $dbForProject->deleteDocument('attributes', $attribute->getId());

            if (!$relatedAttribute->isEmpty()) {
                $dbForProject->deleteDocument('attributes', $relatedAttribute->getId());
            }
        } catch (\Exception $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $attribute->setAttribute('error', $e->getMessage());
                if (!$relatedAttribute->isEmpty()) {
                    $relatedAttribute->setAttribute('error', $e->getMessage());
                }
            }
            $dbForProject->updateDocument(
                'attributes',
                $attribute->getId(),
                $attribute->setAttribute('status', 'stuck')
            );
            if (!$relatedAttribute->isEmpty()) {
                $dbForProject->updateDocument(
                    'attributes',
                    $relatedAttribute->getId(),
                    $relatedAttribute->setAttribute('status', 'stuck')
                );
            }
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $attribute,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $attribute->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        // The underlying database removes/rebuilds indexes when attribute is removed
        // Update indexes table with changes
        /** @var Document[] $indexes */
        $indexes = $collection->getAttribute('indexes', []);

        foreach ($indexes as $index) {
            /** @var string[] $attributes */
            $attributes = $index->getAttribute('attributes');
            $lengths = $index->getAttribute('lengths');
            $orders = $index->getAttribute('orders');

            $found = \array_search($key, $attributes);

            if ($found !== false) {
                // If found, remove entry from attributes, lengths, and orders
                // array_values wraps array_diff to reindex array keys
                // when found attribute is removed from array
                $attributes = \array_values(\array_diff($attributes, [$attributes[$found]]));
                $lengths = \array_values(\array_diff($lengths, isset($lengths[$found]) ? [$lengths[$found]] : []));
                $orders = \array_values(\array_diff($orders, isset($orders[$found]) ? [$orders[$found]] : []));

                if (empty($attributes)) {
                    $dbForProject->deleteDocument('indexes', $index->getId());
                } else {
                    $index
                        ->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('lengths', $lengths, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('orders', $orders, Document::SET_TYPE_ASSIGN);

                    // Check if an index exists with the same attributes and orders
                    $exists = false;
                    foreach ($indexes as $existing) {
                        if (
                            $existing->getAttribute('key') !== $index->getAttribute('key') // Ignore itself
                            && $existing->getAttribute('attributes') === $index->getAttribute('attributes')
                            && $existing->getAttribute('orders') === $index->getAttribute('orders')
                        ) {
                            $exists = true;
                            break;
                        }
                    }

                    if ($exists) { // Delete the duplicate if created, else update in db
                        $this->deleteIndex($database, $collection, $index, $project);
                    } else {
                        $dbForProject->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
        $dbForProject->deleteCachedCollection('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId());

        if (!$relatedCollection->isEmpty() && !$relatedAttribute->isEmpty()) {
            $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $relatedCollection->getId());
            $dbForProject->deleteCachedCollection('database_' . $database->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
        }
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param Document $project
     * @throws \Exception
     */
    protected function createIndex(Document $database, Document $collection, Document $index, Document $project): void
    {
        $projectId = $project->getId();
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($project);

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].indexes.[indexId].update', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key, $type, $attributes, $lengths, $orders)) {
                throw new DatabaseException('Failed to create Index');
            }
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Exception $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'failed')
            );
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collectionId);
    }

    /**
     * @param Document $database
     * @param Document $collection
     * @param Document $index
     * @param Document $project
     */
    protected function deleteIndex(Document $database, Document $collection, Document $index, Document $project): void
    {
        $projectId = $project->getId();
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($project);

        $events = Event::generateEvents('databases.[databaseId].collections.[collectionId].indexes.[indexId].delete', [
            'databaseId' => $database->getId(),
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if ($status !== 'failed' && !$dbForProject->deleteIndex('database_' . $database->getInternalId() . '_collection_' . $collection->getInternalId(), $key)) {
                throw new DatabaseException('Failed to delete index');
            }
            $dbForProject->deleteDocument('indexes', $index->getId());
        } catch (\Exception $e) {
            Console::error($e->getMessage());

            if ($e instanceof DatabaseException) {
                $index->setAttribute('error', $e->getMessage());
            }
            $dbForProject->updateDocument(
                'indexes',
                $index->getId(),
                $index->setAttribute('status', 'stuck')
            );
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'databaseId' => $database->getId(),
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('database_' . $database->getInternalId(), $collection->getId());
    }
}
