<?php

namespace Aladler\LaravelPennantSessionAndDbDriver;

use Aladler\LaravelPennantSessionAndDbDriver\Contracts\UserThatHasPreRegisterFeatures;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Session\SessionManager;
use Laravel\Pennant\Drivers\DatabaseDriver;
use Laravel\Pennant\Feature;

class SessionAndDatabaseDriver extends DatabaseDriver
{
    private const SESSION_KEY_NAME = 'features';

    protected SessionManager $session;

    public function __construct(Connection $db, Dispatcher $events, $config, $featureStateResolvers, SessionManager $session)
    {
        parent::__construct($db, $events, $config, $featureStateResolvers);
        $this->session = $session;
    }

    public function getAll($features): array
    {
        $results = [];
        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $scope) {
                $results[$feature][Feature::serializeScope($scope)] = $this->get($feature, $scope);
            }
        }

        return $results;
    }

    public function get($feature, $scope): mixed
    {
        if ($scope instanceof UserThatHasPreRegisterFeatures) {
            return $this->getValueForUserScope($feature, $scope);
        }

        if ($scope === null) {
            return $this->getValueForNullScope($feature, $scope);
        }

        return parent::get($feature, $scope);
    }

    public function set($feature, $scope, $value): void
    {
        if ($scope === null) {
            $this->setValueToSession($feature, $value);
        } else {
            parent::set($feature, $scope, $value);
            if (
                $scope instanceof UserThatHasPreRegisterFeatures
                &&
                (! app()->runningInConsole() || app()->runningUnitTests())
                && auth()->user() === $scope
            ) {
                // - sync to session, if user is logged in and the scope is the current user
                $this->setValueToSession($feature, $value);
            }
        }
    }

    public function setForAllScopes($feature, $value): void
    {
        $this->setValueToSession($feature, $value);
        parent::setForAllScopes($feature, $value);
    }

    public function delete($feature, $scope): void
    {
        if (
            $scope === null
            || (
                $scope instanceof UserThatHasPreRegisterFeatures
                &&
                (! app()->runningInConsole() || app()->runningUnitTests())
                && auth()->user() === $scope
            )
        ) {
            $this->removeValueFromSession($feature);
        }
        parent::delete($feature, $scope);
    }

    public function purge($features): void
    {
        if ($features === null) {
            $this->session->forget(self::SESSION_KEY_NAME);
        } else {
            foreach ($features as $feature) {
                $this->removeValueFromSession($feature);
            }
        }
        parent::purge($features);
    }

    private function removeValueFromSession(string $feature): void
    {
        $features = $this->getFeaturesFromSession();
        unset($features[$feature]);
        $this->session->put(self::SESSION_KEY_NAME, $features);
    }

    private function getValueFromSession(string $feature): mixed
    {
        $features = $this->getFeaturesFromSession();
        if (array_key_exists($feature, $features)) {
            return $features[$feature];
        }

        return null;
    }

    private function getFeaturesFromSession(): array
    {
        return $this->session->get(self::SESSION_KEY_NAME, []);
    }

    private function setValueToSession(string $feature, mixed $value): void
    {
        $features = $this->getFeaturesFromSession();
        $features[$feature] = $value;
        $this->session->put(self::SESSION_KEY_NAME, $features);
    }

    private function getValueForUserScope(string $feature, UserThatHasPreRegisterFeatures $user): mixed
    {
        //  - try to find in database, if found, done.
        if (($record = $this->retrieve($feature, $user)) !== null) {
            return json_decode($record->value, flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
        }

        //  - if not found, try to find in session (if user is logged in and the scope is the current user)
        $value = null;
        if (
            (! app()->runningInConsole() || app()->runningUnitTests())
            && auth()->user() === $user
        ) {
            $value = $this->getValueFromSession($feature);
        }

        //  - if not found, resolve value
        if (is_null($value)) {
            $value = $this->resolveValue($feature, $user);
        }

        if ($value === $this->unknownFeatureValue) {
            return false;
        }

        //  - put in database
        $this->insert($feature, $user, $value);

        //  - sync to session, if user is logged in and the scope is the current user
        if (
            (! app()->runningInConsole() || app()->runningUnitTests())
            && auth()->user() === $user
        ) {
            $this->setValueToSession($feature, $value);
        }

        return $value;
    }

    private function getValueForNullScope(string $feature, mixed $scope): mixed
    {
        $value = $this->getValueFromSession($feature);

        if (is_null($value)) {
            $value = $this->resolveValue($feature, $scope);
            if ($value === $this->unknownFeatureValue) {
                return false;
            }
            $this->setValueToSession($feature, $value);
        }

        return $value;
    }
}
