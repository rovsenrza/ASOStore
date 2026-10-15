<?php

use App\Models\AppleTeam;
use App\Services\Apple\AppGroupProvisioner;
use App\Services\Apple\SecretStore;

// Which stored developer-portal login serves which Apple team (App Groups need an account of the team itself).

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/portal-creds-'.bin2hex(random_bytes(4));
    mkdir($this->storage.'/app/private', 0700, true);
    app()->useStoragePath($this->storage);
    $this->secrets = app(SecretStore::class);
    $this->portal = new AppGroupProvisioner($this->secrets);
});

afterEach(function () {
    foreach (glob($this->storage.'/app/private/secrets/apple/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->storage.'/app/private/secrets/apple');
    @rmdir($this->storage.'/app/private/secrets');
    @rmdir($this->storage.'/app/private');
    @rmdir($this->storage.'/app');
    @rmdir($this->storage);
});

function teamModel(string $id, bool $primary = false): AppleTeam
{
    $team = new AppleTeam;
    $team->apple_team_id = $id;
    $team->is_primary = $primary;

    return $team;
}

function storeLogin(object $test, string $path, array $login): void
{
    $test->secrets->storeEncrypted($path, json_encode($login));
}

it('serves no team while nothing is stored', function () {
    expect($this->portal->isConfigured())->toBeFalse()
        ->and($this->portal->isConfigured(teamModel('AAAAAAAAAA', true)))->toBeFalse()
        ->and($this->portal->credentialsPath(teamModel('AAAAAAAAAA', true)))->toBeNull();
});

it('lets a login recorded before teams were tracked serve the primary team only', function () {
    storeLogin($this, AppGroupProvisioner::CREDENTIALS, ['user' => 'owner@example.com', 'password' => 'x']);

    expect($this->portal->isConfigured())->toBeTrue()
        ->and($this->portal->credentialsPath(teamModel('AAAAAAAAAA', true)))->toBe(AppGroupProvisioner::CREDENTIALS)
        ->and($this->portal->credentialsPath(teamModel('BBBBBBBBBB')))->toBeNull();
});

it('lets the shared login serve exactly the teams it recorded', function () {
    storeLogin($this, AppGroupProvisioner::CREDENTIALS, ['user' => 'owner@example.com', 'password' => 'x', 'teams' => ['AAAAAAAAAA', 'CCCCCCCCCC']]);

    expect($this->portal->credentialsPath(teamModel('CCCCCCCCCC')))->toBe(AppGroupProvisioner::CREDENTIALS)
        ->and($this->portal->credentialsPath(teamModel('BBBBBBBBBB')))->toBeNull();
});

it('prefers the login of the team itself over the shared one', function () {
    storeLogin($this, AppGroupProvisioner::CREDENTIALS, ['user' => 'owner@example.com', 'password' => 'x', 'teams' => ['BBBBBBBBBB']]);
    storeLogin($this, AppGroupProvisioner::credentialsFor('bbbbbbbbbb'), ['user' => 'bb@example.com', 'password' => 'y', 'teams' => ['BBBBBBBBBB']]);

    expect($this->portal->credentialsPath(teamModel('BBBBBBBBBB')))->toBe('secrets/apple/portal-login-BBBBBBBBBB.json.enc')
        ->and($this->portal->isConfigured(teamModel('BBBBBBBBBB')))->toBeTrue()
        ->and($this->portal->isConfigured(teamModel('DDDDDDDDDD')))->toBeFalse();
});

it('does not ask the portal for a team that no stored login can see', function () {
    // ensure() returns false before any portal call when the team has no login.
    expect($this->portal->ensure(teamModel('BBBBBBBBBB'), 'group.com.example', 'com.example', 'Example'))->toBeFalse()
        ->and($this->portal->run(['login'], 5, team: teamModel('BBBBBBBBBB')))
        ->toMatchArray(['ok' => false, 'code' => 'NOT_CONFIGURED']);
});
