<?php

namespace Emergence\Connectors;

use Emergence\People\IPerson;

trait IdentityConsumerTrait
{
    public static $requiredAccountLevel = 'User';
    public static $userIsPermitted;
    public static $beforeAuthenticate;

    public static function userIsPermitted(IPerson $Person)
    {
        if (static::$requiredAccountLevel && !$Person->hasAccountLevel(static::$requiredAccountLevel)) {
            return false;
        }

        if (is_callable(static::$userIsPermitted)) {
            return call_user_func(static::$userIsPermitted, $Person);
        }

        return true;
    }

    public static function beforeAuthenticate(IPerson $Person)
    {
        if (is_callable(static::$beforeAuthenticate)) {
            if (false === call_user_func(static::$beforeAuthenticate, $Person)) {
                return false;
            }
        }

        return true;
    }

    public static function getSAMLNameId(IPerson $Person)
    {
        if (!$Person->Username) {
            throw new \Exception('Person does not have a username');
        }

        return [
            'Format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'Value' => $Person->Username.'@'.SAML2::$issuer
        ];
    }

    public static function handleRequest($action = null)
    {
        switch ($action ?: $action = static::shiftPath()) {
            case 'login':
                $GLOBALS['Session']->requireAuthentication();

                if (!static::userIsPermitted($GLOBALS['Session']->Person)) {
                    return static::throwError('Your account is not permitted access to the requested service, please contact your systems administrator if you believe this is in error');
                }

                // execute identity consumer classes' beforeAuthenticate method
                if (false === static::beforeAuthenticate($GLOBALS['Session']->Person)) {
                    return static::throwError('Access to the requested service is unavailable for your account at this time, please try again later or contact your systems administrator');
                }

                return static::handleLoginRequest($GLOBALS['Session']->Person);
            default:
                return parent::handleRequest($action);
        }
    }

    public static function handleLoginRequest(IPerson $Person)
    {
        return static::throwInvalidRequestError('Login method not implemented');
    }
}