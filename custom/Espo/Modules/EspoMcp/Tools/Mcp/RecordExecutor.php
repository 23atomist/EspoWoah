<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 *
 * A Model Context Protocol endpoint that lives inside EspoCRM,
 * authenticates with the same identity used to sign into the CRM,
 * and exposes record operations with full per-user ACL enforcement.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Record\ServiceFactory as RecordServiceFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Executes record operations on behalf of an explicit user.
 * All CRUD is funneled through EspoCRM record services so that
 * ACL, validation, duplicates, hooks and stream behave exactly
 * as they do in the UI.
 */
class RecordExecutor
{
    private const int DEFAULT_MAX_SIZE = 50;
    private const int MAX_MAX_SIZE = 200;
    private const int MAX_WHERE_DEPTH = 5;

    private Acl $userAcl;

    public function __construct(
        private RecordServiceContainer $recordServiceContainer,
        private RecordServiceFactory $recordServiceFactory,
        private EntityManager $entityManager,
        private Metadata $metadata,
        private Config $config,
        private AclManager $aclManager,
        private User $user,
    ) {
        $this->userAcl = $this->aclManager->createUserAcl($this->user);
    }

    private function maxWhereDepth(): int
    {
        $value = $this->config->get('mcp.security.maxWhereDepth');

        return is_int($value) && $value > 0 ? $value : self::MAX_WHERE_DEPTH;
    }

    private function defaultMaxSize(): int
    {
        $value = $this->config->get('mcp.security.defaultMaxSize');

        return is_int($value) && $value > 0 ? $value : self::DEFAULT_MAX_SIZE;
    }

    private function maxMaxSize(): int
    {
        $value = $this->config->get('mcp.security.maxMaxSize');

        return is_int($value) && $value > 0 ? $value : self::MAX_MAX_SIZE;
    }

    /**
     * @return string[]
     */
    public function getEntityTypes(): array
    {
        $list = [];

        $scopes = $this->metadata->get('scopes', []);

        foreach (array_keys($scopes) as $scope) {
            $entity = $this->metadata->get(['scopes', $scope, 'entity']);
            $object = $this->metadata->get(['scopes', $scope, 'object']);

            if (!$entity || !$object) {
                continue;
            }

            if (!$this->userAcl->tryCheck($scope)) {
                continue;
            }

            $list[] = $scope;
        }

        sort($list);

        return $list;
    }

    public function describe(string $entityType): stdClass
    {
        $this->checkEntityType($entityType);

        $defs = (object) [];

        $defs->entityType = $entityType;

        $label = $this->metadata->get(['scopes', $entityType, 'label']);
        $defs->label = $label;

        $fields = $this->metadata->get(['entityDefs', $entityType, 'fields'], []);

        $fieldDefs = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            $type = $field['type'] ?? null;

            if ($type === null) {
                continue;
            }

            $item = [
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'readOnly' => (bool) ($field['readOnly'] ?? false),
            ];

            if ($type === 'enum' || $type === 'multiEnum' || $type === 'checklist') {
                $options = $this->metadata->get(['entityDefs', $entityType, 'fields', $name, 'options']);

                if (is_array($options) && count($options) < 30) {
                    $item['options'] = $options;
                }
            }

            if (isset($field['default'])) {
                $item['default'] = $field['default'];
            }

            $label = $this->metadata->get(['entityDefs', $entityType, 'fields', $name, 'label']);

            $fieldDefs[$name] = $item;
        }

        $defs->fields = $fieldDefs;

        $links = $this->metadata->get(['entityDefs', $entityType, 'links'], []);
        $linkDefs = [];

        foreach ($links as $name => $link) {
            if (!is_array($link)) {
                continue;
            }

            $type = $link['type'] ?? null;

            if ($type === null) {
                continue;
            }

            $entry = ['type' => $type];

            if (isset($link['entity'])) {
                $entry['entity'] = $link['entity'];
            }

            $linkDefs[$name] = $entry;
        }

        $defs->links = $linkDefs;

        $collectionAttrs = $this->metadata->get(['entityDefs', $entityType, 'collection']);

        if (is_array($collectionAttrs)) {
            if (isset($collectionAttrs['orderBy'])) {
                $order = is_array($collectionAttrs['orderBy']) ?
                    array_key_first($collectionAttrs['orderBy']) :
                    null;

                if ($order) {
                    $defs->defaultOrderBy = $order;
                }
            }

            if (isset($collectionAttrs['textFilterFields'])) {
                $defs->textFilterFields = $collectionAttrs['textFilterFields'];
            }
        }

        return $defs;
    }

    public function read(string $entityType, string $id): stdClass
    {
        $service = $this->getServiceForUser($entityType);

        $result = $service->read($id);

        return $result->getValueMap();
    }

    public function create(string $entityType, stdClass $data): stdClass
    {
        $service = $this->getServiceForUser($entityType);

        $result = $service->create($data);

        return $result->getValueMap();
    }

    public function update(string $entityType, string $id, stdClass $data): stdClass
    {
        $service = $this->getServiceForUser($entityType);

        $result = $service->update($id, $data);

        return $result->getValueMap();
    }

    public function delete(string $entityType, string $id): void
    {
        $service = $this->getServiceForUser($entityType);

        $service->delete($id);
    }

    public function search(
        string $entityType,
        ?string $textFilter,
        ?array $where,
        ?array $select,
        ?string $orderBy,
        ?string $order,
        ?int $offset,
        ?int $maxSize
    ): stdClass {

        $service = $this->getServiceForUser($entityType);

        if ($where !== null) {
            $this->validateWhere($where, 0);
        }

        $params = [];

        if ($textFilter !== null && $textFilter !== '') {
            $params['textFilter'] = $textFilter;
        }

        if ($where !== null) {
            $params['where'] = $where;
        }

        if ($select !== null) {
            $params['select'] = $select;
        }

        if ($orderBy !== null) {
            $params['orderBy'] = $orderBy;
        }

        if ($order !== null) {
            $params['order'] = strtoupper($order) === 'DESC' ? 'desc' : $order;
        }

        $params['offset'] = $offset ?? 0;
        $params['maxSize'] = min($maxSize ?? $this->defaultMaxSize(), $this->maxMaxSize());

        $searchParams = SearchParams::fromRaw($params);

        $collection = $service->find($searchParams);

        return (object) [
            'total' => $collection->getTotal(),
            'list' => $collection->getValueMapList(),
        ];
    }

    /**
     * @return stdClass[]
     */
    public function readRelated(
        string $entityType,
        string $id,
        string $link,
        ?string $textFilter,
        ?array $where,
        ?int $offset,
        ?int $maxSize
    ): array {

        $service = $this->getServiceForUser($entityType);

        if ($where !== null) {
            $this->validateWhere($where, 0);
        }

        $params = [
            'offset' => $offset ?? 0,
            'maxSize' => min($maxSize ?? $this->defaultMaxSize(), $this->maxMaxSize()),
        ];

        if ($textFilter !== null && $textFilter !== '') {
            $params['textFilter'] = $textFilter;
        }

        if ($where !== null) {
            $params['where'] = $where;
        }

        $searchParams = SearchParams::fromRaw($params);

        $collection = $service->findLinked($id, $link, $searchParams);

        return $collection->getValueMapList();
    }

    public function link(string $entityType, string $id, string $link, string $foreignId): void
    {
        $service = $this->getServiceForUser($entityType);

        $service->link($id, $link, $foreignId);
    }

    public function unlink(string $entityType, string $id, string $link, string $foreignId): void
    {
        $service = $this->getServiceForUser($entityType);

        $service->unlink($id, $link, $foreignId);
    }

    public function convertLead(stdClass $args): stdClass
    {
        $leadId = $args->leadId ?? null;

        if (!$leadId || !is_string($leadId)) {
            throw new BadRequest("Missing 'leadId'.");
        }

        $this->checkEntityType('Lead');

        $leadService = $this->recordServiceContainer->get('Lead');
        $lead = $leadService->getEntity($leadId);

        if (!$lead) {
            throw new NotFound("Lead not found.");
        }

        $newRecords = [];

        $createContact = $args->createContact ?? false;
        $createAccount = $args->createAccount ?? false;
        $createOpportunity = $args->createOpportunity ?? false;

        if ($createContact) {
            $contact = $this->entityManager->getNewEntity('Contact');

            $contact->set('firstName', $lead->get('firstName'));
            $contact->set('lastName', $lead->get('lastName'));

            if (!$lead->get('lastName')) {
                $contact->set('lastName', $lead->get('accountName') ?: 'Unknown');
            }

            if ($lead->get('accountId')) {
                $contact->set('accountId', $lead->get('accountId'));
            }

            $contact->set('title', $lead->get('title'));
            $contact->set('emailAddress', $lead->get('emailAddress'));
            $contact->set('phoneNumber', $lead->get('phoneNumber'));
            $contact->set('description', $lead->get('description'));

            $assignedUserId = $lead->get('assignedUserId');

            if ($assignedUserId) {
                $contact->set('assignedUserId', $assignedUserId);
            }

            $this->entityManager->saveEntity($contact);

            $newRecords['contact'] = $contact->getValueMap();
        }

        if ($createAccount && $lead->get('accountName')) {
            $account = $this->entityManager->getNewEntity('Account');

            $account->set('name', $lead->get('accountName'));
            $account->set('industry', $lead->get('industry'));
            $account->set('website', $lead->get('website'));
            $account->set('description', $lead->get('description'));

            $assignedUserId = $lead->get('assignedUserId');

            if ($assignedUserId) {
                $account->set('assignedUserId', $assignedUserId);
            }

            $this->entityManager->saveEntity($account);

            $newRecords['account'] = $account->getValueMap();

            if (isset($contact)) {
                $this->entityManager
                    ->getRDBRepository('Contact')
                    ->getRelation($contact, 'accounts')
                    ->relate($account);
            }
        }

        if ($createOpportunity) {
            $opportunity = $this->entityManager->getNewEntity('Opportunity');

            $name = $args->opportunityName ?? ($lead->get('accountName') ?: $lead->get('source'));
            $opportunity->set('name', $name ?: 'Opportunity');
            $opportunity->set('amount', $args->opportunityAmount ?? null);
            $opportunity->set('amountCurrency', $lead->get('amountCurrency') ?? 'USD');
            $opportunity->set('closeDate', $args->closeDate ?? null);
            $opportunity->set('stage', $args->stage ?? 'Prospecting');

            if (isset($account)) {
                $opportunity->set('accountId', $account->getId());
            }

            if (isset($contact)) {
                $opportunity->set('contactId', $contact->getId());
            }

            $assignedUserId = $lead->get('assignedUserId');

            if ($assignedUserId) {
                $opportunity->set('assignedUserId', $assignedUserId);
            }

            $this->entityManager->saveEntity($opportunity);

            $newRecords['opportunity'] = $opportunity->getValueMap();
        }

        if (!$newRecords) {
            throw new BadRequest("At least one of createContact, createAccount, createOpportunity is required.");
        }

        $lead->set('status', 'Converted');
        $lead->set('convertedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($lead);

        $payload = (object) [
            'leadId' => $leadId,
            'status' => 'Converted',
            'created' => $newRecords,
        ];

        return $payload;
    }

    public function addStreamNote(string $entityType, string $id, string $post, bool $isInternal = false): stdClass
    {
        $this->checkEntityType($entityType);

        $note = $this->entityManager->getNewEntity('Note');

        $note->set([
            'type' => 'Post',
            'post' => $post,
            'parentType' => $entityType,
            'parentId' => $id,
            'isInternal' => $isInternal,
        ]);

        $this->entityManager->saveEntity($note, ['skipStream' => true]);

        return $note->getValueMap();
    }

    public function getStreamNotes(
        string $entityType,
        string $id,
        ?int $offset,
        ?int $maxSize
    ): stdClass {

        $this->checkEntityType($entityType);

        $limit = min($maxSize ?? $this->defaultMaxSize(), $this->maxMaxSize());

        $selectParams = [
            'where' => [
                [
                    'type' => 'and',
                    'value' => [
                        ['type' => 'equals', 'attribute' => 'parentId', 'value' => $id],
                        ['type' => 'equals', 'attribute' => 'parentType', 'value' => $entityType],
                        [
                            'type' => 'in',
                            'attribute' => 'type',
                            'value' => ['Post', 'Update', 'Create', 'Status', 'EmailReceived', 'EmailSent'],
                        ],
                    ],
                ],
            ],
            'orderBy' => 'number',
            'order' => 'desc',
            'offset' => $offset ?? 0,
            'maxSize' => $limit,
        ];

        $noteService = $this->recordServiceContainer->get('Note');

        $searchParams = SearchParams::fromRaw($selectParams);

        $result = $noteService->find($searchParams);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getValueMapList(),
        ];
    }

    /**
     * @param array<string, mixed> $where
     */
    private function validateWhere(array $where, int $depth): void
    {
        if ($depth >= $this->maxWhereDepth()) {
            throw new BadRequest("Where filter nesting too deep.");
        }

        foreach ($where as $item) {
            if (!is_array($item)) {
                throw new BadRequest("Where filter items must be objects.");
            }

            $type = $item['type'] ?? null;

            if (!$type || !is_string($type)) {
                throw new BadRequest("Where item is missing 'type'.");
            }

            $allowedTypes = [
                'and', 'or', 'not', 'equals', 'notEquals', 'in', 'notIn',
                'like', 'notLike', 'startsWith', 'endsWith', 'contains', 'notContains',
                'greaterThan', 'lessThan', 'greaterThanOrEquals', 'lessThanOrEquals',
                'after', 'before', 'between', 'today', 'past', 'future',
                'lastSevenDays', 'lastXDays', 'nextXDays', 'olderThanXDays', 'afterXDays',
                'currentMonth', 'nextMonth', 'lastMonth', 'currentQuarter', 'lastQuarter',
                'currentYear', 'lastYear', 'currentFiscalYear', 'lastFiscalYear',
                'currentFiscalQuarter', 'lastFiscalQuarter',
                'isNull', 'isNotNull', 'isTrue', 'isFalse',
                'arrayAnyOf', 'arrayNoneOf', 'arrayAllOf', 'arrayIsEmpty', 'arrayIsNotEmpty',
                'linkedWith', 'notLinkedWith', 'linkedWithAll', 'isLinked', 'isNotLinked',
            ];

            if (!in_array($type, $allowedTypes)) {
                throw new BadRequest("Unsupported where type '$type'.");
            }

            $attribute = $item['attribute'] ?? $item['field'] ?? null;

            if ($attribute !== null && !is_string($attribute)) {
                throw new BadRequest("Where 'attribute' must be a string.");
            }

            $value = $item['value'] ?? null;

            if (
                in_array($type, ['and', 'or']) &&
                is_array($value)
            ) {
                $this->validateWhere($value, $depth + 1);
            }
        }
    }

    private function checkEntityType(string $entityType): void
    {
        $isEntity = (bool) $this->metadata->get(['scopes', $entityType, 'entity']);
        $isObject = (bool) $this->metadata->get(['scopes', $entityType, 'object']);

        if (!$isEntity || !$isObject) {
            throw new NotFound("Unknown or non-object entity type '$entityType'.");
        }

        if (!$this->userAcl->tryCheck($entityType)) {
            throw new Forbidden("No access to '$entityType'.");
        }
    }

    private function getServiceForUser(string $entityType): RecordService
    {
        $this->checkEntityType($entityType);

        return $this->recordServiceFactory->createForUser($entityType, $this->user);
    }
}