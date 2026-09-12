<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Licensed under the MIT License.
 ************************************************************************/

namespace Espo\Modules\EspoMcp\Tools\Mcp\Setup;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Role;
use Espo\Entities\User;
use Espo\Modules\EspoMcp\Tools\Mcp\Security\EntityAccessPolicy;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\ApiService;
use stdClass;

/**
 * First-run provisioning of a role-scoped EspoCRM API user for the
 * assistant.
 *
 * Admin-only. Reaching this state already requires admin credentials, so
 * the flow grants no new capability — it trades a permanent
 * full-privilege credential for a scoped, revocable one.
 */
class SetupService
{
    public const string SERVICE_USER_NAME = 'mcp-assistant';

    public function __construct(
        protected EntityManager $entityManager,
        protected Metadata $metadata,
        protected Config $config,
        protected ApiService $apiService,
        protected Log $log,
    ) {}

    /**
     * @throws Forbidden
     */
    public function assertAdmin(User $actor): void
    {
        if (!$actor->isAdmin()) {
            throw new Forbidden("MCP setup requires an administrator session.");
        }
    }

    public function isEnabled(): bool
    {
        return $this->config->get('mcp.setup.enabled') !== false;
    }

    public function findServiceUser(): ?User
    {
        /** @var ?User $user */
        $user = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where(['userName' => self::SERVICE_USER_NAME])
            ->findOne();

        return $user;
    }

    /**
     * Whether the assistant should offer to run setup.
     */
    public function isSetupRequired(User $actor): bool
    {
        return $this->isEnabled()
            && $actor->isAdmin()
            && $this->findServiceUser() === null;
    }

    /**
     * @throws Forbidden
     */
    public function status(User $actor): stdClass
    {
        $this->assertAdmin($actor);

        $existing = $this->findServiceUser();
        $policy = $this->policy();

        return (object) [
            'setupRequired' => $existing === null,
            'serviceUser' => $existing === null ? null : (object) [
                'id' => $existing->getId(),
                'userName' => $existing->get('userName'),
                'isActive' => (bool) $existing->get('isActive'),
                'roles' => $existing->get('rolesNames') ?? [],
            ],
            'presets' => AccessPreset::names(),
            'recordLevels' => [
                AccessPreset::LEVEL_OWN,
                AccessPreset::LEVEL_TEAM,
                AccessPreset::LEVEL_ALL,
            ],
            'overrideShapes' => [
                AccessPreset::SHAPE_NONE,
                AccessPreset::SHAPE_READ,
                AccessPreset::SHAPE_READWRITE,
                AccessPreset::SHAPE_FULL,
            ],
            'cloudflareAccessEnabled' => (bool) $this->config->get('mcp.cloudflareAccess.enabled'),
            'alwaysDenied' => $policy->deniedEntityTypes(),
            'readOnlyEntities' => $policy->deniedWriteEntityTypes(),
            'notes' => [
                'Export, mass-update and data-privacy permissions are disabled on every preset.',
                'Delete is disabled on every preset except full-operator.',
                'Call mcp_setup_preview to see the exact permissions before provisioning.',
            ],
        ];
    }

    /**
     * Dry run. Returns the exact matrix that would be written. Writes nothing.
     *
     * @param array<string, string> $overrides
     * @throws BadRequest
     * @throws Forbidden
     */
    public function preview(User $actor, string $preset, string $recordLevel, array $overrides): stdClass
    {
        $this->assertAdmin($actor);
        $this->validateArguments($preset, $recordLevel, $overrides);

        $data = $this->buildRoleData($preset, $recordLevel, $overrides);

        $enabled = [];
        $disabled = [];

        foreach ($data as $entityType => $actions) {
            if ($actions === false) {
                $disabled[] = $entityType;

                continue;
            }

            $enabled[$entityType] = $actions;
        }

        ksort($enabled);
        sort($disabled);

        return (object) [
            'preset' => $preset,
            'recordLevel' => $recordLevel,
            'overrides' => (object) $overrides,
            'roleName' => $this->roleName($preset),
            'serviceUserName' => self::SERVICE_USER_NAME,
            'permissions' => (object) AccessPreset::permissions($preset),
            'entityAccess' => (object) $enabled,
            'disabledEntities' => $disabled,
            'wouldReplaceExisting' => $this->findServiceUser() !== null,
            'writesNothing' => true,
            'nextStep' => 'Call mcp_setup_provision with the same arguments plus confirm: true.',
        ];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, array<string, string>|false>
     */
    protected function buildRoleData(string $preset, string $recordLevel, array $overrides): array
    {
        $scopes = $this->metadata->get('scopes', []);

        return (new RoleDataBuilder($this->policy()))
            ->build($preset, $recordLevel, $overrides, is_array($scopes) ? $scopes : []);
    }

    protected function policy(): EntityAccessPolicy
    {
        $security = $this->config->get('mcp.security');

        return EntityAccessPolicy::fromConfig(
            is_array($security) || is_object($security) ? $security : null
        );
    }

    protected function roleName(string $preset): string
    {
        return "MCP — $preset";
    }

    /**
     * @param array<string, string> $overrides
     * @throws BadRequest
     */
    protected function validateArguments(string $preset, string $recordLevel, array $overrides): void
    {
        if (!AccessPreset::exists($preset)) {
            $known = implode(', ', AccessPreset::names());

            throw new BadRequest("Unknown preset '$preset'. Known presets: $known.");
        }

        if (!AccessPreset::isValidLevel($recordLevel) || $recordLevel === AccessPreset::LEVEL_NO) {
            throw new BadRequest("recordLevel must be one of: own, team, all.");
        }

        foreach ($overrides as $entityType => $shape) {
            if (!is_string($shape) || !AccessPreset::isValidShape($shape)) {
                throw new BadRequest(
                    "Override for '$entityType' must be one of: none, read, readwrite, full."
                );
            }
        }
    }

    /**
     * Create the Role and the API user, then return the key once.
     *
     * @param array<string, string> $overrides
     * @throws BadRequest
     * @throws Forbidden
     */
    public function provision(
        User $actor,
        string $preset,
        string $recordLevel,
        array $overrides,
        bool $confirm,
        bool $replaceExisting,
    ): stdClass {
        $this->assertAdmin($actor);
        $this->validateArguments($preset, $recordLevel, $overrides);

        if ($confirm !== true) {
            throw new BadRequest(
                "Refusing to provision without confirm: true. " .
                "Call mcp_setup_preview first and show the permissions to the user."
            );
        }

        $existing = $this->findServiceUser();

        if ($existing !== null && !$replaceExisting) {
            throw new BadRequest(
                "An MCP service user already exists (userName: " . self::SERVICE_USER_NAME . "). " .
                "Pass replaceExisting: true to re-provision it with new permissions, " .
                "or revoke it in Administration → Users."
            );
        }

        $roleData = $this->buildRoleData($preset, $recordLevel, $overrides);

        $role = $this->entityManager->getNewEntity(Role::ENTITY_TYPE);

        $role->set('name', $this->roleName($preset));
        $role->set('data', (object) $roleData);
        $role->set('fieldData', (object) []);

        foreach (AccessPreset::permissions($preset) as $field => $value) {
            $role->set($field, $value);
        }

        $this->entityManager->saveEntity($role);

        $user = $existing ?? $this->entityManager->getNewEntity(User::ENTITY_TYPE);

        $user->set('userName', self::SERVICE_USER_NAME);
        $user->set('lastName', 'MCP Assistant');
        $user->set('type', User::TYPE_API);
        $user->set('authMethod', 'ApiKey');
        $user->set('isActive', true);
        $user->set('rolesIds', [$role->getId()]);

        $this->entityManager->saveEntity($user);

        // apiKey is readOnly in entityDefs; this service is the only path
        // that can mint one. It re-checks admin internally — a second gate.
        $provisioned = $this->apiService->generateNewApiKey($user->getId());

        $apiKey = $provisioned->get('apiKey');

        if (!is_string($apiKey) || $apiKey === '') {
            throw new Forbidden("MCP: failed to generate an API key for the service user.");
        }

        $this->log->info(
            'MCP setup: provisioned service user {userName} with preset {preset} ({level}) by {actor}.',
            [
                'userName' => self::SERVICE_USER_NAME,
                'preset' => $preset,
                'level' => $recordLevel,
                'actor' => $actor->get('userName'),
                'roleId' => $role->getId(),
                'userId' => $user->getId(),
                'replaced' => $existing !== null,
            ]
        );

        $siteUrl = rtrim((string) $this->config->get('siteUrl'), '/');

        return (object) [
            'provisioned' => true,
            'replacedExisting' => $existing !== null,
            'preset' => $preset,
            'recordLevel' => $recordLevel,
            'roleId' => $role->getId(),
            'roleName' => $this->roleName($preset),
            'userId' => $user->getId(),
            'userName' => self::SERVICE_USER_NAME,
            'apiKey' => $apiKey,
            'apiKeyIsOneTime' => true,
            'warning' =>
                'This API key is shown once and cannot be retrieved again. ' .
                'Store it in your MCP client config now. ' .
                'To revoke it, deactivate or delete the user "' . self::SERVICE_USER_NAME . '" ' .
                'in Administration → Users. To change its permissions, edit the role "' .
                $this->roleName($preset) . '" in Administration → Roles.',
            'clientConfig' => (object) [
                'mcpServers' => (object) [
                    'espocrm' => (object) [
                        'type' => 'http',
                        'url' => $siteUrl . '/api/v1/mcp',
                        'headers' => (object) [
                            'X-Api-Key' => $apiKey,
                        ],
                    ],
                ],
            ],
        ];
    }
}
