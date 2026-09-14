<?php
/************************************************************************
 * This file is part of EspoMcp — an EspoCRM module.
 *
 * EspoMcp – MCP server as an EspoCRM extension.
 * Copyright (C) 2026 Thomas Gallaway
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
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

    /**
     * Prefix of every Role this service creates. Used to recognise a role
     * this module owns when a re-provision supersedes it.
     */
    public const string MANAGED_ROLE_PREFIX = 'MCP — ';

    /** Appended to a managed role that a re-provision has replaced. */
    public const string SUPERSEDED_SUFFIX = ' (superseded)';

    /**
     * `team` is a legitimate level, but the service user is created with no
     * teams, so it grants nothing until an administrator assigns some. Said
     * out loud rather than left for the operator to discover.
     */
    public const string TEAM_LEVEL_WARNING =
        'recordLevel "team" was requested. The MCP service user is provisioned with no teams, ' .
        'so team-level access resolves to no records until an administrator assigns teams to ' .
        'the user "' . self::SERVICE_USER_NAME . '" in Administration → Users.';

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

    /**
     * Every public entry point calls this, so `mcp.setup.enabled: false`
     * actually disables the flow rather than only hiding the tools from
     * `tools/list`. An assistant that calls `mcp_setup_provision` by name
     * gets the same refusal.
     *
     * Always called after `assertAdmin()`, so a non-admin caller learns
     * nothing about the configuration.
     *
     * @throws Forbidden
     */
    public function assertEnabled(): void
    {
        if ($this->isEnabled()) {
            return;
        }

        throw new Forbidden(
            "MCP setup is disabled (mcp.setup.enabled). " .
            "An administrator must re-enable it in the EspoCRM config before a service " .
            "user can be provisioned."
        );
    }

    /**
     * Whether a role name belongs to this module.
     */
    public static function isManagedRoleName(string $name): bool
    {
        return str_starts_with($name, self::MANAGED_ROLE_PREFIX);
    }

    /**
     * Idempotent: a role already marked superseded keeps its name rather
     * than collecting a second suffix on every re-provision.
     */
    public static function supersededRoleName(string $name): string
    {
        if (str_ends_with($name, self::SUPERSEDED_SUFFIX)) {
            return $name;
        }

        return $name . self::SUPERSEDED_SUFFIX;
    }

    /**
     * Warnings that describe the access actually granted, as opposed to the
     * access the matrix appears to describe.
     *
     * @return string[]
     */
    public static function levelWarnings(string $recordLevel): array
    {
        if ($recordLevel === AccessPreset::LEVEL_TEAM) {
            return [self::TEAM_LEVEL_WARNING];
        }

        return [];
    }

    /**
     * The one-time-key warning. Names the role by id as well as by name,
     * because earlier provisioning runs can leave superseded roles with a
     * similar name, and calls out a reactivation rather than performing one
     * silently.
     */
    public static function provisionWarning(
        string $roleName,
        string $roleId,
        bool $reactivated
    ): string {
        $text =
            'This API key is shown once and cannot be retrieved again. ' .
            'Store it in your MCP client config now. ' .
            'To revoke it, deactivate or delete the user "' . self::SERVICE_USER_NAME . '" ' .
            'in Administration → Users. To change its permissions, edit the role "' .
            $roleName . '" with id ' . $roleId . ' in Administration → Roles — identify it ' .
            'by id, because an earlier run may have left a superseded role with a similar name.';

        if (!$reactivated) {
            return $text;
        }

        return
            'The service user "' . self::SERVICE_USER_NAME . '" was deactivated, which means ' .
            'its access had been revoked. This run has REACTIVATED that user and issued a new ' .
            'API key, so the revocation no longer holds. If the deactivation was deliberate, ' .
            'deactivate the user again now. ' . $text;
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
        $this->assertEnabled();

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
        $this->assertEnabled();
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
            'warnings' => self::levelWarnings($recordLevel),
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
        return self::MANAGED_ROLE_PREFIX . $preset;
    }

    /**
     * Rename — never delete — any role this module owns that the service
     * user currently holds, so the live role stays identifiable after a
     * re-provision has attached a new one with the same name.
     *
     * @return string[] The names after renaming.
     */
    protected function supersedeManagedRoles(User $existing): array
    {
        $renamed = [];

        $roles = $this->entityManager
            ->getRelation($existing, User::LINK_ROLES)
            ->find();

        foreach ($roles as $role) {
            $name = $role->get('name');

            if (!is_string($name) || !self::isManagedRoleName($name)) {
                continue;
            }

            $newName = self::supersededRoleName($name);

            if ($newName === $name) {
                continue;
            }

            $role->set('name', $newName);

            $this->entityManager->saveEntity($role);

            $renamed[] = $newName;
        }

        return $renamed;
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
        $this->assertEnabled();
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

        // Read before anything is overwritten: re-provisioning sets
        // isActive => true, and an administrator who deactivated this user
        // did so to revoke access. That must be reported, not performed
        // silently.
        $wasInactive = $existing !== null && !((bool) $existing->get('isActive'));

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

        // Before the new role is attached, so the role the user held is not
        // left sharing a name with the live one.
        $supersededRoles = $existing === null ? [] : $this->supersedeManagedRoles($existing);

        $user->set('userName', self::SERVICE_USER_NAME);
        $user->set('lastName', 'MCP Assistant');
        $user->set('type', User::TYPE_API);
        $user->set('authMethod', 'ApiKey');
        $user->set('isActive', true);
        $user->set('rolesIds', [$role->getId()]);

        $this->entityManager->saveEntity($user);

        // apiKey is readOnly in entityDefs; ApiService is the only supported
        // way to mint one. Its own admin check is not an independent gate —
        // under Cloudflare Access the container user can be the system user,
        // for which isAdmin() is true. assertAdmin() above is the real gate.
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
                'reactivated' => $wasInactive,
                'supersededRoles' => $supersededRoles,
            ]
        );

        $siteUrl = rtrim((string) $this->config->get('siteUrl'), '/');

        return (object) [
            'provisioned' => true,
            'replacedExisting' => $existing !== null,
            'reactivated' => $wasInactive,
            'supersededRoles' => $supersededRoles,
            'preset' => $preset,
            'recordLevel' => $recordLevel,
            'roleId' => $role->getId(),
            'roleName' => $this->roleName($preset),
            'userId' => $user->getId(),
            'userName' => self::SERVICE_USER_NAME,
            'apiKey' => $apiKey,
            'apiKeyIsOneTime' => true,
            'warning' => self::provisionWarning(
                $this->roleName($preset),
                (string) $role->getId(),
                $wasInactive
            ),
            'warnings' => self::levelWarnings($recordLevel),
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
